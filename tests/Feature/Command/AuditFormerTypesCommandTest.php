<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Mooeen\Scaffold\Support\FormWidgetTypes;

/**
 * moo:audit:former-types 特征测试。
 *
 * 用临时目录造一个最小 SPA（`apps/admin/src/components/former/config.ts`），后端清单取包内真实的
 * `FormWidgetTypes::FORMER` —— 不依赖真实 SPA 仓库，也不复制第二份后端清单（复制会掩盖漏改）。
 * 覆盖：目录推导 / 文件直指、一致退出码 0、前端多一项 / 后端多一项退出码 1、路径与解析失败
 * 退出码 2 且报明确错误（「读不到」不得被说成「一致」）。
 *
 * 用 Artisan::call + Artisan::output()：`$this->artisan()` 的 PendingCommand 在 testbench 下取不到输出。
 */
$GLOBALS['mooFormerTypesFixtures'] = [];

afterEach(function () {
    $fs = new Filesystem;
    foreach ($GLOBALS['mooFormerTypesFixtures'] ?? [] as $dir) {
        if (is_dir($dir)) {
            $fs->deleteDirectory($dir);
        }
    }
    $GLOBALS['mooFormerTypesFixtures'] = [];
});

/**
 * 造一份最小 `config.ts`：形态与真实文件一致 —— `ref<ElComponentMap>({...})` 对象字面量，
 * 连字符类型名用引号键、单词类型名用裸标识符键，条目里带嵌套 props 与注释（花括号不得干扰解析）。
 *
 * @param list<string> $types
 */
function formerTypesConfigSource(array $types): string
{
    $lines = [
        "import type { ElComponent } from './registry'",
        '',
        'const elComponents = ref<ElComponentMap>({',
        '  // 注释里的花括号 { 与 } 不参与解析',
    ];

    foreach ($types as $type) {
        $key     = preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $type) === 1 ? $type : "'{$type}'";
        $lines[] = "  {$key}: {";
        $lines[] = "    name: 'a-input',";
        $lines[] = '    props: {';
        $lines[] = '      allowClear: true,';
        $lines[] = '    },';
        $lines[] = '  },';
    }

    return implode("\n", $lines) . "\n})\n\nregisterWidgets(elComponents.value)\n";
}

/**
 * 造临时 SPA 仓根（约定位置 `apps/admin/src/components/former/config.ts`）。
 *
 * @param list<string> $types
 *
 * @return array{root: string, config: string}
 */
function formerTypesFixture(array $types): array
{
    $root = sys_get_temp_dir() . '/moo-former-' . bin2hex(random_bytes(4));
    $dir  = $root . '/apps/admin/src/components/former';
    mkdir($dir, 0777, true);
    file_put_contents($dir . '/config.ts', formerTypesConfigSource($types));

    $GLOBALS['mooFormerTypesFixtures'][] = $root;

    return ['root' => $root, 'config' => $dir . '/config.ts'];
}

it('命令：--spa 给仓根目录（约定位置推导）两侧一致 → 退出码 0', function () {
    $fixture = formerTypesFixture(FormWidgetTypes::FORMER);

    $code    = Artisan::call('moo:audit:former-types', ['--spa' => $fixture['root'], '--json' => true]);
    $payload = json_decode(Artisan::output(), true);

    expect($code)->toBe(0)
        ->and($payload['consistent'])->toBeTrue()
        ->and($payload['front_only'])->toBe([])
        ->and($payload['backend_only'])->toBe([])
        ->and($payload['aliases'])->toBe([])
        ->and($payload['backend']['count'])->toBe(count(FormWidgetTypes::FORMER))
        ->and($payload['frontend']['count'])->toBe(count(FormWidgetTypes::FORMER))
        ->and($payload['frontend']['file'])->toEndWith('former/config.ts');
});

it('命令：--spa 直接给 config.ts 文件也能判定（一致 → 0，文本模式说「一致」）', function () {
    $fixture = formerTypesFixture(FormWidgetTypes::FORMER);

    $code = Artisan::call('moo:audit:former-types', ['--spa' => $fixture['config']]);
    expect($code)->toBe(0);

    $output = Artisan::output();
    expect($output)->toContain('一致')->toContain('elComponents');
});

it('命令：前端多一个类型 → 退出码 1，输出里看得出是哪个', function () {
    $fixture = formerTypesFixture([...FormWidgetTypes::FORMER, 'signature-pad']);

    // JSON：差异落在 front_only
    Artisan::call('moo:audit:former-types', ['--spa' => $fixture['config'], '--json' => true]);
    $payload = json_decode(Artisan::output(), true);

    expect($payload['consistent'])->toBeFalse()
        ->and($payload['front_only'])->toBe(['signature-pad'])
        ->and($payload['backend_only'])->toBe([]);

    // 文本：不只有退出码，还指名道姓
    $code = Artisan::call('moo:audit:former-types', ['--spa' => $fixture['config']]);
    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('signature-pad')->toContain('不一致');
});

it('命令：后端多一个类型（前端缺一项）→ 退出码 1，差异落在 backend_only', function () {
    $missing = 'text-amount';
    $fixture = formerTypesFixture(array_values(array_diff(FormWidgetTypes::FORMER, [$missing])));

    Artisan::call('moo:audit:former-types', ['--spa' => $fixture['config'], '--json' => true]);
    $payload = json_decode(Artisan::output(), true);

    expect($payload['consistent'])->toBeFalse()
        ->and($payload['front_only'])->toBe([])
        ->and($payload['backend_only'])->toBe([$missing]);

    $code = Artisan::call('moo:audit:former-types', ['--spa' => $fixture['config']]);
    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain($missing)->toContain('只在后端');
});

it('命令：--spa 路径不存在 → 非 0 且报明确错误，不得说「一致」', function () {
    $missing = sys_get_temp_dir() . '/moo-former-absent-' . bin2hex(random_bytes(4));

    $code    = Artisan::call('moo:audit:former-types', ['--spa' => $missing, '--json' => true]);
    $payload = json_decode(Artisan::output(), true);

    expect($code)->not->toBe(0)
        ->and($code)->toBe(2)
        ->and($payload)->not->toHaveKey('consistent')
        ->and($payload['error'])->toBe('spa_path_not_found');

    Artisan::call('moo:audit:former-types', ['--spa' => $missing]);
    $output = Artisan::output();
    expect($output)->toContain('不存在')->not->toContain('一致');
});

it('命令：缺 --spa → 用法错误非 0；目录里没有 expected config.ts → 也非 0', function () {
    $code    = Artisan::call('moo:audit:former-types', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true);
    expect($code)->toBe(2)
        ->and($payload['error'])->toBe('usage');

    // 目录存在但没有 former/config.ts
    $root = sys_get_temp_dir() . '/moo-former-empty-' . bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    $GLOBALS['mooFormerTypesFixtures'][] = $root;

    $code    = Artisan::call('moo:audit:former-types', ['--spa' => $root, '--json' => true]);
    $payload = json_decode(Artisan::output(), true);
    expect($code)->toBe(2)
        ->and($payload['error'])->toBe('config_not_found');
});

it('命令：文件在但解析不到 elComponents → 报解析失败，不退化成「空清单 = 一致」', function () {
    $root = sys_get_temp_dir() . '/moo-former-bad-' . bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    $file = $root . '/config.ts';
    file_put_contents($file, "export default { input: { name: 'a-input' } }\n");
    $GLOBALS['mooFormerTypesFixtures'][] = $root;

    $code    = Artisan::call('moo:audit:former-types', ['--spa' => $file, '--json' => true]);
    $payload = json_decode(Artisan::output(), true);

    expect($code)->toBe(2)
        ->and($payload['error'])->toBe('config_unparsable');

    Artisan::call('moo:audit:former-types', ['--spa' => $file]);
    expect(Artisan::output())->toContain('解析不到')->not->toContain('一致');
});

it('命令：只比集合不比顺序（前端顺序打乱仍一致）', function () {
    $fixture = formerTypesFixture(array_reverse(FormWidgetTypes::FORMER));

    expect(Artisan::call('moo:audit:former-types', ['--spa' => $fixture['config']]))->toBe(0);
});

it('命令：--spa 相对路径按当前工作目录展开（不是 base_path / 仓根）', function () {
    // 造 <tmp>/<x>/spa/... 与 <tmp>/<x>/cwd/，CWD 切到 cwd 再传 `../spa/...`：
    // 只有「按 getcwd() 展开」能命中 —— 若改成按 base_path() 展开就会报 spa_path_not_found。
    $base = sys_get_temp_dir() . '/moo-former-rel-' . bin2hex(random_bytes(4));
    $dir  = $base . '/spa/apps/admin/src/components/former';
    mkdir($dir, 0777, true);
    file_put_contents($dir . '/config.ts', formerTypesConfigSource(FormWidgetTypes::FORMER));
    mkdir($base . '/cwd', 0777, true);
    $GLOBALS['mooFormerTypesFixtures'][] = $base;

    $prev = getcwd();
    chdir($base . '/cwd');

    try {
        $code = Artisan::call('moo:audit:former-types', [
            '--spa'  => '../spa/apps/admin/src/components/former/config.ts',
            '--json' => true,
        ]);
    } finally {
        chdir($prev);
    }

    expect($code)->toBe(0);
});
