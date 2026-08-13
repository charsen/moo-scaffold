<?php declare(strict_types=1);

/**
 * controller stub 用到的 XxxRequest token 必须跟 config.controller.<app>.requests
 * 一一对齐。任何一边漂移（stub 新增 action 引用 NewRequest，但 config 没加；或反之）
 * → 生成出的 controller 找不到 Request 类 / 生成多余无引用 Request 文件 → 路由解析 500。
 *
 * 2026-05-21 立此 guardrail，缘起 Memo 表生成漏 EditRequest（config 缺 'edit'）。
 */
function extractRequestTokens(string $stubPath): array
{
    preg_match_all('/([A-Z][a-zA-Z]+)Request\s+\$request/', file_get_contents($stubPath), $m);

    return array_values(array_unique($m[1])); // ['Index','Store','Update',...]
}

function configRequests(string $app): array
{
    $cfg = require __DIR__ . '/../../../config/config.php';

    return array_map('ucfirst', $cfg['controller'][$app]['requests']);
}

it('controller-admin.stub 的 XxxRequest 全部覆盖于 config admin.requests', function () {
    $stubTokens = extractRequestTokens(__DIR__ . '/../../../stubs/controller-admin.stub');
    $configReqs = configRequests('admin');

    $missing = array_diff($stubTokens, $configReqs);
    expect($missing)->toBe(
        [],
        '以下 Request 在 controller-admin.stub 里出现，但 config admin.requests 缺：' . implode(', ', $missing)
    );

    $orphaned = array_diff($configReqs, $stubTokens);
    expect($orphaned)->toBe(
        [],
        '以下 Request 在 config admin.requests 里配了，但 controller-admin.stub 没引用（会生成无用 Request 文件）：' . implode(', ', $orphaned)
    );
});

it('每个默认应用端的显式 controller stub 与 requests 对齐', function () {
    $cfg = require __DIR__ . '/../../../config/config.php';

    foreach ($cfg['controller'] as $app => $target) {
        $stubTokens = extractRequestTokens(__DIR__ . '/../../../stubs/' . $target['stub'] . '.stub');
        $configReqs = configRequests($app);

        expect(array_diff($stubTokens, $configReqs))->toBe(
            [],
            "应用端 {$app} 的 controller stub 引用了未配置的 Request。"
        );
        expect(array_diff($configReqs, $stubTokens))->toBe(
            [],
            "应用端 {$app} 配置了 controller stub 未使用的 Request。"
        );
    }
});
