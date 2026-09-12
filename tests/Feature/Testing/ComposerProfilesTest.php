<?php

declare(strict_types=1);

use Mooeen\Scaffold\Testing\ComposerProfiles;

/**
 * ComposerProfiles 共享断言的独立回归。
 *
 * 全部用临时目录构造最小 Host（engine/ + 三份 manifest），不依赖任何真实 Host。
 */

/**
 * 构造一套完全合规的三份 manifest。
 *
 * 其中刻意包含两类"允许的差异"正例：
 *  - 公开包 charsen/moo-feedback 本地 @dev、测试/生产稳定，且只有本地有 path 仓库；
 *  - 本地多出 require-dev 开发工具与 scripts 段。
 *
 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
 */
function mooComposerFixtureProfiles(): array
{
    $manifest = [
        [
            'name'         => 'charsen/moo-scaffold',
            'repo-key'     => 'scaffold',
            'provider-rel' => 'src/ScaffoldProvider.php',
            'publish-tag'  => 'public',
        ],
        [
            'name'         => 'charsen/moo-upload',
            'repo-key'     => 'upload',
            'provider-rel' => 'src/MooeenUploadServiceProvider.php',
            'publish-tag'  => null,
        ],
    ];

    $local = [
        'name'    => 'fixture/app',
        'require' => [
            'php'                  => '^8.2',
            'laravel/framework'    => '^12.0',
            'charsen/moo-scaffold' => '^2.1@dev',
            'charsen/moo-upload'   => '^0.1@dev',
            'charsen/moo-feedback' => '^0.1@dev',
        ],
        'require-dev' => [
            'pestphp/pest'       => '^3.0',
            'laravel/pint'       => '^1.13',
            'fixture/local-tool' => '^1.0',
        ],
        'scripts' => [
            'test'       => ['@php artisan test'],
            'local-only' => ['echo local'],
        ],
        'extra'        => ['moo-private-packages' => $manifest],
        'repositories' => [
            'scaffold' => [
                'type'    => 'path',
                'url'     => '../../moo-scaffold',
                'options' => ['symlink' => true, 'versions' => ['charsen/moo-scaffold' => '2.x-dev']],
            ],
            'upload' => [
                'type'    => 'path',
                'url'     => '../../moo-upload',
                'options' => ['symlink' => true, 'versions' => ['charsen/moo-upload' => '0.1.x-dev']],
            ],
            'feedback' => [
                'type'    => 'path',
                'url'     => '../../moo-feedback',
                'options' => ['symlink' => true, 'versions' => ['charsen/moo-feedback' => '0.1.x-dev']],
            ],
        ],
    ];

    $test = [
        'name'    => 'fixture/app',
        'require' => [
            'php'                  => '^8.2',
            'laravel/framework'    => '^12.0',
            'charsen/moo-scaffold' => 'dev-dev',
            'charsen/moo-upload'   => 'dev-dev',
            'charsen/moo-feedback' => '^0.1',
        ],
        'require-dev' => [
            'pestphp/pest' => '^3.0',
            'laravel/pint' => '^1.13',
        ],
        'scripts'      => ['test' => ['@php artisan test']],
        'extra'        => ['moo-private-packages' => $manifest],
        'repositories' => [
            'scaffold' => ['type' => 'vcs', 'url' => 'git@gitee.com:charsen/moo-scaffold.git'],
            'upload'   => ['type' => 'vcs', 'url' => 'git@gitee.com:charsen/moo-upload.git'],
        ],
    ];

    $production                                    = $test;
    $production['require']['charsen/moo-scaffold'] = '^2.1.7';
    $production['require']['charsen/moo-upload']   = '^0.1.3';

    return [$local, $test, $production];
}

/**
 * @param array<string, mixed> $local
 * @param array<string, mixed> $test
 * @param array<string, mixed> $production
 */
function mooComposerFixtureWrite(string $root, array $local, array $test, array $production): void
{
    $engine = $root . '/engine';

    if (! is_dir($engine) && ! mkdir($engine, 0o777, true) && ! is_dir($engine)) {
        throw new RuntimeException('无法创建 fixture 目录：' . $engine);
    }

    $files = [
        'composer.json'            => $local,
        'composer.test.json'       => $test,
        'composer.production.json' => $production,
    ];

    foreach ($files as $file => $data) {
        file_put_contents(
            $engine . '/' . $file,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }
}

function mooComposerFixtureRemove(string $root): void
{
    if (! is_dir($root)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($root);
}

/**
 * 写入 fixture（可选先变异、后删除文件），执行断言回调，最后清理。
 *
 * @param callable(string): void                                                                     $callback
 * @param (callable(array<string, mixed>&, array<string, mixed>&, array<string, mixed>&): void)|null $mutate
 * @param list<string>                                                                               $missing
 */
function mooComposerFixtureWith(callable $callback, ?callable $mutate = null, array $missing = []): void
{
    [$local, $test, $production] = mooComposerFixtureProfiles();

    if ($mutate !== null) {
        $mutate($local, $test, $production);
    }

    $root = sys_get_temp_dir() . '/moo-scaffold-composer-' . bin2hex(random_bytes(8));
    mooComposerFixtureWrite($root, $local, $test, $production);

    foreach ($missing as $file) {
        @unlink($root . '/engine/' . $file);
    }

    try {
        $callback($root);
    } finally {
        mooComposerFixtureRemove($root);
    }
}

test('三份 manifest 全部合规时返回空数组', function () {
    mooComposerFixtureWith(function (string $root): void {
        expect(ComposerProfiles::problems($root))->toBe([]);
    });
});

test('公开包 @dev、本地 path 仓库与本地专用 require-dev / scripts 不报问题', function () {
    mooComposerFixtureWith(function (string $root): void {
        $local = json_decode((string) file_get_contents($root . '/engine/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        // 正例的前提确实存在，避免 fixture 退化后测试空跑。
        expect($local['require']['charsen/moo-feedback'])->toBe('^0.1@dev')
            ->and($local['require-dev'])->toHaveKey('fixture/local-tool')
            ->and($local['scripts'])->toHaveKey('local-only')
            ->and($local['repositories'])->toHaveKey('feedback')
            ->and(ComposerProfiles::problems($root))->toBe([]);
    });
});

test('缺少任一 profile 文件时报告缺失且不误判其余规则', function () {
    mooComposerFixtureWith(function (string $root): void {
        $problems = ComposerProfiles::problems($root);

        expect($problems)->toContain('composer.test.json: 文件不存在')
            ->and($problems)->not->toBe([]);
    }, null, ['composer.test.json']);
});

test('manifest 三份不一致（含顺序）会报问题', function () {
    mooComposerFixtureWith(function (string $root): void {
        expect(ComposerProfiles::problems($root))
            ->toContain('composer.json: extra.moo-private-packages 与 composer.production.json 不一致（含顺序）');
    }, function (array &$local): void {
        $local['extra']['moo-private-packages'] = array_reverse($local['extra']['moo-private-packages']);
    });
});

test('本地 manifest 包不是 path 仓库会报问题', function () {
    mooComposerFixtureWith(function (string $root): void {
        expect(ComposerProfiles::problems($root))
            ->toContain('composer.json: repositories.scaffold 的 type 应为 path，实际 vcs');
    }, function (array &$local): void {
        $local['repositories']['scaffold']['type'] = 'vcs';
    });
});

test('本地约束与 options.versions 主次版本不对应会报问题', function () {
    mooComposerFixtureWith(function (string $root): void {
        expect(ComposerProfiles::problems($root))
            ->toContain('composer.json: charsen/moo-scaffold 的本地约束 ^1.0@dev 与 options.versions.charsen/moo-scaffold=2.x-dev 的主次版本不对应');
    }, function (array &$local): void {
        $local['require']['charsen/moo-scaffold'] = '^1.0@dev';
    });
});

test('测试 profile 的 manifest 包约束不是 dev-dev 会报问题', function () {
    mooComposerFixtureWith(function (string $root): void {
        expect(ComposerProfiles::problems($root))
            ->toContain('composer.test.json: charsen/moo-upload 的测试约束应为 dev-dev，实际 ^0.1');
    }, function (array &$local, array &$test): void {
        $test['require']['charsen/moo-upload'] = '^0.1';
    });
});

test('生产 profile 的 manifest 包约束含 dev / @ 会报问题', function () {
    mooComposerFixtureWith(function (string $root): void {
        expect(ComposerProfiles::problems($root))
            ->toContain('composer.production.json: charsen/moo-scaffold 的生产约束应为稳定 semver，实际 dev-master')
            ->toContain('composer.production.json: charsen/moo-upload 的生产约束应为稳定 semver，实际 ^0.1@dev');
    }, function (array &$local, array &$test, array &$production): void {
        $production['require']['charsen/moo-scaffold'] = 'dev-master';
        $production['require']['charsen/moo-upload']   = '^0.1@dev';
    });
});

test('测试与生产 repositories 不同会报问题', function () {
    mooComposerFixtureWith(function (string $root): void {
        $problems = ComposerProfiles::problems($root);

        expect($problems)
            ->toContain('composer.test.json 与 composer.production.json: 私包 charsen/moo-upload 的 repositories.upload URL 不同（git@gitee.com:charsen/moo-upload.git vs git@gitee.com:charsen/other.git）')
            ->toContain('composer.test.json 与 composer.production.json: repositories.upload 条目不一致');
    }, function (array &$local, array &$test, array &$production): void {
        $production['repositories']['upload']['url'] = 'git@gitee.com:charsen/other.git';
    });
});

test('测试与生产除 require 外的其它键不同会报问题', function () {
    mooComposerFixtureWith(function (string $root): void {
        expect(ComposerProfiles::problems($root))
            ->toContain('composer.test.json 与 composer.production.json 除 require 外存在差异的键：scripts');
    }, function (array &$local, array &$test, array &$production): void {
        $production['scripts']['extra-prod'] = ['echo prod'];
    });
});

test('非 Moo 运行时依赖基线不一致会报问题', function () {
    mooComposerFixtureWith(function (string $root): void {
        expect(ComposerProfiles::problems($root))
            ->toContain('composer.test.json: 非 Moo 运行时依赖基线与 composer.json 不一致（仅该 profile 有 guzzlehttp/guzzle）');
    }, function (array &$local, array &$test): void {
        $test['require']['guzzlehttp/guzzle'] = '^7.9';
    });
});

test('manifest 包缺少测试/生产仓库 URL 会报问题', function () {
    mooComposerFixtureWith(function (string $root): void {
        $problems = ComposerProfiles::problems($root);

        expect($problems)
            ->toContain('composer.test.json: 私包 charsen/moo-upload 缺少 repositories.upload')
            ->toContain('composer.production.json: 私包 charsen/moo-upload 缺少 repositories.upload');
    }, function (array &$local, array &$test, array &$production): void {
        unset($test['repositories']['upload'], $production['repositories']['upload']);
    });
});
