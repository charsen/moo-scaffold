<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Designer;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

/**
 * plan-49:把同一张表的"1 个 create + N 个 update"migration 文件合并成单一 create。
 *
 *  - 保留 create 文件名(migrations 表 entry 仍匹配 → 不重跑)
 *  - 重渲 create 内容 = FreshStorageGenerator 从当前 yaml 一次性渲出来
 *  - 删 N 个 update 文件
 *  - 可选:DELETE migrations 表中 update 文件对应的孤儿 entry
 *
 *  三个兜底(plan-49 §3.3):
 *   A) rename/drop 中间态 → CompactBlockedException
 *   B) schema drift(真 DB ↔ 重渲 create)→ warnings 列表(GUI 决定 abort / accept)
 *   C) git push 检测 → 默认拒绝;但 push≠部署,GUI 在已 push 时让人工勾「未在 production/shared
 *      服务器部署」确认框 → 传 force=true 绕过(团队协作 push 是常态,真正不可逆的是服务器已跑过库)
 */
class MigrationCompacter
{
    public function __construct(
        private readonly SchemaLoader $loader,
        private readonly MigrationWriter $writer,
        private readonly GitInspector $git,
        private readonly Filesystem $fs,
        private readonly string $cwd,
    ) {}

    /**
     * Dry-run:扫文件 + 重渲 create + drift 检测 + git push 检测,**不动磁盘**。
     *
     * @return array{
     *   create_file: string,
     *   update_files: array<int, string>,
     *   preview_php: string,
     *   schema_drift: array<int, array{type:string, detail:string}>,
     *   git_pushed: array<int, string>,
     * }
     *
     * @throws CompactBlockedException
     */
    public function preview(string $schema, string $table): array
    {
        $files      = $this->findTableMigrationFiles($table, $schema);
        $previewPhp = $this->regenerateCreateContent($schema, $table);
        $drift      = $this->detectSchemaDrift($schema, $table);
        // create 文件也要查:execute 会重写它(filename 不变),它通常最早被 push —— 漏查则
        // 静默改写已 push 的 create,别人 DB 与文件分叉(2026-06-09 修)。
        $pushed = $this->detectGitPushed(array_merge([$files['create']], $files['updates']), $schema);

        return [
            'create_file'  => basename($files['create']),
            'update_files' => array_map('basename', $files['updates']),
            'preview_php'  => $previewPhp,
            'schema_drift' => $drift,
            'git_pushed'   => $pushed,
        ];
    }

    /**
     * Execute:真删 update 文件 + 真改写 create 文件(+ 可选清 migrations 表)。
     *
     * @param array{clean_db?: bool, force?: bool} $opts
     *                                                   - clean_db:DELETE FROM migrations WHERE migration IN (update basenames)
     *                                                   - force:绕开 git_pushed 兜底(GUI 在已 push 时经人工「未部署」确认勾选传入)
     *
     * @return array{
     *   rewritten: string,
     *   deleted: array<int, string>,
     *   db_cleaned: int,
     *   warnings: array<int, string>,
     * }
     *
     * @throws CompactBlockedException
     */
    public function execute(string $schema, string $table, array $opts = []): array
    {
        $cleanDb = ! empty($opts['clean_db']);
        $force   = ! empty($opts['force']);

        // 写权硬线(plan-53):compact 改写/删除包内 migration,vcs 拷贝包拒绝
        $this->loader->assertOriginWritable($schema);
        $files = $this->findTableMigrationFiles($table, $schema);
        if (! $force) {
            // create 一并查:下方 fs->put 会重写它(filename 不变),漏查则静默改写已 push 的 create。
            $pushed = $this->detectGitPushed(array_merge([$files['create']], $files['updates']), $schema);
            if ($pushed !== []) {
                throw new CompactBlockedException(
                    'update 文件已 push 到 origin：' . implode(', ', array_map('basename', $pushed)) . ' — 拒绝合并（防多 dev state 不一致），如确认请加 force=true',
                    CompactBlockedException::REASON_GIT_PUSHED,
                );
            }
        }

        $previewPhp = $this->regenerateCreateContent($schema, $table);
        $warnings   = [];

        // 1) 原子改写 create(filename 不变 → migrations 表 entry 仍匹配)。
        //    tmp + rename:写一半进程死掉不会留下半个 create;写失败立即中止,
        //    此时一个 update 都还没删,磁盘状态与合并前完全一致(2026-06-11 修)。
        $tmp = $files['create'] . '.compact-tmp';
        if ($this->fs->put($tmp, $previewPhp) === false || ! @rename($tmp, $files['create'])) {
            @unlink($tmp);
            throw new \RuntimeException('create 文件改写失败（磁盘/权限），已中止 —— 未删除任何 update 文件');
        }

        // 2) 删 N 个 update 文件 —— 删除结果必查:失败的不算已删、不参与 clean_db。
        //    原先忽略返回值,删失败照样报「已删除」,勾 clean_db 还会误删它的 migrations
        //    表 entry → 下次 migrate 重跑该 update → duplicate column 卡死(2026-06-11 修)。
        $deletedBasenames = [];
        $deletedFiles     = [];
        foreach ($files['updates'] as $abs) {
            if ($this->fs->delete($abs)) {
                $deletedFiles[]     = basename($abs);
                $deletedBasenames[] = basename($abs, '.php');
            } else {
                $warnings[] = '删除失败：' . basename($abs) . '（权限/占用？）— 文件仍在，其 migrations 表记录未动；处理后重跑合并即可（create 改写幂等）';
            }
        }

        // 3) 可选 DB 清理(孤儿 migration entries)
        $dbCleaned = 0;
        if ($cleanDb && $deletedBasenames !== []) {
            $dbCleaned = $this->cleanMigrationsTable($deletedBasenames, $warnings);
        }

        return [
            'rewritten'  => basename($files['create']),
            'deleted'    => $deletedFiles,     // 只报真删掉的(原先把删失败的也算上 → 假成功)
            'db_cleaned' => $dbCleaned,
            'warnings'   => $warnings,
        ];
    }

    // ---------------------------------------------------------------
    // 文件扫描 + rename/drop 兜底
    // ---------------------------------------------------------------

    /**
     * @return array{create: string, updates: array<int, string>}
     *
     * @throws CompactBlockedException
     */
    public function findTableMigrationFiles(string $table, string $schema): array
    {
        $dir = $this->migrationPath($schema);
        $all = glob($dir . '/*.php') ?: [];

        $creates  = [];
        $updates  = [];
        $blockers = [];

        $tableEsc = preg_quote($table, '/');
        $createRe = '/^\d{4}_\d{2}_\d{2}_\d+_create_' . $tableEsc . '_table\.php$/';
        $updateRe = '/^\d{4}_\d{2}_\d+_\d+_update_' . $tableEsc . '_table\.php$/';
        $dropRe   = '/^\d{4}_\d{2}_\d+_\d+_drop_' . $tableEsc . '_table\.php$/';
        // 收紧到 rename_{本表}_to_* / rename_*_to_{本表} 两形:原来的 rename_{本表}_.* 会把
        // rename_user_logs_to_audit_logs 误判成 user 表的 blocker(误拦,2026-06-11 修)
        // 收紧到 rename_{本表}_to_* / rename_*_to_{本表} 两形:原来的 rename_{本表}_.* 会把
        // rename_user_logs_to_audit_logs 误判成 user 表的 blocker(误拦,2026-06-11 修)
        $renameRe = '/^\d{4}_\d{2}_\d+_\d+_rename_(?:' . $tableEsc . '_to_.+|.+_to_' . $tableEsc . ')_table\.php$/';

        foreach ($all as $abs) {
            $base = basename($abs);
            if (preg_match($createRe, $base)) {
                $creates[] = $abs;

                continue;
            }
            if (preg_match($updateRe, $base)) {
                $updates[] = $abs;

                continue;
            }
            if (preg_match($dropRe, $base)) {
                $blockers[] = ['type' => 'drop', 'file' => $base];

                continue;
            }
            if (preg_match($renameRe, $base)) {
                $blockers[] = ['type' => 'rename', 'file' => $base];

                continue;
            }
        }

        // 兜底 A:rename/drop 中间态 → 拒绝
        foreach ($blockers as $b) {
            $reason = $b['type'] === 'rename'
                ? CompactBlockedException::REASON_RENAME
                : CompactBlockedException::REASON_DROP;
            $msg = $b['type'] === 'rename'
                ? "暂不支持表名变更后的合并历史（{$b['file']}），请手动处理"
                : "暂不支持表被 drop 后的合并历史（{$b['file']}），请手动处理";
            throw new CompactBlockedException($msg, $reason);
        }

        if ($creates === []) {
            throw new CompactBlockedException(
                "找不到 {$table} 的 create migration 文件，无法合并",
                CompactBlockedException::REASON_NO_CREATE,
            );
        }
        if (count($creates) > 1) {
            throw new CompactBlockedException(
                "{$table} 有 " . count($creates) . ' 个 create 文件：' . implode(', ', array_map('basename', $creates)) . ' — 请手动审计',
                CompactBlockedException::REASON_MULTI_CREATE,
            );
        }

        sort($updates);     // ASCII order = chronological(filename 起头时间戳)

        return ['create' => $creates[0], 'updates' => $updates];
    }

    // ---------------------------------------------------------------
    // 重渲 create 内容(yaml = source of truth)
    // ---------------------------------------------------------------

    public function regenerateCreateContent(string $schema, string $table): string
    {
        // SchemaDiffService::createdTableDiff 同款结构 — render 看 status='created' 走 renderCreate
        $normalized = $this->loader->loadNormalized($schema);
        if (! isset($normalized['tables'][$table])) {
            throw new CompactBlockedException(
                "yaml 里找不到 {$schema}.{$table}，无法重渲 create",
                CompactBlockedException::REASON_NO_CREATE,
            );
        }
        $current = $normalized['tables'][$table];

        $fakeDiff = [
            'schema' => $schema,
            'tables' => [
                $table => [
                    'status'              => 'created',
                    'baseline_definition' => null,
                    'current_definition'  => $current,
                    'field_changes'       => [],   // renderCreate 不读这个,只读 current_definition
                    'index_changes'       => [],
                    'warnings'            => [],
                ],
            ],
        ];

        $rendered = $this->writer->render($fakeDiff);
        if (! isset($rendered[$table]['php_source'])) {
            throw new \RuntimeException("MigrationWriter::render 没返 {$table} 的 php_source");
        }

        return $rendered[$table]['php_source'];
    }

    // ---------------------------------------------------------------
    // 兜底 B:schema drift 检测(真 DB ↔ yaml)
    // ---------------------------------------------------------------

    /**
     * 比对真 DB 跟 yaml 的差异 —— **仅列名集合**:类型 / 长度 / 默认值 / 索引变化不在
     * 检测范围(完整对账用 `moo:db:audit`)。返 warning 列表给 GUI 提示,不抛异常 ——
     * 工作流约定下 drift 只是信息(合并以 yaml 为准,生产首跑拿全量;本地不齐部署后拉库覆盖)。
     *
     * @return array<int, array{type:string, detail:string}>
     */
    public function detectSchemaDrift(string $schema, string $table): array
    {
        $warnings = [];

        if (! Schema::hasTable($table)) {
            // 本地 DB 还没建表 → 没法 drift,跳过
            $warnings[] = ['type' => 'no_db_table', 'detail' => "local DB 无 {$table} 表，跳过 drift 检测"];

            return $warnings;
        }

        $normalized = $this->loader->loadNormalized($schema);
        if (! isset($normalized['tables'][$table])) {
            return $warnings;
        }
        $yamlFields = array_keys($normalized['tables'][$table]['fields']);

        // 真 DB 列
        try {
            $dbCols = Schema::getColumnListing($table);
        } catch (\Throwable $e) {
            $warnings[] = ['type' => 'db_introspect_failed', 'detail' => 'DB introspection failed: ' . $e->getMessage()];

            return $warnings;
        }

        // 差集:DB 有但 yaml 没
        foreach (array_diff($dbCols, $yamlFields) as $extra) {
            $warnings[] = ['type' => 'column_db_only', 'detail' => "列 `{$extra}` 真 DB 有，yaml 无 — 重渲 create 不会包含"];
        }
        // 差集:yaml 有但 DB 没(yaml 改了但 migrate 没跑过)
        foreach (array_diff($yamlFields, $dbCols) as $missing) {
            $warnings[] = ['type' => 'column_yaml_only', 'detail' => "列 `{$missing}` yaml 有，真 DB 没 — migrate 未跑全"];
        }

        return $warnings;
    }

    // ---------------------------------------------------------------
    // 兜底 C:git push 检测
    // ---------------------------------------------------------------

    /**
     * 返回已 push 到 origin/<current-branch> 的文件列表(空 = 全部未 push,可合并)。
     *
     * 注意:依赖本地 origin refs 是否 up-to-date(我们 *不* 自动 fetch,避免侧作用)。
     * user 担心 stale → fetch 后再点 compact。
     *
     * plan-53(2026-07-03 复盘审查 #3):git 检测按 schema 出身切仓 —— 包 schema 的 migration
     * 在**包自己的 git 仓**里,branch / origin ref / git log 全部要对包仓跑;沿用宿主 cwd 会让
     * 包文件 realpath 落在宿主 repo 之外 → 命中「repo 外=沙箱」的 continue 被静默放行,
     * 已 push 的包 migration 被无确认改写(正是 2026-06-11 fail-closed 要防死的事故)。
     *
     * @param array<int, string> $absPaths
     *
     * @return array<int, string>
     */
    public function detectGitPushed(array $absPaths, string $schema = ''): array
    {
        if ($absPaths === []) {
            return [];
        }

        // 这道守护是 force=false 时的默认安全闸(未 push = 表没流出本机 = 任何其他环境都不可能
        // 跑过 → 怎么合并都安全)。push≠服务器部署,所以它只是"可能已部署"的代理:命中后 GUI
        // 不再硬拦,而是让人工确认「未在 production/shared 部署」→ force=true 绕过本检测(见 execute)。
        // 因此本检测在 force=false 时必须 fail-closed:只有「确认不在 git repo」是合法跳过(沙箱/无
        // git 宿主);git 异常 / detached HEAD 等"无法确认"状态一律按已 push 处理,绝不静默放行
        // (2026-06-11 修,原先任何失败都放行)。真正的放行决定权交给人工 ack,不靠静默。
        // 按出身定 git 检测的 cwd:host = 宿主 cwd;包 = 包根(migrationDir 的上二级 = {包根})
        $origin = $schema !== '' ? $this->loader->originOf($schema) : null;
        $gitCwd = $origin === null ? $this->cwd : dirname($this->loader->migrationDirFor($schema), 2);

        try {
            // current branch
            $branchProc = new Process(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], $gitCwd);
            $branchProc->setTimeout(10);
            $branchProc->run();
            if (! $branchProc->isSuccessful()) {
                if (str_contains($branchProc->getErrorOutput(), 'not a git repository')) {
                    return [];     // 确认不在 git repo —— 唯一的合法跳过
                }
                throw new CompactBlockedException(
                    '无法确认推送状态（git 异常：' . trim($branchProc->getErrorOutput() ?: 'unknown') . '），拒绝合并；确认未推送可 force',
                    CompactBlockedException::REASON_GIT_UNCERTAIN,
                );
            }
            $branch = trim($branchProc->getOutput());
            if ($branch === '' || $branch === 'HEAD') {
                throw new CompactBlockedException(
                    '当前处于 detached HEAD，无法按分支确认推送状态，拒绝合并；切回分支后重试（或确认未推送可 force）',
                    CompactBlockedException::REASON_GIT_UNCERTAIN,
                );
            }

            // origin/<branch> 不存在 = 该分支从未 push 过 → 未推送,合法放行
            $remoteRefProc = new Process(['git', 'rev-parse', '--verify', "origin/{$branch}"], $gitCwd);
            $remoteRefProc->setTimeout(10);
            $remoteRefProc->run();
            if (! $remoteRefProc->isSuccessful()) {
                return [];
            }

            // repoRoot 同样按出身:host 走 GitInspector(宿主 cwd 固定);包按 gitCwd 现算,
            // 失败 = 无法确认 → fail-closed 抛(与 branch/detached 同一待遇)
            if ($origin === null) {
                $repoRoot = $this->git->repoRoot();
            } else {
                $rootProc = new Process(['git', 'rev-parse', '--show-toplevel'], $gitCwd);
                $rootProc->setTimeout(10);
                $rootProc->run();
                if (! $rootProc->isSuccessful() || trim($rootProc->getOutput()) === '') {
                    throw new CompactBlockedException(
                        "无法确认扩展包 [{$origin}] 的 git 仓根，拒绝合并；确认未推送可 force",
                        CompactBlockedException::REASON_GIT_UNCERTAIN,
                    );
                }
                $repoRoot = trim($rootProc->getOutput());
            }
            $pushed = [];
            foreach ($absPaths as $abs) {
                $rel = $this->relPathFromRepoRoot($abs, $repoRoot);
                if ($rel === $abs) {
                    continue;   // 文件在 repo 之外(如测试沙箱)→ 不可能被本 repo push,确定未推送
                }
                // 该文件在 origin/<branch> 历史里是否有 commit。
                // ⚠ cwd 必须是 repoRoot:pathspec 是相对 repo root 的,而 git 按 cwd 解析 ——
                // 原先用 $this->cwd(= Laravel base_path),宿主若在 git 仓子目录(如 宿主项目
                // 的 engine/),pathspec 永远查空 → 守护恒放行。2026-06-11 真机实战揭穿的存量洞。
                $logProc = new Process(['git', 'log', '--format=%H', "origin/{$branch}", '--', $rel], $repoRoot);
                $logProc->setTimeout(10);
                $logProc->run();
                if (! $logProc->isSuccessful()) {
                    throw new CompactBlockedException(
                        '无法确认 ' . basename($abs) . ' 的推送状态（git log 异常），拒绝合并；确认未推送可 force',
                        CompactBlockedException::REASON_GIT_UNCERTAIN,
                    );
                }
                if (trim($logProc->getOutput()) !== '') {
                    $pushed[] = $abs;
                }
            }

            return $pushed;
        } catch (CompactBlockedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Process 超时 / GitInspector 抛错等 —— 同样属于"无法确认",fail-closed
            throw new CompactBlockedException(
                '无法确认推送状态（' . $e->getMessage() . '），拒绝合并；确认未推送可 force',
                CompactBlockedException::REASON_GIT_UNCERTAIN,
            );
        }
    }

    // ---------------------------------------------------------------
    // DB 清理(migrations 表孤儿 entries)
    // ---------------------------------------------------------------

    /**
     * @param array<int, string> $migrationBasenames(去 .php 后缀)
     * @param array<int, string> $warnings(out)
     */
    private function cleanMigrationsTable(array $migrationBasenames, array &$warnings): int
    {
        try {
            return DB::table('migrations')->whereIn('migration', $migrationBasenames)->delete();
        } catch (\Throwable $e) {
            $warnings[] = 'DB 清理失败：' . $e->getMessage() . '（文件已删，migrations 表残留孤儿 entry，可手动 DELETE）';

            return 0;
        }
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function migrationPath(string $schema): string
    {
        // plan-53:按 schema 出身解析(host / 扩展包 database/migrations)
        return $this->loader->migrationDirFor($schema);
    }

    private function relPathFromRepoRoot(string $abs, string $repoRoot): string
    {
        // 两侧 realpath 规范化再比前缀:git 返回物理路径(/private/var/...),而 PHP 侧常是
        // 符号链接路径(macOS tmp 的 /var/...、symlink 部署的项目目录)—— 裸比对会把仓内文件
        // 误判成「repo 之外」→ 守护静默放行(2026-06-11 真机+回归测试连环揭穿)。
        $absReal  = realpath($abs) ?: $abs;
        $rootReal = realpath($repoRoot) ?: $repoRoot;
        $root     = rtrim($rootReal, '/') . '/';
        if (str_starts_with($absReal, $root)) {
            return substr($absReal, strlen($root));
        }

        return $abs;
    }
}
