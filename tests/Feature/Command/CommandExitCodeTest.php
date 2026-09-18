<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\File;
use Mooeen\Scaffold\Command\AdderCommand;
use Mooeen\Scaffold\Command\CreateApiCommand;
use Mooeen\Scaffold\Command\CreateControllerCommand;
use Mooeen\Scaffold\Command\CreateMigrationCommand;
use Mooeen\Scaffold\Command\CreateModelCommand;
use Mooeen\Scaffold\Command\CreateResourceCommand;
use Mooeen\Scaffold\Command\CreateSchemaCommand;
use Mooeen\Scaffold\Command\CreateTestCommand;
use Mooeen\Scaffold\Command\CreateViewCommand;
use Mooeen\Scaffold\Command\FreeCommand;
use Mooeen\Scaffold\Command\FreshStorageCommand;
use Mooeen\Scaffold\Command\InitCommand;
use Mooeen\Scaffold\Command\UpdateAuthorizationCommand;
use Mooeen\Scaffold\Command\UpdateMultilingualCommand;
use Mooeen\Scaffold\Designer\SchemaLoader;
use Mooeen\Scaffold\Designer\SnapshotStore;
use Mooeen\Scaffold\RouterTool;
use Mooeen\Scaffold\Support\ConsoleUi;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * 第 16 项「退出码统一」的守卫。
 *
 * 背景:14 个 codegen 命令此前是 `handle(): void` + 裸 `return;` 早退,于是
 *   ① 各种「没做成」(app 未配 / schema 找不到 / 表 key 打错 / 表反查不到 schema)全部退出 0;
 *   ② `tipDone(false)` 屏幕上打红字「失败。」,退出码仍是 0 —— CI / 脚本据此判成功;
 *   ③ `resolveSchemaArg()` 用 `''` 同时表示「用户没给」与「没找到」,而非交互模式下 `chooseSchema()`
 *      回落的恰好也是 `''`,于是"拿不到"一路静默到 `未找到 schema ""`;
 *   ④ schema 列表为空时 `$this->choice([], ...)` 直接抛 Symfony 的
 *      `LogicException: Choice question must have at least 1 choice available.`(崩栈而非报错)。
 *
 * 三层守卫:签名(Reflection,零 fixture)→ 早退路径(行为,驱动真实命令)→ 契约收窄(?string 语义)。
 *
 * 为什么行为用例只覆盖这几条路径:testbench 的 base_path 下没有 `scaffold/database/`,
 * 凡需要 `FreshStorageGenerator` 的命令(model / resource / controller / migration / i18n / api)
 * 都会在写 `_fields.yaml` 时抛 `ErrorException`。所以行为断言刻意挑**在刷缓存之前**就早退的路径。
 */
it('14 个 codegen 命令的 handle() 都声明 int 返回类型（否则 tipDone / report* 给的退出码会被丢弃）', function () {
    $classes = [
        AdderCommand::class               => 'moo:adder',
        CreateApiCommand::class           => 'moo:api',
        CreateControllerCommand::class    => 'moo:controller',
        CreateMigrationCommand::class     => 'moo:migration',
        CreateModelCommand::class         => 'moo:model',
        CreateResourceCommand::class      => 'moo:resource',
        CreateSchemaCommand::class        => 'moo:schema',
        CreateTestCommand::class          => 'moo:test',
        CreateViewCommand::class          => 'moo:view',
        FreeCommand::class                => 'moo:free',
        FreshStorageCommand::class        => 'moo:fresh',
        InitCommand::class                => 'moo:init',
        UpdateAuthorizationCommand::class => 'moo:auth',
        UpdateMultilingualCommand::class  => 'moo:i18n',
    ];

    foreach ($classes as $class => $name) {
        $method = new ReflectionMethod($class, 'handle');
        expect((string) $method->getReturnType())->toBe(
            'int',
            "{$name}（{$class}）的 handle() 必须声明 int 返回类型 —— `void` 会把 handle 里所有 return 的退出码丢掉"
        );
    }
});

it('未知 app：报 is not configured 并以退出码 1 结束（4 个走 reportAppNotConfigured 的命令全覆盖）', function (string $command) {
    // only_in_local 关掉,免得 checkRunning() 的 guard 先把它挡下 —— 本用例要验的是 app 校验那一段
    config(['scaffold.only_in_local' => false]);

    $this->artisan($command, ['app' => 'no-such-app', '--no-interaction' => true])
        ->expectsOutputToContain('is not configured')
        ->assertExitCode(1);
})->with([
    'moo:adder',
    'moo:auth',
    'moo:api',
    'moo:free',
]);

it('schema 列表为空：明确报错并以退出码 1 结束（早前抛 Symfony LogicException 崩栈）', function () {
    $empty = sys_get_temp_dir() . '/scaffold-no-schema-' . bin2hex(random_bytes(4));
    File::makeDirectory($empty, 0777, true);

    $orig = config('scaffold.database.schema');
    config(['scaffold.database.schema' => $empty . '/']);
    // SchemaLoader 的内存 cache 跟 path 强绑定(且它不是容器单例,但可能被 buffer 过),换路径必须清
    app()->forgetInstance(SchemaLoader::class);
    app()->forgetInstance(SnapshotStore::class);

    try {
        config(['scaffold.only_in_local' => false]);

        $this->artisan('moo:view', ['--no-interaction' => true])
            ->expectsOutputToContain('没有可选的 schema')
            ->assertExitCode(1);
    } finally {
        config(['scaffold.database.schema' => $orig]);
        app()->forgetInstance(SchemaLoader::class);
        app()->forgetInstance(SnapshotStore::class);
        File::deleteDirectory($empty);
    }
});

it('有 schema 可选项但没选成（非交互下 choice 回落 null）：明确报「未选择」并以退出码 1 结束', function () {
    // 这一条锁的是「空串双义」的现场:`chooseSchema()` 早前 `$labels[$picked] ?? (string) $picked`
    // 在非交互模式下把 null 归一成 `''`,调用方 `if ($schema_name === '') { return; }` 于是**零输出 + 退出码 0**。
    //
    // 为什么不走 `$this->artisan()`:Pest 的 harness 会把 OutputStyle 换成 Mockery 局部 mock,
    // `choice()` 直接打 `askQuestion()`(既不看 `--no-interaction` 也没排队答案)→ BadMethodCallException;
    // 换句话说「choice 回落默认值」这条路径在 harness 下根本表达不出来。
    // 所以这里自己实例化命令:① 用裸 BufferedOutput 做 console 出口(ConsoleUi 对裸 OutputInterface
    // 只做 writeln 路由)② 替掉"问用户"这一步返回 null(正是非交互模式的真实结果)。
    $sink = new BufferedOutput;

    $cmd = new class(app(Filesystem::class), app(Utility::class)) extends CreateViewCommand
    {
        public BufferedOutput $sink;

        /** `$input` / `$output` 都是 protected —— 从类内绑一个空输入(不给 schema_name) */
        public function primeInput(): void
        {
            $this->input = new ArrayInput([], $this->getDefinition());
        }

        protected function console(): ConsoleUi
        {
            return new ConsoleUi($this->sink);
        }

        /** 让选项列表非空(否则命中的是上面那条空列表守卫),并断开 SchemaLoader 依赖 */
        protected function hostSchemaNames(): array
        {
            return ['Demo'];
        }

        /** 非交互模式下 Symfony 的 choice 回落到默认值 null */
        protected function choicePrompt(string $question, array $choices, $default = null, ?int $attempts = null, bool $multiple = false): mixed
        {
            return null;
        }
    };
    $cmd->sink = $sink;
    $cmd->primeInput();   // 不给 schema_name,复现「没选成」

    config(['scaffold.only_in_local' => false]);

    expect($cmd->handle())->toBe(1)
        ->and($sink->fetch())->toContain('未选择 schema');
});

it('resolveSchemaArg / chooseSchema 的返回类型已从 string 收窄成 ?string（空串双义拆除）', function () {
    foreach ([CreateViewCommand::class, CreateModelCommand::class] as $class) {
        expect((string) (new ReflectionMethod($class, 'chooseSchema'))->getReturnType())->toBe('?string', "{$class}::chooseSchema");
        expect((string) (new ReflectionMethod($class, 'resolveSchemaArg'))->getReturnType())->toBe('?string', "{$class}::resolveSchemaArg");
    }
});

it('报错助手直接交回退出码：reportAppNotConfigured / reportSchemaNotFound / tipDone 都返回 int', function () {
    foreach (['reportAppNotConfigured', 'reportSchemaNotFound', 'tipDone'] as $method) {
        expect((string) (new ReflectionMethod(CreateViewCommand::class, $method))->getReturnType())->toBe('int', "{$method}()");
    }
});

it('tipDone 的返回值就是退出码：屏幕上「完成。/失败。」与 0/1 由同一处决定', function () {
    // 11 个命令的收尾都是 `return $this->tipDone($result);` —— 这条锁住「红字失败。」不会再配一个退出码 0。
    // 直接调 tipDone 需要一个 console 出口:命令没 run() 过时 `$this->output` 是 null,所以把 console()
    // 换成裸 BufferedOutput 再 invoke。
    $sink = new BufferedOutput;

    $cmd = new class(app(Filesystem::class), app(Utility::class)) extends CreateViewCommand
    {
        public BufferedOutput $sink;

        protected function console(): ConsoleUi
        {
            return new ConsoleUi($this->sink);
        }
    };
    $cmd->sink = $sink;

    $tipDone = new ReflectionMethod($cmd, 'tipDone');

    expect($tipDone->invoke($cmd, true))->toBe(0)
        ->and($sink->fetch())->toContain('完成');

    // fetch() 会清空缓冲,所以第二次只看到第二次的输出
    expect($tipDone->invoke($cmd, false))->toBe(1)
        ->and($sink->fetch())->toContain('失败');
});

it('表 key 反查不到所属 schema：resolveSchemaArg 返回 null 并明确报错（不再回空串）', function () {
    $sink = new BufferedOutput;

    $cmd = new class(app(Filesystem::class), app(Utility::class)) extends CreateModelCommand
    {
        public BufferedOutput $sink;

        protected function console(): ConsoleUi
        {
            return new ConsoleUi($this->sink);
        }

        /** 断开 storage/scaffold 缓存依赖,直接给"表不属于任何 schema"这个结果 */
        protected function schemaOfTable(string $table): ?string
        {
            return null;
        }
    };
    $cmd->sink = $sink;

    $resolveSchemaArg = new ReflectionMethod($cmd, 'resolveSchemaArg');

    // 反查失败 → null(早前是 '',调用方 `=== ''` 与"用户没给"撞在一起)
    expect($resolveSchemaArg->invoke($cmd, null, 'no_such_table'))->toBeNull()
        ->and($sink->fetch())->toContain('找不到表');

    // 正向:显式给了 schema 就原样用,不去反查表(这条保证 null 化没把 happy path 改坏)
    expect($resolveSchemaArg->invoke($cmd, 'System', 'no_such_table'))->toBe('System');
});

// ─── moo:api 的 namespace 选择:同一个「choice 回落 null」坑的另一处现场 ──────────
//
// 第 16 项修了 `chooseSchema()`,但 `CreateApiCommand` 选 namespace 走的是自己那条线:
//   `$namespace = $this->choicePrompt('选择 namespace', $namespaces);` 之后直接
//   `new RouterTool($app, $namespace, …)` —— 非交互模式下回落 null,而 `RouterTool::$folder`
//   是 `string` 属性,于是在**属性赋值处**抛 `Cannot assign null to property … of type string`
//   崩栈;选项列表为空时还会抛 Symfony 的 LogicException。两者都不是「一行红字 + 退出码 1」。
//
// 同样不走 `$this->artisan()`:这条早退在 `FreshStorageGenerator->start()` **之后**,
// 而 testbench 的 base_path 下没有 `scaffold/database/`,驱动 handle() 会先在那一步炸掉。
// 所以直接对 `chooseNamespace()` 取证(reflection + 替掉 choicePrompt / 目录扫描两处外部依赖)。

/** 造一个只返回固定 namespace 列表的命令:断开 `Utility::getControllerNamespaces()` 的真实目录扫描。 */
function fakeApiNamespaceCommand(array $namespaces, BufferedOutput $sink, ?callable $choice): CreateApiCommand
{
    $utility = new class($namespaces) extends Utility
    {
        public function __construct(private readonly array $fixed) {}

        public function getControllerNamespaces(string $app = 'admin'): array
        {
            return $this->fixed;
        }
    };

    $cmd = new class(app(Filesystem::class), $utility, app(Router::class), $choice) extends CreateApiCommand
    {
        public BufferedOutput $sink;

        /** @var null|callable(string, array): mixed */
        public $chooser;

        public function __construct(Filesystem $fs, Utility $utility, Router $router, ?callable $chooser)
        {
            parent::__construct($fs, $utility, $router);
            $this->chooser = $chooser;
        }

        protected function console(): ConsoleUi
        {
            return new ConsoleUi($this->sink);
        }

        protected function choicePrompt(string $question, array $choices, $default = null, ?int $attempts = null, bool $multiple = false): mixed
        {
            return $this->chooser === null ? null : ($this->chooser)($question, $choices);
        }
    };
    $cmd->sink = $sink;

    return $cmd;
}

it('moo:api 非交互下没选成 namespace：报「未选择 namespace」并返回 null（调用方转 FAILURE）', function () {
    $sink = new BufferedOutput;
    $cmd  = fakeApiNamespaceCommand(['System', 'Light'], $sink, null);   // null = 非交互模式 symfony 的回落值

    $choose = new ReflectionMethod($cmd, 'chooseNamespace');

    expect($choose->invoke($cmd, 'admin'))->toBeNull()
        ->and($sink->fetch())->toContain('未选择 namespace');
});

it('moo:api 一个控制器命名空间都没有：报错而不是抛 Symfony 的 LogicException 崩栈', function () {
    $sink = new BufferedOutput;
    $cmd  = fakeApiNamespaceCommand([], $sink, null);

    $choose = new ReflectionMethod($cmd, 'chooseNamespace');

    expect($choose->invoke($cmd, 'admin'))->toBeNull()
        ->and($sink->fetch())->toContain('没有找到任何控制器命名空间');
});

it('moo:api 选中了 namespace：归一后原样返回（happy path 没被守门改坏）', function () {
    $sink = new BufferedOutput;
    $cmd  = fakeApiNamespaceCommand(['system', 'light'], $sink, fn () => 'system');

    $choose = new ReflectionMethod($cmd, 'chooseNamespace');

    expect($choose->invoke($cmd, 'admin'))->toBe('System');   // normalizeNamespace: ucfirst
});

it('chooseNamespace 的返回类型是 ?string，且 handle() 把 null 转成 FAILURE（不再静默往下走）', function () {
    expect((string) (new ReflectionMethod(CreateApiCommand::class, 'chooseNamespace'))->getReturnType())->toBe('?string');

    $src = (string) file_get_contents((new ReflectionClass(CreateApiCommand::class))->getFileName());

    // 源码锚点:拿不到 namespace 必须中止。少了下面这三行,红字会配上一个退出码 0。
    expect($src)->toMatch(
        '/\$namespace = \$this->chooseNamespace\(\$app\);\s+if \(\$namespace === null\) \{\s+return self::FAILURE;/'
    );
});

it('RouterTool 的 $folder 形参已声明 string（越界值在调用点就失败,而不是属性赋值处）', function () {
    $params = (new ReflectionMethod(RouterTool::class, '__construct'))->getParameters();

    expect((string) $params[1]->getType())->toBe('string')
        ->and($params[1]->getName())->toBe('folder');
});
