<?php declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Http;
use Mooeen\Scaffold\Designer\TranslationService;
use Mooeen\Scaffold\Http\Middleware\EnforceScaffoldWritable;
use Mooeen\Scaffold\Http\Middleware\ScaffoldAuthenticate;
use Mooeen\Scaffold\Tests\Feature\Designer\Support\FixtureSchema;

/**
 * DesignerController HTTP feature test。绕 ScaffoldAuthenticate / VerifyCsrfToken /
 * EnforceScaffoldWritable 中间件(测试只关心 controller 自身行为),只测 readonly +
 * 校验路径,不真写 yaml(避免污染 production fixture)。
 *
 * 真写路径(save / createSchema / createTable / deleteTable / migrate)已经在
 * Playwright e2e 测过端到端 round-trip。
 */
beforeEach(function () {
    $this->withoutMiddleware([
        ScaffoldAuthenticate::class,
        VerifyCsrfToken::class,
        EnforceScaffoldWritable::class,
    ]);
});

// ─── GET endpoints(readonly) ──────────────────────────────────

it('GET /scaffold/db/designer renders index view with 200', function () {
    $r = $this->get('/scaffold/db/designer');
    $r->assertOk();
});

it('GET /scaffold/db/designer/{schema} renders schema page with 200', function () {
    $orig = FixtureSchema::activate(app());
    try {
        $r = $this->get('/scaffold/db/designer/' . FixtureSchema::SCHEMA);
        $r->assertOk();
    } finally {
        FixtureSchema::deactivate(app(), $orig);
    }
});

it('字段表带 简洁/完整列 切换按钮(精度/format 默认按本表用量显隐,2026-06-11)', function () {
    $orig = FixtureSchema::activate(app());
    try {
        $r = $this->get('/scaffold/db/designer/' . FixtureSchema::SCHEMA . '?table=' . FixtureSchema::TABLE);
        $r->assertOk();
        $r->assertSee('toggleAdvancedCols');                 // 切换按钮(行内可编辑之后)
        $r->assertSee('x-show="showAdvancedCols"', false);   // 精度/format 列挂显隐绑定
    } finally {
        FixtureSchema::deactivate(app(), $orig);
    }
});

it('GET /scaffold/db/designer/{schema}/preview returns JSON with full shape', function () {
    $orig = FixtureSchema::activate(app());
    try {
        $r = $this->get('/scaffold/db/designer/' . FixtureSchema::SCHEMA . '/preview');
        $r->assertOk();
        $r->assertJsonStructure([
            'data' => [
                'schema',
                'is_empty',
                'summary' => ['tables_changed', 'tables_created', 'tables_dropped'],
                'tables',
            ],
        ]);
        expect($r->json('data.schema'))->toBe(FixtureSchema::SCHEMA);
        expect($r->json('data.is_empty'))->toBeBool();
        // plan 36 砍 parser_warnings 字段
        expect($r->json('data'))->not->toHaveKey('parser_warnings');
        // plan 39 砍 commit_message 字段
        expect($r->json('data'))->not->toHaveKey('commit_message');
    } finally {
        FixtureSchema::deactivate(app(), $orig);
    }
});

it('GET preview reports is_empty=true on schema with fresh snapshot (plan 36 / P1-1 fixture)', function () {
    // plan-37 P1-1:用 fixture schema 隔绝 production yaml,baseline ↔ source 字节对齐
    $orig = FixtureSchema::activate(app());
    try {
        $r = $this->get('/scaffold/db/designer/' . FixtureSchema::SCHEMA . '/preview');
        $r->assertOk();
        expect($r->json('data.is_empty'))->toBeTrue();
    } finally {
        FixtureSchema::deactivate(app(), $orig);
    }
});

it('GET migration-content returns 400 on missing filename', function () {
    $r = $this->get('/scaffold/db/designer/Platform/migration-content');
    $r->assertStatus(400);
    expect($r->json('error.code'))->toBe('INVALID_FILENAME');
});

it('GET migration-content rejects path traversal', function () {
    $r = $this->get('/scaffold/db/designer/Platform/migration-content?file=../../etc/passwd');
    $r->assertStatus(400);
});

it('GET migration-content returns 404 on non-existent file', function () {
    $orig = FixtureSchema::activate(app());
    try {
        $r = $this->get('/scaffold/db/designer/' . FixtureSchema::SCHEMA . '/migration-content?file=does_not_exist_xyz.php');
        $r->assertStatus(404);
        expect($r->json('error.code'))->toBe('NOT_FOUND');
    } finally {
        FixtureSchema::deactivate(app(), $orig);
    }
});

it('GET migration-content returns php_code for real migration file', function () {
    // 需要该 schema 真实 migration 文件;bundled fixture 不带 migrations,testbench 也无 →
    // 无文件时 graceful skip(php_code happy-path 由 designer.spec.ts e2e 覆盖),不让套件失败。
    $dir   = database_path('migrations');
    $first = is_dir($dir)
        ? collect(scandir($dir))->first(fn ($f) => str_ends_with($f, '.php') && str_contains($f, '_demo_') && ! is_dir("{$dir}/{$f}"))
        : null;
    if ($first === null) {
        $this->markTestSkipped('no Demo migration file in test env — php_code happy-path covered by e2e');
    }
    $orig = FixtureSchema::activate(app());
    try {
        $r = $this->get('/scaffold/db/designer/' . FixtureSchema::SCHEMA . "/migration-content?file={$first}");
        $r->assertOk();
        expect($r->json('data.filename'))->toBe($first);
        expect($r->json('data.php_code'))->toBeString()->toContain('<?php');
    } finally {
        FixtureSchema::deactivate(app(), $orig);
    }
});

it('GET migration-content rejects file not belonging to schema (plan-37 P1)', function () {
    // 拿 Order 的 migration 试图通过 Platform endpoint 读 → 应 404
    $dir       = database_path('migrations');
    $orderFile = collect(scandir($dir))
        ->first(fn ($f) => str_ends_with($f, '.php') && str_contains($f, '_order_') && ! is_dir("{$dir}/{$f}"));
    if ($orderFile === null) {
        $this->markTestSkipped('no order_* migration to test cross-schema rejection');
    }
    $r = $this->get("/scaffold/db/designer/Platform/migration-content?file={$orderFile}");
    $r->assertStatus(404);
    expect($r->json('error.code'))->toBe('NOT_FOUND');
});

// ─── POST translate(用 Http::fake 拦 DeepSeek)─────────────

it('POST /scaffold/db/designer/translate proxies DeepSeek + returns valid results', function () {
    // TranslationService 从 AiSettingStore 读配置,test 时直接 instance 替换成有 key 的 service
    // (DeepSeek HTTP 仍走 Http::fake 拦截)。显式传非默认 temperature / maxTokens 验证参数透传到请求体。
    app()->instance(TranslationService::class, new TranslationService(
        baseUrl: 'https://api.deepseek.com',
        apiKey: 'sk-test',
        model: 'deepseek-chat',
        timeout: 30,
        temperature: 0.2,
        maxTokens: 4096,
    ));
    Http::fake(['*/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => json_encode([
            'results' => [['input' => '头像', 'output' => 'user_avatar', 'type' => 'varchar', 'size' => 64]],
        ])]]],
    ], 200)]);

    $r = $this->postJson('/scaffold/db/designer/translate', [
        'scene'           => 'fields',
        'table'           => 'demo_users',
        'prefix'          => 'user',
        'existing_fields' => [],
        'inputs'          => ['头像'],
    ]);
    $r->assertOk();
    expect($r->json('data.results.0.valid'))->toBeTrue();
    expect($r->json('data.results.0.output'))->toBe('user_avatar');
    // 传输 / 生成参数(借鉴 moo-scaffold-cloud)真进了 DeepSeek 请求体
    Http::assertSent(fn ($req) => $req['temperature'] === 0.2 && $req['max_tokens'] === 4096);
});

it('POST translate returns 503 AI_NOT_CONFIGURED when apiKey empty', function () {
    // AI 配置改从 AiSettingStore 读(不再走 config('scaffold.ai.*'))。显式注入空 key 的
    // TranslationService → assertConfigured 抛 AiNotConfiguredException → 503。
    app()->instance(TranslationService::class, new TranslationService(
        baseUrl: 'https://api.deepseek.com/v1',
        apiKey: '',
        model: 'deepseek-chat',
        timeout: 10,
    ));
    $r = $this->postJson('/scaffold/db/designer/translate', [
        'scene'  => 'fields',
        'table'  => 'demo_users',
        'prefix' => 'user',
        'inputs' => ['头像'],
    ]);
    $r->assertStatus(503);
    expect($r->json('error.code'))->toBe('AI_NOT_CONFIGURED');
});

it('POST translate returns 422 on missing scene', function () {
    $r = $this->postJson('/scaffold/db/designer/translate', []);
    $r->assertStatus(422);
});

// ─── POST createSchema / createTable validation ─────────────

it('POST /scaffold/db/designer/schemas returns 422 on missing required fields', function () {
    $r = $this->postJson('/scaffold/db/designer/schemas', []);
    $r->assertStatus(422);
    $r->assertJsonValidationErrors(['schema', 'name']);
});

it('POST createSchema returns 422 on schema name not PascalCase', function () {
    // plan-40 §五 F4:controller validate regex 已早于 SchemaLoader throw,走 Laravel 默认 422 validation shape
    $r = $this->postJson('/scaffold/db/designer/schemas', [
        'schema' => 'lower_case_invalid',
        'name'   => 'x',
    ]);
    $r->assertStatus(422);
    $r->assertJsonValidationErrors(['schema']);
});

// plan-40 §五 F4 regression
it('POST createTable returns 422 on table_key not snake_case', function () {
    $r = $this->postJson('/scaffold/db/designer/Platform/tables', [
        'table_key' => 'BadCamelCase',
        'name'      => 'x',
    ]);
    $r->assertStatus(422);
    $r->assertJsonValidationErrors(['table_key']);
});

it('POST createTable returns 422 on missing required fields', function () {
    $r = $this->postJson('/scaffold/db/designer/Platform/tables', []);
    $r->assertStatus(422);
    $r->assertJsonValidationErrors(['table_key', 'name']);
});

// ─── DELETE deleteTable validation ─────────────────────────

it('DELETE /scaffold/db/designer/{schema}/tables/{table} returns 422 on confirm_key mismatch', function () {
    $r = $this->deleteJson('/scaffold/db/designer/Platform/tables/platform_regions', [
        'confirm_key' => 'wrong_key',
    ]);
    $r->assertStatus(422);
    expect($r->json('error.code'))->toBe('CONFIRM_MISMATCH');
});

it('DELETE deleteTable returns 422 on missing confirm_key', function () {
    $r = $this->deleteJson('/scaffold/db/designer/Platform/tables/platform_regions', []);
    $r->assertStatus(422);
});
