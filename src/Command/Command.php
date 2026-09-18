<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2025-07-16 11:05
 * @Description: Command
 */

namespace Mooeen\Scaffold\Command;

use Illuminate\Console\Command as BaseCommand;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Designer\SchemaLoader;
use Mooeen\Scaffold\Support\Concerns\InteractsWithConsoleUi;
use Mooeen\Scaffold\Utility;

class Command extends BaseCommand
{
    use InteractsWithConsoleUi;

    protected bool $requiresLocalEnvironment = true;

    protected Filesystem $filesystem;

    protected Utility $utility;

    /**
     * Create a new command instance.
     */
    public function __construct(Filesystem $filesystem, Utility $utility)
    {
        parent::__construct();

        $this->filesystem = $filesystem;
        $this->utility    = $utility;
    }

    protected function getConsoleTarget(): BaseCommand|Factory
    {
        return $this;
    }

    protected function showTitle(): void
    {
        $this->console()->title((string) ($this->title ?? $this->getName()));
    }

    protected function askPrompt(string $question, ?string $default = null): mixed
    {
        return $this->ask($this->console()->prompt($question), $default);
    }

    protected function choicePrompt(string $question, array $choices, $default = null, ?int $attempts = null, bool $multiple = false): mixed
    {
        return $this->choice($this->console()->prompt($question), $choices, $default, $attempts, $multiple);
    }

    protected function confirmPrompt(string $question, bool $default = false): bool
    {
        return $this->confirm($this->console()->prompt($question), $default);
    }

    /**
     * 隐藏输入(不回显)。与 askPrompt / choicePrompt / confirmPrompt 同一条路径：
     * 提示语由 ConsoleUi 统一加标记，命令层不再直连 `$this->secret()`。
     */
    protected function secretPrompt(string $question, bool $fallback = true): mixed
    {
        return $this->secret($this->console()->prompt($question), $fallback);
    }

    protected function chooseApp(array $apps): string
    {
        return $this->choicePrompt('选择 app', array_keys($apps));
    }

    /**
     * 全部 schema 名(plan-53:走 Designer 的 SchemaLoader 单一真源,host + 各扩展包聚合)。
     * 命令编排层直接问 Designer,不再经 Utility 反调(拆 Utility→SchemaLoader 依赖环)。
     */
    protected function schemaNames(): array
    {
        return array_keys(app(SchemaLoader::class)->listSchemaFiles());
    }

    /**
     * schema 的出身:null = host,否则扩展包 key(plan-53)。
     */
    protected function schemaOrigin(string $schema): ?string
    {
        return app(SchemaLoader::class)->originOf($schema);
    }

    /**
     * 仅 host schema(滤掉扩展包出身)—— moo:view / moo:test 等本 plan 暂不碰包的命令用。
     */
    protected function hostSchemaNames(): array
    {
        return array_values(array_filter($this->schemaNames(), fn (string $s): bool => $this->schemaOrigin($s) === null));
    }

    /**
     * plan-53:schema 选择带出身标注(`System〔moo-system 扩展包〕`),选了即定出身、无独立 host/pkg 问题;
     * $forApp 非 admin 时按上下文收窄 —— 包 schema 固定 admin,mobi/web 等语境下不列(天然无矛盾)。
     *
     * 返回 null = 没得选或没选成,两种情况都**先报错**再返回,调用方一律中止(FAILURE):
     *   ① 选项列表为空(一个 schema 都没落 / app 收窄后列空)—— 早前这里会直接抛 Symfony 的
     *      `LogicException: Choice question must have at least 1 choice available.`(崩栈,不是报错);
     *   ② 非交互模式(`--no-interaction`)下 choice 回落到默认 null —— 早前 `(string) null` 变成 `''`
     *      冒充"合法的空 schema 名",调用方只判 `=== ''` 就 `return;`,于是**零输出、退出码 0**。
     */
    protected function chooseSchema(array $schemas, string $question = '选择 schema（模块）', ?string $forApp = null): ?string
    {
        $labels = [];   // label => name(标注只进选项文本,返回值恒为纯 schema 名)
        foreach ($schemas as $name) {
            $origin = $this->schemaOrigin((string) $name);
            if ($origin !== null && $forApp !== null && $forApp !== 'admin') {
                continue;
            }
            $label          = $origin === null ? (string) $name : "{$name}〔{$origin} 扩展包〕";
            $labels[$label] = (string) $name;
        }

        if ($labels === []) {
            $this->console()->error('没有可选的 schema。请先跑 `moo:init` 初始化脚手架，或确认当前 app 下有对应模块。');

            return null;
        }

        $picked = $this->choicePrompt($question, array_keys($labels));
        $name   = $labels[$picked] ?? (string) $picked;

        if ($name === '') {
            $this->console()->error('未选择 schema。非交互模式下请显式传入 schema 名，或用 -t 指定表 key 自动反查。');

            return null;
        }

        return $name;
    }

    protected function confirmConsoleCommand(string $command): bool
    {
        return $this->confirmPrompt("现在就执行 '{$command}' 吗", true);
    }

    /**
     * -f/--force 是 VALUE_OPTIONAL:传了不带值 → option 为 null → 强制;
     * 没传 → 默认 false → 不强制。=== null 即"用户传了 -f"。
     *
     * ⚠ 仅适用于 force 声明为 VALUE_OPTIONAL 的命令;若某命令把 force 声明成 VALUE_NONE
     * (如 SnapshotInitCommand,读 `(bool) $this->option('force')`),语义相反,勿改用本助手。
     */
    protected function isForced(): bool
    {
        return $this->option('force') === null;
    }

    /**
     * 报「app 未配置」并交回退出码 —— 调用方写 `return $this->reportAppNotConfigured($app);`。
     * 这样「给用户看的那句话」与「进程退出码」同源,不会出现「报了 error 却退出 0」。
     */
    protected function reportAppNotConfigured(string $app, string $detail = 'Please check the scaffold controller configuration.'): int
    {
        $this->console()->error("App \"{$app}\" is not configured. {$detail}");

        return self::FAILURE;
    }

    /**
     * 同 reportAppNotConfigured:报「未找到 schema」并以 FAILURE 结束。
     */
    protected function reportSchemaNotFound(string $schema): int
    {
        $this->console()->error("未找到 schema 文件 \"{$schema}\"。");

        return self::FAILURE;
    }

    /**
     * 是否在非正式环境中关闭命令行功能
     */
    protected function checkRunning(): bool
    {
        if (! $this->requiresLocalEnvironment) {
            return true;
        }

        if ($this->utility->getConfig('only_in_local') && ! app()->isLocal()) {
            $this->console()->error('moo-scaffold commands are only available in the local environment.');

            return false;
        }

        return true;
    }

    /**
     * 提示执行的命令
     */
    protected function tipCallCommand($command): void
    {
        $this->console()->section("Running {$command}");
    }

    /**
     * 提示执行完成 —— **返回值即退出码**(`true` → SUCCESS / `false` → FAILURE),调用方写
     * `return $this->tipDone($result);`。
     *
     * 刻意让「屏幕上那句 完成。/失败。」与「进程退出码」由这一处同时决定:早前命令 `handle(): void`
     * 时,`tipDone(false)` 打了红字「失败。」而退出码仍是 0,CI / 脚本据此判成功。
     */
    protected function tipDone($result = true): int
    {
        if ($result) {
            $this->console()->success('完成。');
        } else {
            $this->console()->error('失败。');
        }

        return $result ? self::SUCCESS : self::FAILURE;
    }

    /**
     * 疑似改名(同表同时 add+drop):CLI 收不到改名提示,统一引导去 designer 标改名。
     * $next 是各命令各自的后续动作文案(如「再重跑 moo:migration」)。
     */
    protected function tipUseDesignerRename(string $next = '确认后再重跑'): void
    {
        $this->console()->warn("检测到疑似改名（同表同时 add+drop）。CLI 无法接受改名提示，请到 /scaffold/db/designer 点「改名」{$next}。");
    }

    /**
     * 打「择机跑这批测试」提示 —— 非交互,只给一条可复制的 `php artisan test {目录}` 命令,
     * 操作者补完真断言 / 进 CI 后自行跑。空目录(没落任何测)则静默。
     *
     * @param list<string> $dirs 相对测试目录,形如 ['tests/Feature/Admin/Market']
     */
    protected function tipRunTests(array $dirs): void
    {
        if ($dirs === []) {
            return;
        }

        $this->console()->info('💡 路由契约测已就位，择机跑（补完真断言后更佳）：');
        $this->console()->line('   php artisan test ' . implode(' ', $dirs));
    }

    /**
     * 解析 -t/--table 选项:trim 后空串(仅给 flag 不给值)视作 null(不过滤)。
     * 单表模式只过滤 Model/Resource/Controller 这类按表生成的步骤。
     */
    protected function resolveOnlyTable(): ?string
    {
        $table = $this->option('table');

        return is_string($table) && trim($table) !== '' ? trim($table) : null;
    }

    /**
     * 校验表 key 属于该 schema(读 moo:fresh 刚重建的 models.php 缓存,它是所有表的全集)。
     * 命中提示「单表模式」并放行;不存在则报错 + 列出可选 key,返回 false 让 handle() 提前退出。
     */
    protected function assertTableInSchema(string $schema_name, string $table): bool
    {
        $models = $this->filesystem->getRequire($this->utility->getStoragePath() . 'models.php');
        $valid  = array_values(array_column($models[$schema_name] ?? [], 'table_name'));

        if (in_array($table, $valid, true)) {
            $this->console()->info("单表模式：本次只处理表 [{$table}]（其它表跳过）");

            return true;
        }

        $this->console()->error("表 key \"{$table}\" 在 schema \"{$schema_name}\" 中不存在。");
        $this->console()->line('  可选表 key：' . ($valid === [] ? '（无）' : implode(', ', $valid)));

        return false;
    }

    /**
     * 反查表 key 属于哪个 schema(读 models.php 全集)。表 key = 真实 DB 表名、全局唯一 → 唯一命中。
     * 找不到(没跑 moo:fresh / 表名打错)返回 null。
     */
    protected function schemaOfTable(string $table): ?string
    {
        $file = $this->utility->getStoragePath() . 'models.php';
        if (! $this->filesystem->isFile($file)) {
            return null;
        }

        foreach ($this->filesystem->getRequire($file) as $schema => $rows) {
            if (in_array($table, array_column($rows, 'table_name'), true)) {
                return (string) $schema;
            }
        }

        return null;
    }

    /**
     * 定位要操作的 schema 单一入口:① 显式给了就用;② 没给但 `-t` 指定了表 → 按全局唯一表 key
     * 反查,免去再选模块;③ 都没给 → 交互选。
     *
     * 返回 `null` = **定位失败**(反查不到该表所属 schema / 没有可选的 schema / 非交互下没选成),
     * 返回 `string` = 拿到确定的 schema 名。调用方一律按 null 中止(FAILURE)。
     *
     * **刻意不再用 `''` 表示失败**:空串同时也是「用户本来就没给 schema」的初始态,`$schema_name === ''`
     * 这行读起来分不清是"没给"还是"没找到";而 chooseSchema 在非交互模式下回落的正是 `''`,
     * 于是"拿不到"这件事一路静默到 `未找到 schema ""`。null 与 `''` 分开后两义各有明确归属。
     */
    protected function resolveSchemaArg(?string $schema, ?string $table, ?string $forApp = null): ?string
    {
        if (! empty($schema)) {
            return (string) $schema;
        }

        if ($table !== null && $table !== '') {
            $hit = $this->schemaOfTable($table);
            if ($hit !== null) {
                $this->console()->info("已按表 [{$table}] 定位到 schema [{$hit}]（无需再选模块）");

                return $hit;
            }
            $this->console()->error("找不到表 [{$table}] 所属的 schema —— 先跑 `moo:fresh`，或检查表名拼写。");

            return null;
        }

        return $this->chooseSchema($this->schemaNames(), '选择 schema（模块）', $forApp);
    }
}
