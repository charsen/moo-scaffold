<?php declare(strict_types=1);

use Mooeen\Scaffold\Support\FormWidgetVisibility;

/*
 * 表单契约审计「可见性口径」判定单测 —— 纯函数，不依赖业务数据 / 数据库。
 * 覆盖三类可见性噪音（hidden / disabled / layout 外）+ 一类契约噪音
 * （rules 外、宿主 reset 附加的 `contract => false` 键）。
 */

test('layoutFields 兼容单字段、字段作键、一行多控件与混排，并去重', function () {
    $layout = [
        ['category_id'],
        ['contract_code' => ['span' => 12], 'contract_filing_no' => ['span' => 12]],
        ['contract_name'],
        ['category_id'],
        'ignored-row',
    ];

    expect(FormWidgetVisibility::layoutFields($layout))
        ->toBe(['category_id', 'contract_code', 'contract_filing_no', 'contract_name']);
});

test('layoutFields 对非数组行与空字段容错', function () {
    $layout = [null, ['', 'keep_id'], [0 => ['nested' => true]]];

    expect(FormWidgetVisibility::layoutFields($layout))->toBe(['keep_id']);
});

test('hidden 归入 hidden 桶', function () {
    $reason = FormWidgetVisibility::exclusionReason(['field' => 'amount_paid', 'hidden' => true], [], false);

    expect($reason)->toBe(FormWidgetVisibility::REASON_HIDDEN)
        ->and(FormWidgetVisibility::bucketOf($reason))->toBe('hidden');
});

test('disabled 归入 disabled 桶', function () {
    $reason = FormWidgetVisibility::exclusionReason(['field' => 'real_name', 'disabled' => true], [], false);

    expect($reason)->toBe(FormWidgetVisibility::REASON_DISABLED);
});

test('hidden 优先于 disabled，同一控件只归一个桶', function () {
    $reason = FormWidgetVisibility::exclusionReason(
        ['field' => 'x', 'hidden' => true, 'disabled' => true],
        ['x'],
        true,
    );

    expect($reason)->toBe(FormWidgetVisibility::REASON_HIDDEN);
});

test('定义了 formLayout 时，layout 外控件归入 layout 桶', function () {
    $layoutFields = FormWidgetVisibility::layoutFields([['category_id'], ['contract_name']]);

    $inLayout  = FormWidgetVisibility::exclusionReason(['field' => 'category_id'], $layoutFields, true);
    $outLayout = FormWidgetVisibility::exclusionReason(['field' => 'amount_paid'], $layoutFields, true);

    expect($inLayout)->toBeNull()
        ->and($outLayout)->toBe(FormWidgetVisibility::REASON_LAYOUT);
});

test('未定义 formLayout 时不做 layout 判定 —— 幽灵键仍算可见', function () {
    $reason = FormWidgetVisibility::exclusionReason(['field' => 'amount_paid'], [], false);

    expect($reason)->toBeNull()
        ->and(FormWidgetVisibility::bucketOf($reason))->toBe('visible');
});

test('exclusionReason 对 false 的 hidden/disabled 不当成排除', function () {
    $reason = FormWidgetVisibility::exclusionReason(
        ['field' => 'x', 'hidden' => false, 'disabled' => false],
        ['x'],
        true,
    );

    expect($reason)->toBeNull();
});

test('isContractParticipant：只有 contract === false 才是契约外附加键', function () {
    expect(FormWidgetVisibility::isContractParticipant(['field' => 'a']))->toBeTrue()
        ->and(FormWidgetVisibility::isContractParticipant(['field' => 'a', 'contract' => true]))->toBeTrue()
        // 弱等值不算：必须严格 false
        ->and(FormWidgetVisibility::isContractParticipant(['field' => 'a', 'contract' => 0]))->toBeTrue()
        ->and(FormWidgetVisibility::isContractParticipant(['field' => 'a', 'contract' => false]))->toBeFalse();
});

test('shouldExclude：默认（只报可见）剔除三口径与契约外噪音', function () {
    expect(FormWidgetVisibility::shouldExclude(['field' => 'a', 'hidden' => true], 'hidden', true, true, true, true))->toBeTrue()
        ->and(FormWidgetVisibility::shouldExclude(['field' => 'a', 'disabled' => true], 'disabled', true, true, true, true))->toBeTrue()
        ->and(FormWidgetVisibility::shouldExclude(['field' => 'a'], 'layout', true, true, true, true))->toBeTrue()
        ->and(FormWidgetVisibility::shouldExclude(['field' => 'a', 'contract' => false, 'hidden' => true], 'hidden', true, true, true, true))->toBeTrue()
        ->and(FormWidgetVisibility::shouldExclude(['field' => 'a'], null, true, true, true, true))->toBeFalse();
});

test('shouldExclude：用户可见的 contract=false 附加键仍是真实违规（标记不豁免）', function () {
    $visibleExtra = ['field' => 'property_area_id', 'contract' => false];

    // 默认口径、--include-non-contract、--all 下都计入
    expect(FormWidgetVisibility::shouldExclude($visibleExtra, null, true, true, true, true))->toBeFalse()
        ->and(FormWidgetVisibility::shouldExclude($visibleExtra, null, true, true, true, false))->toBeFalse()
        ->and(FormWidgetVisibility::shouldExclude($visibleExtra, null, false, false, false, false))->toBeFalse();
});

test('shouldExclude：不可见的 contract=false 附加键要 --include-non-contract/--all 才计入', function () {
    $hiddenExtra = ['field' => 'amount_paid', 'hidden' => true, 'contract' => false];

    // 默认剔除
    expect(FormWidgetVisibility::shouldExclude($hiddenExtra, 'hidden', true, true, true, true))->toBeTrue();
    // 只放开 --include-hidden：仍是契约外噪音，剔除
    expect(FormWidgetVisibility::shouldExclude($hiddenExtra, 'hidden', false, true, true, true))->toBeTrue();
    // 只放开 --include-non-contract：可见性口径仍关，剔除
    expect(FormWidgetVisibility::shouldExclude($hiddenExtra, 'hidden', true, true, true, false))->toBeTrue();
    // --all：两项全放开 → 计入
    expect(FormWidgetVisibility::shouldExclude($hiddenExtra, 'hidden', false, false, false, false))->toBeFalse();
});

test('shouldExclude：各自的口径只放开自己那一项（非契约外条目）', function () {
    expect(FormWidgetVisibility::shouldExclude(['field' => 'a', 'hidden' => true], 'hidden', false, true, true, true))->toBeFalse()
        ->and(FormWidgetVisibility::shouldExclude(['field' => 'a', 'disabled' => true], 'disabled', false, true, true, true))->toBeTrue()
        ->and(FormWidgetVisibility::shouldExclude(['field' => 'a'], 'layout', true, true, false, true))->toBeFalse()
        ->and(FormWidgetVisibility::shouldExclude(['field' => 'a', 'disabled' => true], 'disabled', false, false, false, false))->toBeFalse();
});

test('summaryLine 始终带分桶明细（含 waived），缺失桶按 0 计', function () {
    expect(FormWidgetVisibility::summaryLine(4, ['visible' => 4, 'hidden' => 18, 'waived' => 1]))
        ->toBe('Contract violations: 4 (visible 4, hidden 18, disabled 0, layout-only 0, waived 1)');

    expect(FormWidgetVisibility::summaryLine(0, []))
        ->toBe('Contract violations: 0 (visible 0, hidden 0, disabled 0, layout-only 0, waived 0)');
});

test('复刻 MyContract/create 的 5 个 hidden 契约外键：默认全剔除但归 hidden 桶', function () {
    $widgets = [];
    foreach (['amount_paid', 'amount_not_paid', 'amount_received', 'amount_not_received', 'keep_department_id'] as $field) {
        $widgets[] = ['field' => $field, 'hidden' => true, 'contract' => false];
    }

    $buckets = ['visible' => 0, 'hidden' => 0, 'disabled' => 0, 'layout' => 0];
    $counted = 0;
    foreach ($widgets as $widget) {
        $reason = FormWidgetVisibility::exclusionReason($widget, [], false);
        $buckets[FormWidgetVisibility::bucketOf($reason)]++;

        if (FormWidgetVisibility::shouldExclude($widget, $reason, true, true, true, true)) {
            continue;
        }
        $counted++;
    }

    expect($counted)->toBe(0)
        ->and($buckets)->toBe(['visible' => 0, 'hidden' => 5, 'disabled' => 0, 'layout' => 0]);

    // 放开口径后仍可见（信息未丢）：--all 等价四项全放开
    $all = 0;
    foreach ($widgets as $widget) {
        if (! FormWidgetVisibility::shouldExclude($widget, 'hidden', false, false, false, false)) {
            $all++;
        }
    }
    expect($all)->toBe(5);
});
