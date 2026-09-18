<?php

declare(strict_types=1);

use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Mooeen\Scaffold\Support\ConsoleUi;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * ConsoleUi 的「唯一出口」契约锁。
 *
 * ConsoleUi 承担两种职责，本文件分别钉住：
 *   ① 原样输出 line() / newLine() / table() —— 只路由不加工，且对**三种目标**都成立
 *      （ConsoleCommand / Factory / OutputInterface）。三种目标都是活的：命令走 Command、
 *      AdderTest 走 Factory、DesignerController 的 web context 与 RouterTool 走 OutputInterface。
 *   ② 语义级输出的前导标记**恰好一个** —— 消息自带同款标记时先去重，且只认「前导 + 本级」。
 *
 * 真实 Command 目标那两个分支不在这里覆盖（需起完整 artisan kernel）：
 *   line()  → tests/Feature/Command/ComposerDocsCommandTest.php
 *   table() → tests/Feature/Command/AuditFormerTypesCommandTest.php、AuditResourceKeysCommandTest.php
 */

/** @return array{0: ConsoleUi, 1: BufferedOutput} */
function console_ui_buffered(): array
{
    $buffer = new BufferedOutput;

    return [new ConsoleUi($buffer), $buffer];
}

/** @return array{0: ConsoleUi, 1: BufferedOutput} */
function console_ui_factory(): array
{
    $buffer  = new BufferedOutput;
    $factory = new Factory(new OutputStyle(new ArrayInput([]), $buffer));

    return [new ConsoleUi($factory), $buffer];
}

// ─── 原样输出：只路由不加工 ──────────────────────────────────────────────────

it('line()：OutputInterface 目标原样写出，不加包装', function () {
    [$ui, $buffer] = console_ui_buffered();

    $ui->line('  <fg=gray>提示</>');

    expect($buffer->fetch())->toBe("  提示\n");
});

it('line()：Factory 目标不套组件包装', function () {
    // 为什么钉这个：`Factory::line()` 的参数顺序是 (style, string) 且会套一层 `<info>`，
    // 消息里自带的 `</>` 会把样式提前弹回外层 —— 所以 Factory 目标必须直写底层输出。
    [$ui, $buffer] = console_ui_factory();

    $ui->line('<fg=gray>灰</>＋<fg=red>红</>');

    expect($buffer->fetch())->toBe("灰＋红\n");
});

it('line()：显式给 style 才包装（语义同 Command::line()）', function () {
    [$ui, $buffer] = console_ui_buffered();

    $ui->line('正文', 'info');

    expect($buffer->fetch())->toBe("正文\n");
});

it('newLine()：按计数写空行', function () {
    [$ui, $buffer] = console_ui_buffered();

    $ui->newLine(2);

    expect($buffer->fetch())->toBe("\n\n");
});

it('table()：非 Command 目标用 Symfony 兜底，表头与内容都在', function () {
    [$ui, $buffer] = console_ui_buffered();

    $ui->table(['侧', '数量'], [['后端', '12']]);

    expect($buffer->fetch())->toContain('侧')->toContain('数量')->toContain('后端')->toContain('12');
});

it('table()：Factory 目标同样可用（组件目录没有 Table 组件，只能兜底）', function () {
    [$ui, $buffer] = console_ui_factory();

    $ui->table(['列'], [['值']]);

    expect($buffer->fetch())->toContain('列')->toContain('值');
});

// ─── 前导标记：恰好一个 ──────────────────────────────────────────────────────

it('warn()：消息自带 ⚠️ / ⚠ 时先去重，渲染恰好一个警告标记', function (string $prefix) {
    // 真实场景：SnapshotStore::baselineNote() 按契约返回 '⚠ …'（web 端也直接展示），
    // 命令层再经 warn() 就会双写成 '⚠️  ⚠ …'。
    [$ui, $buffer] = console_ui_buffered();

    $ui->warn($prefix . 'baseline 未推进');

    $out = $buffer->fetch();

    expect($out)->toContain('baseline 未推进')
        ->and(mb_substr_count($out, '⚠'))->toBe(1);
})->with(['⚠️ ', '⚠ ']);

it('warn()：只认前导标记 —— 消息内部的 ⚠ 不动', function () {
    [$ui, $buffer] = console_ui_buffered();

    $ui->warn('重启前确认 ⚠ 这一条');

    $out = $buffer->fetch();

    expect(mb_substr_count($out, '⚠'))->toBe(2)          // 本级标记 + 消息内部那一个
        ->and($out)->toContain('重启前确认 ⚠ 这一条');
});

it('info()：不吞消息里的 ✓（那是作者有意表达「通过」，不是 info 的标记）', function () {
    [$ui, $buffer] = console_ui_buffered();

    $ui->info('✓ 没有发现危险列');

    $out = $buffer->fetch();

    expect($out)->toContain('✓ 没有发现危险列')
        ->and($out)->toContain('ℹ');
});

it('success() / error()：同样去重自带的 ✅ / ❌', function () {
    [$ok, $okBuf] = console_ui_buffered();
    $ok->success('✅ 全部一致');
    expect(mb_substr_count($okBuf->fetch(), '✅'))->toBe(1);

    [$bad, $badBuf] = console_ui_buffered();
    $bad->error('❌ 失败');
    expect(mb_substr_count($badBuf->fetch(), '❌'))->toBe(1);
});
