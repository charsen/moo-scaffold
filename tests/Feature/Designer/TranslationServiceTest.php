<?php declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Mooeen\Scaffold\Designer\AiNotConfiguredException;
use Mooeen\Scaffold\Designer\AiUpstreamErrorException;
use Mooeen\Scaffold\Designer\TranslationService;

/**
 * TranslationService 回归测试 — 用 Http::fake 拦截 DeepSeek API,
 * 不真打外部服务。覆盖 callJson 3 个 parse fallback + validate*Response
 * 边界 case + assertConfigured。
 */
function svc(string $apiKey = 'sk-test'): TranslationService
{
    return new TranslationService(
        baseUrl: 'https://api.deepseek.com',
        apiKey: $apiKey,
        model: 'deepseek-chat',
        timeout: 30,
    );
}

function fakeChat(string $content): array
{
    return ['choices' => [['message' => ['content' => $content]]]];
}

beforeEach(function () {
    Http::preventStrayRequests();
});

// ─── assertConfigured ────────────────────────────────────────────

it('throws AiNotConfiguredException when apiKey empty', function () {
    Http::fake();
    expect(fn () => svc('')->translateFieldNames('demo_users', 'user', [], ['头像']))
        ->toThrow(AiNotConfiguredException::class);
});

// ─── callJson — parse fallback paths ─────────────────────────────

it('parses raw json content directly', function () {
    Http::fake([
        '*/chat/completions' => Http::response(fakeChat(json_encode([
            'results' => [['input' => '头像', 'output' => 'user_avatar', 'type' => 'varchar', 'size' => 64]],
        ])), 200),
    ]);
    $r = svc()->translateFieldNames('demo_users', 'user', [], ['头像']);
    expect($r['results'][0]['valid'])->toBeTrue();
    expect($r['results'][0]['output'])->toBe('user_avatar');
});

it('strips ```json ... ``` markdown fence and re-parses', function () {
    $fenced = "```json\n" . json_encode([
        'results' => [['input' => '头像', 'output' => 'user_avatar', 'type' => 'varchar', 'size' => 64]],
    ]) . "\n```";
    Http::fake(['*/chat/completions' => Http::response(fakeChat($fenced), 200)]);
    $r = svc()->translateFieldNames('demo_users', 'user', [], ['头像']);
    expect($r['results'][0]['valid'])->toBeTrue();
});

it('falls back to brace-block regex when content has explainer text', function () {
    $messy = "好的,请看结果:\n" . json_encode([
        'results' => [['input' => '头像', 'output' => 'user_avatar', 'type' => 'varchar', 'size' => 64]],
    ]) . "\n以上仅供参考";
    Http::fake(['*/chat/completions' => Http::response(fakeChat($messy), 200)]);
    $r = svc()->translateFieldNames('demo_users', 'user', [], ['头像']);
    expect($r['results'][0]['valid'])->toBeTrue();
});

it('throws AiUpstreamErrorException on unparseable content', function () {
    Http::fake(['*/chat/completions' => Http::response(fakeChat('hi there 没有 json'), 200)]);
    expect(fn () => svc()->translateFieldNames('demo_users', 'user', [], ['头像']))
        ->toThrow(AiUpstreamErrorException::class);
});

it('throws AiUpstreamErrorException on non-2xx response', function () {
    Http::fake(['*/chat/completions' => Http::response('rate limited', 429)]);
    expect(fn () => svc()->translateFieldNames('demo_users', 'user', [], ['头像']))
        ->toThrow(AiUpstreamErrorException::class);
});

it('throws AiUpstreamErrorException when results missing', function () {
    Http::fake(['*/chat/completions' => Http::response(fakeChat('{"other": []}'), 200)]);
    expect(fn () => svc()->translateFieldNames('demo_users', 'user', [], ['头像']))
        ->toThrow(AiUpstreamErrorException::class);
});

// ─── validateFieldsResponse boundary cases ──────────────────────

it('marks invalid when output format breaks snake_case regex', function () {
    Http::fake(['*/chat/completions' => Http::response(fakeChat(json_encode([
        'results' => [['input' => '头像', 'output' => 'UserAvatar']],     // PascalCase 非法
    ])), 200)]);
    $r = svc()->translateFieldNames('demo_users', 'user', [], ['头像']);
    expect($r['results'][0]['valid'])->toBeFalse();
    expect($r['results'][0]['reason'])->toContain('格式非法');
});

it('rejects output that contains underscore but does not match prefix', function () {
    Http::fake(['*/chat/completions' => Http::response(fakeChat(json_encode([
        'results' => [['input' => '头像', 'output' => 'avatar_thumb']],     // 有下划线但没 prefix_
    ])), 200)]);
    $r = svc()->translateFieldNames('demo_users', 'user', [], ['头像']);
    expect($r['results'][0]['valid'])->toBeFalse();
    expect($r['results'][0]['reason'])->toContain('未以 prefix');
});

it('auto-prepends prefix_ when output has no underscore', function () {
    Http::fake(['*/chat/completions' => Http::response(fakeChat(json_encode([
        'results' => [['input' => '头像', 'output' => 'avatar', 'type' => 'varchar', 'size' => 64]],
    ])), 200)]);
    $r = svc()->translateFieldNames('demo_users', 'user', [], ['头像']);
    expect($r['results'][0]['valid'])->toBeTrue();
    expect($r['results'][0]['output'])->toBe('user_avatar');
});

it('accepts prefix-prepended output up to 64 chars (cap raised 25 → 64)', function () {
    Http::fake(['*/chat/completions' => Http::response(fakeChat(json_encode([
        // long_prefix_longvarnameindeed = 29 字符:旧 25 cap 误拒(就是带 prefix 字段常翻车的场景),新 64 放行
        'results' => [['input' => '头像', 'output' => 'longvarnameindeed', 'type' => 'varchar', 'size' => 64]],
    ])), 200)]);
    $r = svc()->translateFieldNames('demo_users', 'long_prefix', [], ['头像']);
    expect($r['results'][0]['valid'])->toBeTrue();
    expect($r['results'][0]['output'])->toBe('long_prefix_longvarnameindeed');
});

it('still rejects when prefix-prepended output exceeds 64 chars', function () {
    $long = str_repeat('a', 55);     // 55 ≤ 64 过首检;拼 long_prefix_(12)后 = 67 > 64 → 拼前缀后超长
    Http::fake(['*/chat/completions' => Http::response(fakeChat(json_encode([
        'results' => [['input' => '头像', 'output' => $long, 'type' => 'varchar']],
    ])), 200)]);
    $r = svc()->translateFieldNames('demo_users', 'long_prefix', [], ['头像']);
    expect($r['results'][0]['valid'])->toBeFalse();
    expect($r['results'][0]['reason'])->toContain('超长');
});

it('rejects duplicate against existing fields list', function () {
    Http::fake(['*/chat/completions' => Http::response(fakeChat(json_encode([
        'results' => [['input' => '头像', 'output' => 'user_avatar']],
    ])), 200)]);
    $r = svc()->translateFieldNames('demo_users', 'user', ['user_avatar'], ['头像']);
    expect($r['results'][0]['valid'])->toBeFalse();
    expect($r['results'][0]['reason'])->toBe('重复字段名');
});

it('marks invalid when output is null with model abandon reason', function () {
    Http::fake(['*/chat/completions' => Http::response(fakeChat(json_encode([
        'results' => [['input' => '某品牌名', 'output' => null, 'reason' => '人名/品牌,模型放弃']],
    ])), 200)]);
    $r = svc()->translateFieldNames('demo_users', 'user', [], ['某品牌名']);
    expect($r['results'][0]['valid'])->toBeFalse();
    expect($r['results'][0]['reason'])->toBe('人名/品牌,模型放弃');
});

it('fallbacks type to varchar/64 when AI returns invalid type', function () {
    Http::fake(['*/chat/completions' => Http::response(fakeChat(json_encode([
        'results' => [['input' => '头像', 'output' => 'avatar', 'type' => 'unknown_type', 'size' => 999]],
    ])), 200)]);
    $r = svc()->translateFieldNames('demo_users', 'user', [], ['头像']);
    expect($r['results'][0]['valid'])->toBeTrue();
    expect($r['results'][0]['type'])->toBe('varchar');
    expect($r['results'][0]['size'])->toBe(64);
});

it('strips size to null on non-string types (int / bigint / datetime / json)', function () {
    Http::fake(['*/chat/completions' => Http::response(fakeChat(json_encode([
        'results' => [['input' => '年龄', 'output' => 'age', 'type' => 'int', 'size' => 99]],
    ])), 200)]);
    $r = svc()->translateFieldNames('demo_users', 'user', [], ['年龄']);
    expect($r['results'][0]['valid'])->toBeTrue();
    expect($r['results'][0]['type'])->toBe('int');
    expect($r['results'][0]['size'])->toBeNull();
});

it('pads missing results with "响应缺项" up to expectedCount', function () {
    Http::fake(['*/chat/completions' => Http::response(fakeChat(json_encode([
        'results' => [['input' => '头像', 'output' => 'avatar', 'type' => 'varchar', 'size' => 64]],
        // 只返 1 条,期望 3 条
    ])), 200)]);
    $r = svc()->translateFieldNames('demo_users', 'user', [], ['头像', '昵称', '电话']);
    expect($r['results'])->toHaveCount(3);
    expect($r['results'][0]['valid'])->toBeTrue();
    expect($r['results'][1]['valid'])->toBeFalse();
    expect($r['results'][1]['reason'])->toBe('响应缺项');
    expect($r['results'][2]['reason'])->toBe('响应缺项');
});

it('caps excess results to expectedCount', function () {
    Http::fake(['*/chat/completions' => Http::response(fakeChat(json_encode([
        'results' => [
            ['input' => 'A', 'output' => 'a', 'type' => 'varchar', 'size' => 64],
            ['input' => 'B', 'output' => 'b', 'type' => 'varchar', 'size' => 64],
            ['input' => 'C', 'output' => 'c', 'type' => 'varchar', 'size' => 64],
        ],
    ])), 200)]);
    $r = svc()->translateFieldNames('demo_users', 'user', [], ['头像']);
    expect($r['results'])->toHaveCount(1);
});

// ─── translateEnumKeys / validateEnumsResponse ──────────────────

it('translates enum keys with label_en validation', function () {
    Http::fake(['*/chat/completions' => Http::response(fakeChat(json_encode([
        'results' => [['input' => '男', 'output' => 'male', 'label_en' => 'Male']],
    ])), 200)]);
    $r = svc()->translateEnumKeys('gender', ['男']);
    expect($r['results'][0]['valid'])->toBeTrue();
    expect($r['results'][0]['output'])->toBe('male');
    expect($r['results'][0]['label_en'])->toBe('Male');
});

it('rejects enum label_en that does not start with uppercase', function () {
    Http::fake(['*/chat/completions' => Http::response(fakeChat(json_encode([
        'results' => [['input' => '男', 'output' => 'male', 'label_en' => 'male']],     // lowercase 不合法
    ])), 200)]);
    $r = svc()->translateEnumKeys('gender', ['男']);
    expect($r['results'][0]['valid'])->toBeFalse();
    expect($r['results'][0]['reason'])->toContain('label_en');
});

it('rejects duplicate enum output within a single group', function () {
    Http::fake(['*/chat/completions' => Http::response(fakeChat(json_encode([
        'results' => [
            ['input' => '男', 'output' => 'male',   'label_en' => 'Male'],
            ['input' => '男1', 'output' => 'male',  'label_en' => 'Male'],     // 同组重复
        ],
    ])), 200)]);
    $r = svc()->translateEnumKeys('gender', ['男', '男1']);
    expect($r['results'][0]['valid'])->toBeTrue();
    expect($r['results'][1]['valid'])->toBeFalse();
    expect($r['results'][1]['reason'])->toBe('同组重复');
});

// ─── translateTableShort ────────────────────────────────────────

it('returns valid table_short result', function () {
    Http::fake(['*/chat/completions' => Http::response(fakeChat(json_encode([
        'result' => 'order_users',
    ])), 200)]);
    expect(svc()->translateTableShort('Order', '订单用户'))->toBe('order_users');
});

it('throws AiUpstreamErrorException on table_short format mismatch', function () {
    Http::fake(['*/chat/completions' => Http::response(fakeChat(json_encode([
        'result' => 'OrderUsers',     // PascalCase 不合 snake_case 正则
    ])), 200)]);
    expect(fn () => svc()->translateTableShort('Order', '订单用户'))
        ->toThrow(AiUpstreamErrorException::class);
});

// ─── Round 2 P2 B-4:retry + exponential backoff regression ───────

it('retries on 5xx with exponential backoff and eventually succeeds', function () {
    // Http::sequence:前两次 5xx 错,第三次成功 — 验证 retry 真的发生
    Http::fake(['*/chat/completions' => Http::sequence()
        ->push('upstream temp fail', 500)
        ->push('upstream temp fail', 502)
        ->push(fakeChat(json_encode([
            'results' => [['input' => '头像', 'output' => 'user_avatar', 'type' => 'varchar', 'size' => 64]],
        ])), 200),
    ]);
    $r = svc()->translateFieldNames('demo_users', 'user', [], ['头像']);
    expect($r['results'][0]['valid'])->toBeTrue();
    expect($r['results'][0]['output'])->toBe('user_avatar');
    Http::assertSentCount(3);
});

it('does NOT retry on 4xx client error (4xx 是业务错,重试无意义还浪费 quota)', function () {
    Http::fake(['*/chat/completions' => Http::sequence()
        ->push('rate limited', 429)
        ->push(fakeChat(json_encode([
            'results' => [['input' => '头像', 'output' => 'user_avatar']],
        ])), 200),
    ]);
    expect(fn () => svc()->translateFieldNames('demo_users', 'user', [], ['头像']))
        ->toThrow(AiUpstreamErrorException::class);
    Http::assertSentCount(1);
});

// ─── 通用字段「通用:」标记 + 长度 25 cap ─────────────────────

it('「通用:」标记输入跳过 prefix 兜底 — AI 输出 role_id 直接保留(不加 user_)', function () {
    Http::fake(['*/chat/completions' => Http::response(fakeChat(json_encode([
        'results' => [['input' => '通用:角色 ID', 'output' => 'role_id', 'type' => 'bigint', 'size' => null]],
    ])), 200)]);
    $r = svc()->translateFieldNames('demo_users', 'user', [], ['通用:角色 ID']);
    expect($r['results'][0]['valid'])->toBeTrue();
    expect($r['results'][0]['output'])->toBe('role_id');         // 不是 user_role_id
    expect($r['results'][0]['comment'])->toBe('角色 ID');         // strip 「通用:」前缀
});

it('「通用:」+ AI 误加 prefix 时,后端 strip 掉(防御纵深)', function () {
    Http::fake(['*/chat/completions' => Http::response(fakeChat(json_encode([
        'results' => [['input' => '通用:创建人 ID', 'output' => 'user_creator_id', 'type' => 'bigint']],
    ])), 200)]);
    $r = svc()->translateFieldNames('demo_users', 'user', [], ['通用:创建人 ID']);
    expect($r['results'][0]['valid'])->toBeTrue();
    expect($r['results'][0]['output'])->toBe('creator_id');     // AI 误加的 user_ 被 strip
});

it('普通输入(无「通用:」标记)仍走原 prefix 兜底逻辑', function () {
    Http::fake(['*/chat/completions' => Http::response(fakeChat(json_encode([
        'results' => [['input' => '头像', 'output' => 'avatar', 'type' => 'varchar', 'size' => 64]],
    ])), 200)]);
    $r = svc()->translateFieldNames('demo_users', 'user', [], ['头像']);
    expect($r['results'][0]['valid'])->toBeTrue();
    expect($r['results'][0]['output'])->toBe('user_avatar');     // 兜底加 prefix
});

// 2026-05-20 真机暴露:user 表 prefix 含尾 `_`(yaml.attrs.prefix=op_)时
// → controller 入口未 strip → backend $prefix.'_' 拼成 op__ 双下划线
// → AI 合法输出 op_xxx 全被误判 invalid。修在 DesignerController::translate(2026-05-20).
// 这里覆盖 TranslationService 接收已 strip prefix 时的 happy path,锁定语义不退化
it('translate 接收 strip 过的 prefix(无尾 _),合法 AI 输出 prefix_xxx 通过', function () {
    Http::fake(['*/chat/completions' => Http::response(fakeChat(json_encode([
        'results' => [
            ['input' => '岗位 ID', 'output' => 'op_job_id', 'type' => 'bigint', 'size' => null],
            ['input' => '商品名', 'output' => 'op_product', 'type' => 'varchar', 'size' => 64],
        ],
    ])), 200)]);
    // prefix = 'op'(无尾 _)— 跟 controller strip 后传给 service 的形态一致
    $r = svc()->translateFieldNames('order_plans', 'op', [], ['岗位 ID', '商品名']);
    expect($r['results'][0]['valid'])->toBeTrue();
    expect($r['results'][0]['output'])->toBe('op_job_id');
    expect($r['results'][1]['valid'])->toBeTrue();
    expect($r['results'][1]['output'])->toBe('op_product');
});

it('「通用:」+ 64 字符边界 cap(上限 25 → 64)', function () {
    $ok64  = str_repeat('a', 64);
    $bad65 = str_repeat('a', 65);
    Http::fake(['*/chat/completions' => Http::response(fakeChat(json_encode([
        'results' => [
            ['input' => '通用:字段 a', 'output' => $ok64,  'type' => 'varchar', 'size' => 64],
            ['input' => '通用:字段 b', 'output' => $bad65, 'type' => 'varchar', 'size' => 64],
        ],
    ])), 200)]);
    $r = svc()->translateFieldNames('demo_users', 'user', [], ['通用:字段 a', '通用:字段 b']);
    expect($r['results'][0]['valid'])->toBeTrue();      // 64 字符通过(新上限 = DB 标识符上限)
    expect($r['results'][1]['valid'])->toBeFalse();     // 65 字符拒绝
    expect($r['results'][1]['reason'])->toContain('格式非法');
});
