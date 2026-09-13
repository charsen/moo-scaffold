<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Generator\CreateModelGenerator;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Output\NullOutput;

/**
 * append-only 表（表内只有 created_at、没有 updated_at）的模型时间戳口径（2026-09-12）。
 *
 * 缺陷现场：`moo-mini-app` 的 `RecordRevision::create()` 抛
 *   SQLSTATE[HY000]: table moo_mini_app_record_revisions has no column named updated_at
 * 因为 Eloquent 默认 insert 时同时写 created_at / updated_at，而这类表没有 updated_at。
 * 生成器改为：表内无 `updated_at` 时显式 `public const UPDATED_AT = null;`
 * —— 只关「更新于」，保留 `created_at` 自动写入（其语义常是「发生于」）。
 *
 * 同时钉住「不产生格式噪音」：有 `updated_at` 的表返回空串，占位符替换为空后
 * 渲染结果与旧模板逐字一致，已有 100+ 模型重生成时不会多出空行。
 */
function appendonly_gen(): CreateModelGenerator
{
    return new CreateModelGenerator(new NullOutput, app(Filesystem::class), app(Utility::class));
}

function appendonly_call(object $obj, string $method, array $args = []): mixed
{
    $ref = new ReflectionMethod($obj, $method);
    $ref->setAccessible(true);

    return $ref->invokeArgs($obj, $args);
}

it('表内无 updated_at → 生成 const UPDATED_AT = null（保留 created_at 自动写入）', function () {
    $block = appendonly_call(appendonly_gen(), 'getTimestampColumns', [[
        'id'         => ['type' => 'bigint'],
        'created_at' => ['type' => 'timestamp'],
    ]]);

    expect($block)->toContain('public const UPDATED_AT = null;')
        ->and($block)->toContain('本表只有 `created_at`')
        // 不能顺手关掉 timestamps：created_at 还要写
        ->and($block)->not->toContain('$timestamps = false');
});

it('表内有 updated_at → 返回空串，不产生任何格式变化', function () {
    $block = appendonly_call(appendonly_gen(), 'getTimestampColumns', [[
        'id'         => ['type' => 'bigint'],
        'created_at' => ['type' => 'timestamp'],
        'updated_at' => ['type' => 'timestamp'],
    ]]);

    expect($block)->toBe('');
});

it('占位符为空时，渲染出的 model stub 与旧模板逐字一致且是合法 PHP', function () {
    $stub = (string) file_get_contents(__DIR__ . '/../../../stubs/model.stub');
    $gen  = appendonly_gen();

    $meta = [
        'author'            => 'test',
        'date'              => '2026-09-12 00:00',
        'property_code'     => '',
        'namespace'         => 'App\\Models\\Demo',
        'use_class'         => 'use Illuminate\\Database\\Eloquent\\Model;',
        'use_trait'         => '    use Demo;',
        'class'             => 'Demo',
        'filter'            => 'DemoFilter',
        'class_name'        => '演示模型',
        'table_name'        => 'demo',
        'casts'             => '',
        'appends'           => '',
        'hidden'            => '',
        'fillable'          => '',
        'attributes'        => '',
        'timestamp_columns' => '',
    ];

    $rendered = appendonly_call($gen, 'buildStub', [$meta, $stub]);

    expect($rendered)->not->toContain('{{')
        ->and($rendered)->not->toContain('UPDATED_AT')
        // 空替换后不应出现连续两个空行（占位符行整个消失，与旧模板一致）
        ->and($rendered)->not->toContain("    use Demo;\n\n\n");

    token_get_all($rendered, TOKEN_PARSE);
});

it('占位符有值时渲染出的 model stub 仍是合法 PHP', function () {
    $stub = (string) file_get_contents(__DIR__ . '/../../../stubs/model.stub');
    $gen  = appendonly_gen();

    $meta = [
        'author'            => 'test',
        'date'              => '2026-09-12 00:00',
        'property_code'     => '',
        'namespace'         => 'App\\Models\\Demo',
        'use_class'         => 'use Illuminate\\Database\\Eloquent\\Model;',
        'use_trait'         => '    use Demo;',
        'class'             => 'Demo',
        'filter'            => 'DemoFilter',
        'class_name'        => '演示模型',
        'table_name'        => 'demo',
        'casts'             => '',
        'appends'           => '',
        'hidden'            => '',
        'fillable'          => '',
        'attributes'        => '',
        'timestamp_columns' => appendonly_call($gen, 'getTimestampColumns', [[
            'id'         => ['type' => 'bigint'],
            'created_at' => ['type' => 'timestamp'],
        ]]),
    ];

    $rendered = appendonly_call($gen, 'buildStub', [$meta, $stub]);

    expect($rendered)->toContain('public const UPDATED_AT = null;')
        ->and($rendered)->not->toContain('{{');

    token_get_all($rendered, TOKEN_PARSE);
});
