<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Designer;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Mooeen\Scaffold\Support\Concerns\AtomicFileWrite;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Plan 36:designer baseline 改用「上次成功生成 migration 时的 yaml 快照」。
 *
 * 快照存 `scaffold/database/.snapshots/{Schema}.yaml`,记录当时源 yaml 的数据(经 YamlFormatter
 * normalize,跟 captureTables / unsetTables 同格式 —— 不带注释、canonical key 序,跨 migrate 路径
 * 格式稳定,避免 git churn)。应当 commit 进 git — 跟 migration 文件一起跨成员/分支同步。
 *
 * 写时机:只在 designer migrate 成功 / `moo:migration` CLI 成功后调 capture。
 * 读时机:SchemaDiffService::loadBaseline 取这份快照当 baseline。
 * 冷启动:无快照 → load() 返回 null → diff 把所有表当 create。
 *
 * Round 2 修 P0/P1:capture 分两层:
 *   - capture()         全量覆盖(snapshot:init / 全表 migrate)
 *   - captureTables(t)  只 merge 指定表子树(designer only_table / CLI 只写部分表 create)
 *
 * 不做的:
 *   - 不做多版本(git 已经管理)
 *   - 不做 flock(读-改-写的丢更新不是锁能解决的;写入本身已原子,见 writeSnapshot)
 *   - 不防用户绕过 designer 改 yaml(同 plan-30 parser 路线,scaffold 不兜历史漂移)
 */
class SnapshotStore
{
    use AtomicFileWrite;

    public function __construct(
        private readonly Filesystem $fs,
    ) {}

    /**
     * 把当前工作树 yaml 拷一份到 .snapshots/{Schema}.yaml,作为下一次 diff 的 baseline。
     * 全量覆盖。
     *
     * @throws \RuntimeException 源 yaml 不存在时
     */
    public function capture(string $schema): void
    {
        $this->assertOriginWritable($schema);
        $source = $this->sourcePath($schema);
        if (! $this->fs->exists($source)) {
            throw new \RuntimeException("schema yaml not found, cannot capture snapshot: {$source}");
        }

        // 2026-06-10:走 YamlFormatter::dump,跟 captureTables / unsetTables **同一种格式**。
        // 原先 capture() 是 verbatim 拷贝(带注释 + 原 key 序),另两个走 normalized dump → 同一个
        // baseline 文件随 migrate 路径(全量 moo:migration 用 capture / designer 单表用 captureTables)
        // 在 verbatim 与 normalized 之间反复横跳 → git 跨成员同步时整文件 churn / 合并冲突。
        // 统一为 normalized:baseline 是机读 diff 源,不需要注释,确定性 > 保留注释。
        try {
            $parsed = Yaml::parse($this->fs->get($source)) ?: [];
        } catch (ParseException $e) {
            throw new \RuntimeException("schema yaml parse failed, cannot capture snapshot for {$schema}: {$e->getMessage()}");
        }

        $this->ensureSnapshotDir($schema);
        // 这里刻意**不**检查返回值：capture() 只被 `moo:snapshot:init` 调用，写失败原先只 log；
        // 把它改成抛属于独立的行为变更（会改 CLI 退出行为），不混进本次「captureTables → write()
        // 链可见化」的范围。见 NOTES.md 的残留清单。
        $this->writeSnapshot($this->snapshotPath($schema), YamlFormatter::dump($parsed));
    }

    /**
     * 只更新 baseline 中 $tableKeys 列出的表子树,其它表的 baseline 保持不变。
     *
     * 用途:designer 用 only_table 单表 migrate,或 CLI 只为部分新表写了 create — 这种场景下,
     * 全量 capture 会把"用户未通过本次 migrate 落地的修改"也吃进 baseline,导致下次 diff 漏报。
     *
     * 行为:
     *   - 没有现有快照 → 当 init 写入 — 仅写指定表
     *   - 表在 current yaml 不存在(被删了)→ 从快照中移除该表
     *   - 表在 current yaml 存在 → snapshot[tables][k] = current[tables][k]
     *
     * 返回状态（2026-09-11 新增 —— 原先 `void`，导致下面两条失败路径调用方无从得知）：
     *   - `advanced=false` ⇒ baseline **没有**按请求推进，`reason` 是给用户看的中文说明，调用方必须回报；
     *   - `rebuilt_from_scratch=true` ⇒ 快照自身损坏、已从零重建，**其它表的 baseline 被丢弃**。
     *
     * 为什么不用异常表达这两件事：本方法在 migration 文件**已落盘之后**才调，抛异常会把流程
     * 打断在半成品状态（见下方 parse 分支的注释），所以保留「log 不抛」，但**必须把结果交回调用方**
     * —— 否则调用方会向用户报成功，而用户下次预览重见同一变更、再点一次就产出重复 migration。
     *
     * @param array<int,string> $tableKeys
     *
     * @return array{advanced:bool, rebuilt_from_scratch:bool, reason:?string}
     *
     * @throws \RuntimeException 源 yaml 文件**不存在**时（注意：解析失败**不**抛，见上）
     */
    public function captureTables(string $schema, array $tableKeys): array
    {
        if ($tableKeys === []) {
            return ['advanced' => true, 'rebuilt_from_scratch' => false, 'reason' => null];
        }     // 没要更新的表 → no-op

        $this->assertOriginWritable($schema);
        $source = $this->sourcePath($schema);
        if (! $this->fs->exists($source)) {
            throw new \RuntimeException("schema yaml not found, cannot captureTables: {$source}");
        }

        $currentRaw = $this->fs->get($source);
        try {
            $currentParsed = Yaml::parse($currentRaw) ?: [];
        } catch (ParseException $e) {
            // 保留稳态:源 yaml 坏掉时不抛,不写盘 —— 本方法在 migration 文件已落盘之后调,
            // 抛异常会把流程打断在半成品状态。代价是 baseline 不推进、下次 preview 会重报本次
            // change。原先这里只 Log::warning,调用方无从得知,于是照旧向用户报成功
            // (web 端是绿色 toast「migration 已生成 N 个文件」)。现把结果交回调用方。
            Log::warning(
                "SnapshotStore::captureTables skipped: source yaml parse failed for {$schema}: {$e->getMessage()}",
            );

            return [
                'advanced'             => false,
                'rebuilt_from_scratch' => false,
                'reason'               => '源 schema yaml 解析失败，baseline 未推进（migration 文件已落盘）。'
                    . '下次预览会重报本次变更；请先修好 yaml 再重新 preview + 生成，'
                    . '不要直接再点一次生成（会产出重复 migration）。原因：' . $e->getMessage(),
            ];
        }

        $snapshotPath = $this->snapshotPath($schema);
        $rebuilt      = false;
        if ($this->fs->exists($snapshotPath)) {
            try {
                $snapParsed = Yaml::parse($this->fs->get($snapshotPath)) ?: [];
            } catch (ParseException $e) {
                // 快照坏掉 — 当冷启动重新搭骨架,迁过来本次要 capture 的表。
                // ⚠ 副作用:下面的 merge 只放回 $tableKeys 列出的表,**其它表的 baseline 全部丢弃**
                // (之后那些表会走 baseline_drift 拒生成)。快照是入 git 的全员共享文件,冲突标记
                // 落进去就会命中,所以这个副作用必须让调用方看见。
                Log::warning(
                    "SnapshotStore::captureTables snapshot for {$schema} unparseable, rebuilding from scratch: {$e->getMessage()}",
                );
                $snapParsed           = $currentParsed;
                $snapParsed['tables'] = [];
                $rebuilt              = true;
            }
        } else {
            // 冷启动:用 current 的 top-level scaffolding 当骨架,tables 留空待 merge
            $snapParsed           = $currentParsed;
            $snapParsed['tables'] = [];
        }
        $snapParsed['tables'] ??= [];

        foreach ($tableKeys as $tk) {
            if (isset($currentParsed['tables'][$tk])) {
                $snapParsed['tables'][$tk] = $currentParsed['tables'][$tk];
            } else {
                unset($snapParsed['tables'][$tk]);     // 表已从 yaml 删除 → 从 baseline 也删
            }
        }

        $this->ensureSnapshotDir($schema);
        $written = $this->writeSnapshot($snapshotPath, YamlFormatter::dump($snapParsed));

        if (! $written) {
            return [
                'advanced'             => false,
                'rebuilt_from_scratch' => $rebuilt,
                'reason'               => '快照写入失败（详见日志），baseline 未推进。下次预览会重报本次变更；'
                    . '请确认 .snapshots/ 目录可写后重新 preview + 生成，不要直接再点一次生成（会产出重复 migration）。',
            ];
        }

        return [
            'advanced'             => true,
            'rebuilt_from_scratch' => $rebuilt,
            'reason'               => $rebuilt
                ? '快照文件损坏，已从零重建：本次这几张表的 baseline 已就位，但**其它表的 baseline 已被丢弃**。'
                    . '需要时请从 git 还原 .snapshots/ 里其它表的段，否则那些表会报 baseline 缺失、拒绝生成 migration。'
                : null,
        ];
    }

    /**
     * 把 `captureTables()` 的返回状态转成一句给用户看的提示（无异常 → 空串）。
     *
     * 各调用方（CLI 命令 / DesignerController 三条路径）都要显示它，所以格式化只留这一处，
     * 免得 5 个地方各自写 null 判断与 `⚠` 前缀后慢慢漂移。
     *
     * @param array{advanced?:bool, rebuilt_from_scratch?:bool, reason?:?string} $baseline
     */
    public static function baselineNote(array $baseline): string
    {
        $reason = $baseline['reason'] ?? null;

        return is_string($reason) && $reason !== '' ? '⚠ ' . $reason : '';
    }

    private function ensureSnapshotDir(string $schema): void
    {
        $dir = dirname($this->snapshotPath($schema));
        if (! $this->fs->isDirectory($dir)) {
            $this->fs->makeDirectory($dir, 0755, true);
        }
    }

    /**
     * 原子写快照。失败只 log 不抛 —— 与本类对 parse 失败的处理保持一致：
     * 快照没写成功 ⇒ baseline 不推进 ⇒ 下次 preview 会重报本次 change，用户重跑即可；
     * 而抛异常会在 migration 文件已落盘之后把流程打断，反而更难收拾。
     *
     * 2026-09-11：原先 3 处 `$this->fs->put($path, ..., lock: true)`，既非原子（半写会留
     * 半份 baseline）又不检查返回值（写失败完全静默）。LOCK_EX 只串行化写入动作，
     * 挡不住半写撕裂，故改用 tmp + rename 并补上失败可见性。
     *
     * 返回值供 `captureTables()` 把"没写成功 ⇒ baseline 没推进"回报给调用方
     * —— 只 log 不抛是刻意的（别在 migration 已落盘后打断），但**不能再静默**。
     */
    private function writeSnapshot(string $path, string $content): bool
    {
        try {
            $this->writeFileAtomically($path, $content);

            return true;
        } catch (\RuntimeException $e) {
            Log::warning("SnapshotStore write failed for {$path}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * 读快照 raw 内容。无快照 → null(冷启动 / 用户手 rm)。
     */
    /**
     * 2026-05-21 C+ 方案:从 snapshot 中 unset 指定 table 子树。
     * 用途:designer 删除 migration 文件 + 勾选"同时让 designer 重新生成此 migration" 时,
     * 清掉该表 baseline,SchemaDiffService 看 baseline 缺该表 + DB hasTable check 决定走
     * baseline_drift(防 prod 冲突)还是 add(表真的没建过)。
     *
     * @param array<int,string> $tableKeys
     */
    public function unsetTables(string $schema, array $tableKeys): void
    {
        if ($tableKeys === []) {
            return;
        }
        $this->assertOriginWritable($schema);
        $path = $this->snapshotPath($schema);
        if (! $this->fs->exists($path)) {
            return;
        }
        try {
            $parsed = Yaml::parse($this->fs->get($path)) ?: [];
        } catch (ParseException $e) {
            Log::warning(
                "SnapshotStore::unsetTables skipped: snapshot parse failed for {$schema}: {$e->getMessage()}",
            );

            return;
        }
        $changed = false;
        foreach ($tableKeys as $tk) {
            if (isset($parsed['tables'][$tk])) {
                unset($parsed['tables'][$tk]);
                $changed = true;
            }
        }
        if (! $changed) {
            return;
        }
        // plan-49 后续:统一走 YamlFormatter,canonical key 顺序 + tables 间空行(跟主 yaml 一致)
        $this->writeSnapshot($path, YamlFormatter::dump($parsed));
    }

    public function load(string $schema): ?string
    {
        $path = $this->snapshotPath($schema);
        if (! $this->fs->exists($path)) {
            return null;
        }

        return (string) $this->fs->get($path);
    }

    public function exists(string $schema): bool
    {
        return $this->fs->exists($this->snapshotPath($schema));
    }

    /**
     * 快照绝对路径(用于命令行展示 / debug)。
     * plan-53:按出身派生自源 yaml 所在目录 —— 包 schema 的快照落**包内** `.snapshots/`,
     * 随包仓 git 同步(跨机器 / 跨 host diff 基线一致);host 照旧 scaffold/database/.snapshots/。
     */
    public function snapshotPath(string $schema): string
    {
        return dirname($this->sourcePath($schema)) . '/.snapshots/' . $schema . '.yaml';
    }

    private function sourcePath(string $schema): string
    {
        // 经 SchemaLoader::yamlPath 按出身解析(host / 扩展包);app() 晚绑定,测试可换 Registry
        return app(SchemaLoader::class)->yamlPath($schema);
    }

    /**
     * 写权硬线在 store 层兜底:
     * capture / captureTables / unsetTables 多入口共用(designer / MigrationWriter / CLI
     * moo:snapshot:init),不能只信任上游各自把闸 —— vcs 拷贝包(非软链)在这里统一硬拒,
     * 否则快照写进 vendor 拷贝,composer update 即蒸发、diff 基线丢失。
     */
    private function assertOriginWritable(string $schema): void
    {
        app(SchemaLoader::class)->assertOriginWritable($schema);
    }
}
