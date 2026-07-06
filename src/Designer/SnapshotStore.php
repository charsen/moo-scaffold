<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Designer;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
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
 *   - 不做 atomic write(scaffold 是单 dev 工具,无并发)
 *   - 不防用户绕过 designer 改 yaml(同 plan-30 parser 路线,scaffold 不兜历史漂移)
 */
class SnapshotStore
{
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
        // plan-40 §三 R-1:LOCK_EX 防 captureTables vs save / multi-tab 写覆盖
        $this->fs->put($this->snapshotPath($schema), YamlFormatter::dump($parsed), lock: true);
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
     * @param array<int,string> $tableKeys
     *
     * @throws \RuntimeException 源 yaml 不存在 / 解析失败时
     */
    public function captureTables(string $schema, array $tableKeys): void
    {
        if ($tableKeys === []) {
            return;
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
            // plan-39 后 GUI 不再调 git commit,但仍保留稳态:源 yaml 坏掉时不抛,不写盘。
            // migration 文件已落盘 → baseline 不推进 → 下次 preview 会重报本次 change,
            // 用户必须先恢复 yaml 才能继续。Log warning 让运行时可见。
            Log::warning(
                "SnapshotStore::captureTables skipped: source yaml parse failed for {$schema}: {$e->getMessage()}",
            );

            return;
        }

        $snapshotPath = $this->snapshotPath($schema);
        if ($this->fs->exists($snapshotPath)) {
            try {
                $snapParsed = Yaml::parse($this->fs->get($snapshotPath)) ?: [];
            } catch (ParseException $e) {
                // 快照坏掉 — 当冷启动重新搭骨架,迁过来本次要 capture 的表
                Log::warning(
                    "SnapshotStore::captureTables snapshot for {$schema} unparseable, rebuilding from scratch: {$e->getMessage()}",
                );
                $snapParsed           = $currentParsed;
                $snapParsed['tables'] = [];
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
        $this->fs->put($snapshotPath, YamlFormatter::dump($snapParsed), lock: true);     // plan-40 §三 R-1
    }

    private function ensureSnapshotDir(string $schema): void
    {
        $dir = dirname($this->snapshotPath($schema));
        if (! $this->fs->isDirectory($dir)) {
            $this->fs->makeDirectory($dir, 0755, true);
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
        $this->fs->put($path, YamlFormatter::dump($parsed), lock: true);
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
