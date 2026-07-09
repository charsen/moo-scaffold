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

    protected function chooseApp(array $apps): string
    {
        return $this->choicePrompt('选择 app', array_keys($apps));
    }

    /**
     * plan-53:schema 选择带出身标注(`System〔moo-system 扩展包〕`),选了即定出身、无独立 host/pkg 问题;
     * $forApp 非 admin 时按上下文收窄 —— 包 schema 固定 admin,api 等语境下不列(天然无矛盾)。
     */
    protected function chooseSchema(array $schemas, string $question = '选择 schema(模块)', ?string $forApp = null): string
    {
        $labels = [];   // label => name(标注只进选项文本,返回值恒为纯 schema 名)
        foreach ($schemas as $name) {
            $origin = $this->utility->schemaOrigin((string) $name);
            if ($origin !== null && $forApp !== null && $forApp !== 'admin') {
                continue;
            }
            $label          = $origin === null ? (string) $name : "{$name}〔{$origin} 扩展包〕";
            $labels[$label] = (string) $name;
        }

        $picked = $this->choicePrompt($question, array_keys($labels));

        return $labels[$picked] ?? (string) $picked;
    }

    protected function confirmConsoleCommand(string $command): bool
    {
        return $this->confirmPrompt("现在就执行 '{$command}' 吗", true);
    }

    /**
     * -f/--force 是 VALUE_OPTIONAL:传了不带值 → option 为 null → 强制;
     * 没传 → 默认 false → 不强制。=== null 即"用户传了 -f"。
     */
    protected function isForced(): bool
    {
        return $this->option('force') === null;
    }

    protected function reportAppNotConfigured(string $app, string $detail = 'Please check the scaffold controller configuration.'): void
    {
        $this->console()->error("App \"{$app}\" is not configured. {$detail}");
    }

    protected function reportSchemaNotFound(string $schema): void
    {
        $this->console()->error("未找到 schema 文件 \"{$schema}\"。");
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
     * 提示执行完成
     */
    protected function tipDone($result = true): void
    {
        if ($result) {
            $this->console()->success('完成。');
        } else {
            $this->console()->error('失败。');
        }
    }

    /**
     * 疑似改名(同表同时 add+drop):CLI 收不到改名提示,统一引导去 designer 标改名。
     * $next 是各命令各自的后续动作文案(如「再重跑 moo:migration」)。
     */
    protected function tipUseDesignerRename(string $next = '确认后再重跑'): void
    {
        $this->console()->warn("检测到疑似改名(同表同时 add+drop)。CLI 无法接受改名提示,请到 /scaffold/db/designer 点「改名」{$next}。");
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

        $this->console()->info('💡 路由契约测已就位,择机跑(补完真断言后更佳):');
        $this->line('   php artisan test ' . implode(' ', $dirs));
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
            $this->console()->info("单表模式:本次只处理表 [{$table}](其它表跳过)");

            return true;
        }

        $this->console()->error("表 key \"{$table}\" 在 schema \"{$schema_name}\" 中不存在。");
        $this->line('  可选表 key:' . ($valid === [] ? '(无)' : implode(', ', $valid)));

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
     * 反查,免去再选模块;③ 都没给 → 交互选。反查失败返回 '',由调用方据此提前退出。
     */
    protected function resolveSchemaArg(?string $schema, ?string $table, ?string $forApp = null): string
    {
        if (! empty($schema)) {
            return (string) $schema;
        }

        if ($table !== null && $table !== '') {
            $hit = $this->schemaOfTable($table);
            if ($hit !== null) {
                $this->console()->info("已按表 [{$table}] 定位到 schema [{$hit}](无需再选模块)");

                return $hit;
            }
            $this->console()->error("找不到表 [{$table}] 所属的 schema —— 先跑 `moo:fresh`,或检查表名拼写。");

            return '';
        }

        return $this->chooseSchema($this->utility->getSchemaNames(), '选择 schema(模块)', $forApp);
    }
}
