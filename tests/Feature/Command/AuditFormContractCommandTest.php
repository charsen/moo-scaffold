<?php declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/*
 * `moo:audit:form-contract` 命令测试 —— 用包内最小 fixture 工程（不依赖真实 Host / 数据库），
 * 覆盖可见性归类、@moo-waived 豁免、陈旧标记、分桶摘要、CSV 列集与默认/放开口径的退出码。
 *
 * fixture 控制器/Request 位于 tests/Feature/Command/Fixtures/FormContract/，命名空间由 composer
 * 的 `Mooeen\Scaffold\Tests\` PSR-4 前缀承载，命令按 --scope 绝对路径反推出 `...\App\Admin\Controllers`。
 */

function formContractScope(): string
{
    return __DIR__ . '/Fixtures/FormContract/App/Admin/Controllers';
}

/** @return array<int, array<string, string>> */
function formContractCsv(string $path): array
{
    $rows   = [];
    $handle = fopen($path, 'r');
    $header = fgetcsv($handle);
    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = array_combine($header, $row);
    }
    fclose($handle);

    return $rows;
}

/** @param array<int, array<string, string>> $rows */
function formContractFind(array $rows, string $field, string $method): array
{
    foreach ($rows as $row) {
        if ($row['Field'] === $field && $row['Method'] === $method) {
            return $row;
        }
    }

    throw new RuntimeException("fixture row not found: {$field}/{$method}");
}

beforeEach(function () {
    $this->tmp = sys_get_temp_dir() . '/moo-form-contract-' . bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->tmp);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

test('默认口径只报用户可见违规：hidden/disabled 噪音与 waived 豁免都不计入，CSV 沿用既有列集', function () {
    $csv = $this->tmp . '/default.csv';

    $this->artisan('moo:audit:form-contract', ['--scope' => formContractScope(), '--out' => $csv])
        ->expectsOutputToContain('Contract violations: 4 (visible 4, hidden 2, disabled 2, layout-only 0, waived 2)')
        ->expectsOutputToContain('Waived form fields: 2')
        ->expectsOutputToContain('legacy_field = 早期精简：nullable 字段暂不实现')
        // 陈旧标记不能被静默忽略
        ->expectsOutputToContain('Stale waived markers: 4')
        ->expectsOutputToContain('removed_field')
        ->assertExitCode(1);

    $rows = formContractCsv($csv);
    expect($rows)->toHaveCount(10);
    expect(array_keys($rows[0]))
        ->toBe(['Module', 'Controller', 'Method', 'Request', 'Field', 'WidgetCount', 'Note', 'Visible', 'ExcludedBy']);

    // 可见的 rules 外附加键 = 真实违规（用户填得进、提交被丢）
    $visible = formContractFind($rows, 'ghost_visible', 'create');
    expect($visible['Visible'])->toBe('yes')
        ->and($visible['ExcludedBy'])->toBe('')
        ->and($visible['Request'])->toBe('StoreRequest')
        ->and($visible['Note'])->toContain('[contract=false');

    // hidden / disabled 噪音：登记但标为排除
    expect(formContractFind($rows, 'ghost_hidden', 'create')['ExcludedBy'])->toBe('hidden')
        ->and(formContractFind($rows, 'ghost_hidden', 'create')['Visible'])->toBe('no')
        ->and(formContractFind($rows, 'ghost_disabled', 'edit')['ExcludedBy'])->toBe('disabled');

    // 显式 waived：独立桶 + 原因，未计入违规
    $waived = formContractFind($rows, 'legacy_field', 'create');
    expect($waived['ExcludedBy'])->toBe('waived')
        ->and($waived['Visible'])->toBe('no')
        ->and($waived['Note'])->toContain('[waived: 早期精简：nullable 字段暂不实现]');

    // rules 内字段（name）不登记为检出项
    foreach ($rows as $row) {
        expect($row['Field'])->not->toBe('name');
    }
});

test('反例：规则被整行注释但未加 @moo-waived 标记的字段仍报可见违规', function () {
    $csv = $this->tmp . '/commented.csv';

    $this->artisan('moo:audit:form-contract', [
        '--scope'  => formContractScope(),
        '--module' => 'Commented',
        '--out'    => $csv,
    ])
        ->expectsOutputToContain('Contract violations: 2 (visible 2, hidden 0, disabled 0, layout-only 0, waived 0)')
        ->assertExitCode(1);

    $rows = formContractCsv($csv);
    expect($rows)->toHaveCount(2);
    foreach ($rows as $row) {
        expect($row['Field'])->toBe('commented_field')
            ->and($row['Visible'])->toBe('yes')
            ->and($row['ExcludedBy'])->toBe('')
            ->and($row['Note'])->not->toContain('[waived:');
    }
});

test('陈旧标记：标记仍在但当期不构成违规时以 warning 报出，不静默忽略', function () {
    $csv = $this->tmp . '/stale.csv';

    $this->artisan('moo:audit:form-contract', [
        '--scope'  => formContractScope(),
        '--module' => 'Stale',
        '--out'    => $csv,
    ])
        ->expectsOutputToContain('Stale waived markers: 4')
        ->expectsOutputToContain('removed_field: 控件已移除，标记应清理')
        ->expectsOutputToContain('restored_field: 规则已补回，标记应清理')
        ->expectsOutputToContain('Contract violations: 0 (visible 0, hidden 0, disabled 0, layout-only 0, waived 0)')
        ->assertExitCode(0);

    expect(formContractCsv($csv))->toHaveCount(0);
});

test('--all 放开口径后 hidden/disabled 计入（waived 始终不计），CSV 与默认口径逐字节一致', function () {
    $defaultCsv = $this->tmp . '/default.csv';
    $allCsv     = $this->tmp . '/all.csv';

    $this->artisan('moo:audit:form-contract', ['--scope' => formContractScope(), '--out' => $defaultCsv])
        ->assertExitCode(1);

    $this->artisan('moo:audit:form-contract', ['--scope' => formContractScope(), '--out' => $allCsv, '--all' => true])
        ->expectsOutputToContain('Contract violations: 8 (visible 4, hidden 2, disabled 2, layout-only 0, waived 2)')
        ->assertExitCode(1);

    expect(file_get_contents($allCsv))->toBe(file_get_contents($defaultCsv));
});

test('--include-waived 展开逐条明细且不改变计数（waived 不影响退出码）', function () {
    $csv = $this->tmp . '/include-waived.csv';

    $this->artisan('moo:audit:form-contract', [
        '--scope'          => formContractScope(),
        '--out'            => $csv,
        '--include-waived' => true,
    ])
        ->expectsOutputToContain('Waived form fields: 2')
        ->expectsOutputToContain('Waived/Waived.create legacy_field: 早期精简：nullable 字段暂不实现')
        ->expectsOutputToContain('Contract violations: 4 (visible 4, hidden 2, disabled 2, layout-only 0, waived 2)')
        ->assertExitCode(1);
});

test('--include-hidden 单独放开不会展开契约外 hidden 键（still contract-gated）', function () {
    $csv = $this->tmp . '/include-hidden.csv';

    $this->artisan('moo:audit:form-contract', [
        '--scope'          => formContractScope(),
        '--out'            => $csv,
        '--include-hidden' => true,
    ])
        ->expectsOutputToContain('Contract violations: 4 (visible 4, hidden 2, disabled 2, layout-only 0, waived 2)')
        ->assertExitCode(1);
});

test('--module 只审计指定模块；纯 waived 模块默认退出码 0', function () {
    $csv = $this->tmp . '/waived-only.csv';

    $this->artisan('moo:audit:form-contract', [
        '--scope'  => formContractScope(),
        '--module' => 'Waived',
        '--out'    => $csv,
    ])
        ->expectsOutputToContain('Contract violations: 0 (visible 0, hidden 0, disabled 0, layout-only 0, waived 2)')
        ->assertExitCode(0);

    $rows = formContractCsv($csv);
    expect($rows)->toHaveCount(2);
    foreach ($rows as $row) {
        expect($row['Module'])->toBe('Waived')
            ->and($row['ExcludedBy'])->toBe('waived');
    }
});

test('layout 内表单的 rules 外附加键被 transformLayout 丢弃，命令不误报', function () {
    $csv = $this->tmp . '/layout.csv';

    $this->artisan('moo:audit:form-contract', [
        '--scope'  => formContractScope(),
        '--module' => 'Layout',
        '--out'    => $csv,
    ])
        ->expectsOutputToContain('Contract violations: 0 (visible 0, hidden 0, disabled 0, layout-only 0, waived 0)')
        ->assertExitCode(0);

    expect(formContractCsv($csv))->toHaveCount(0);
});

// ─── 寿命口径与编排结构（2026-09-18 第 12 项）────────────────────────

test('命令实例是 per-process：同一进程内重复调用不得累积 CSV 行（$rows 必须在 handle() 开头清空）', function () {
    // 与控制器相反：命令**不是**每请求新建 —— 同一进程内多次 Artisan::call 复用同一个实例
    // （Laravel 把命令注册进 Artisan 应用，实例留在容器里）。所以任何实例状态都必须每次
    // handle() 重置，否则上一轮的检出结果会接着累。
    // 这条会把「把 $rows = [] 那行删掉」变成可观测的失败：第 2 轮的 CSV / Checked 计数翻倍。
    $first  = $this->tmp . '/repeat-1.csv';
    $second = $this->tmp . '/repeat-2.csv';

    Artisan::call('moo:audit:form-contract', ['--scope' => formContractScope(), '--out' => $first]);
    $run1 = Artisan::output();

    Artisan::call('moo:audit:form-contract', ['--scope' => formContractScope(), '--out' => $second]);
    $run2 = Artisan::output();

    expect(formContractCsv($first))->not->toBeEmpty('前置：第一轮就该有检出项，否则这条测不出累积');

    expect(formContractCsv($second))->toHaveCount(count(formContractCsv($first)))
        // 摘要是同一条流水线算出来的，累积同样会让它翻倍 —— 顺带守住 $rows 之外的实例状态
        ->and(preg_match('/Checked \d+ form paths, \d+ skipped\./', $run1, $m1))->toBe(1)
        ->and(preg_match('/Checked \d+ form paths, \d+ skipped\./', $run2, $m2))->toBe(1)
        ->and($m2[0])->toBe($m1[0]);
});

test('handle() 只做编排：五段各自成私有方法，handle() 本体不得再长回去', function () {
    // 拆之前 handle() 是 278 行单方法（选项解析 / 152 行主循环 / 摘要全挤在一起），
    // 想知道「哪些开关影响计数」「一行 CSV 怎么来的」都得从里面刨。现拆成
    // 解析 → 口径 → 执行 → 落账 → 输出 五段，handle() 只留编排。
    // 用反射量长度而不是扫源码：精确、不依赖缩进，报错自带方法名。
    $rc = new ReflectionClass(\Mooeen\Scaffold\Command\AuditFormContractCommand::class);

    foreach ([
        'resolveNamespace',
        'resolveControllerFiles',
        'visibilityScopes',
        'emptyTotals',
        'inspectController',
        'inspectFormPath',
        'dropForgotten',
        'staleWaivedMarkers',
        'recordFindings',
        'reportFindings',
    ] as $method) {
        expect($rc->hasMethod($method))->toBeTrue("编排阶段 {$method}() 缺失 —— 是不是又被合回 handle() 了？")
            ->and($rc->getMethod($method)->isPrivate())->toBeTrue("{$method}() 应是私有实现细节");
    }

    $handle = $rc->getMethod('handle');
    $lines  = $handle->getEndLine() - $handle->getStartLine() + 1;

    // 阈值不是圣数：只表达「编排层不该超过一屏」。拆分前 278 行，现在 36 行。
    expect($lines)->toBeLessThanOrEqual(45, "handle() 又长到 {$lines} 行 —— 新增逻辑应落到对应阶段的私有方法里");
});
