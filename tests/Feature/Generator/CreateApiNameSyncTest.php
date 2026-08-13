<?php

declare(strict_types=1);

use Illuminate\Console\View\Components\Factory;
use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Generator\CreateApiGenerator;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Yaml\Yaml;

/**
 * @module_name {zh-CN: 内容 | en: Content}
 * @controller_name {zh-CN: 文章 | en: Article}
 */
class ApiNameSyncArticleController
{
    /**
     * 文章列表
     *
     * 返回已发布文章。
     */
    public function index(): void {}

    public function show(): void {}
}

function apiNameSyncGenerator(bool $syncNames = false): CreateApiGenerator
{
    $generator = new CreateApiGenerator(
        Mockery::mock(Factory::class),
        app(Filesystem::class),
        app(Utility::class),
    );

    $property = new ReflectionProperty($generator, 'syncNames');
    $property->setValue($generator, $syncNames);

    return $generator;
}

/** @return array<string, mixed> */
function buildApiNameSchema(CreateApiGenerator $generator, array $existingData): array
{
    $method = new ReflectionMethod($generator, 'buildControllerSchemaContent');
    $result = $method->invoke(
        $generator,
        'Article',
        ['index' => ['method' => 'GET', 'uri' => 'api/articles']],
        new ReflectionClass(ApiNameSyncArticleController::class),
        $existingData,
        '2026-08-13 00:00:00',
    );

    return Yaml::parse($result['content']);
}

it('fills blank controller and action names from docblocks without requiring force sync', function () {
    $schema = buildApiNameSchema(apiNameSyncGenerator(), [
        'controller' => ['class' => 'Article', 'name' => ''],
        'actions'    => ['index_get' => ['name' => '', 'desc' => []]],
    ]);

    expect($schema['controller']['name'])->toBe('文章')
        ->and($schema['actions']['index_get']['name'])->toBe('文章列表')
        ->and($schema['actions']['index_get']['desc'])->toBe(['返回已发布文章。']);

    $method = new ReflectionMethod(apiNameSyncGenerator(), 'buildControllerSchemaContent');
    $result = $method->invoke(
        apiNameSyncGenerator(),
        'Article',
        ['index' => ['method' => 'GET', 'uri' => 'api/articles']],
        new ReflectionClass(ApiNameSyncArticleController::class),
        ['controller' => ['class' => 'Article', 'code' => '']],
        '2026-08-13 00:00:00',
    );

    expect($result['content'])->toContain("    code:\n")
        ->not->toContain("    code: \n");
});

it('preserves non-empty YAML names by default and syncs controller and action names explicitly', function () {
    $existing = [
        'controller' => ['class' => 'Article', 'name' => '旧控制器名称'],
        'actions'    => ['index_get' => ['name' => '旧动作名称', 'desc' => ['旧说明']]],
    ];

    $preserved = buildApiNameSchema(apiNameSyncGenerator(), $existing);
    $synced    = buildApiNameSchema(apiNameSyncGenerator(true), $existing);

    expect($preserved['controller']['name'])->toBe('旧控制器名称')
        ->and($preserved['actions']['index_get']['name'])->toBe('旧动作名称')
        ->and($preserved['actions']['index_get']['desc'])->toBe(['旧说明'])
        ->and($synced['controller']['name'])->toBe('文章')
        ->and($synced['actions']['index_get']['name'])->toBe('文章列表')
        ->and($synced['actions']['index_get']['desc'])->toBe(['返回已发布文章。']);
});

it('does not replace an existing action name with a raw method name when its docblock is missing', function () {
    $generator = apiNameSyncGenerator(true);
    $method    = new ReflectionMethod($generator, 'buildControllerSchemaContent');
    $result    = $method->invoke(
        $generator,
        'Article',
        ['show' => ['method' => 'GET', 'uri' => 'api/articles/{article}']],
        new ReflectionClass(ApiNameSyncArticleController::class),
        [
            'controller' => ['class' => 'Article', 'name' => '文章'],
            'actions'    => ['show_get' => ['name' => '文章详情', 'desc' => []]],
        ],
        '2026-08-13 00:00:00',
    );
    $schema = Yaml::parse($result['content']);

    expect($schema['actions']['show_get']['name'])->toBe('文章详情');
});
