<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Support\LocalMarkdownEditor;
use Mooeen\Scaffold\Support\PlansRepository;
use Mooeen\Scaffold\Support\RecordScope;
use Mooeen\Scaffold\Support\ReleaseRecordsRepository;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 记录范围的目录边界（`Support\RecordScope`）。
 *
 * 为什么钉这个：这条「配置项 → realpath → is_dir → 目录包含」判定原先在三处各写一份
 * （PlansRepository / ReleaseRecordsRepository / LocalMarkdownEditor），而它正是这两个目录的
 * **读写唯一边界** —— 「目录外的软链目标不读、也不算可编辑」全靠它。三份里改漏一份，
 * 表现是静默多读一个文件、或少判一次越界，都不会报错。
 *
 * 注释里出现 `is_dir` 这类字面属于说明、不算实现；用例一律走真实文件系统。
 */
beforeEach(function () {
    $this->directory = sys_get_temp_dir() . '/scaffold_scope_' . uniqid();
    mkdir($this->directory);
    config([
        'scaffold.plans.path'           => $this->directory,
        'scaffold.release_records.path' => $this->directory,
        'scaffold.config_ui.readonly'   => false,
    ]);
    app()->instance('env', 'local');
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->directory);
});

it('directory()：取配置目录的真实路径；空值 / 不存在 / 指向文件都给 null', function () {
    expect(RecordScope::directory('plans'))->toBe(realpath($this->directory));

    config(['scaffold.plans.path' => '']);
    expect(RecordScope::directory('plans'))->toBeNull();

    config(['scaffold.plans.path' => $this->directory . '/missing']);
    expect(RecordScope::directory('plans'))->toBeNull();

    // 指向**文件**（不是目录）：少了 is_dir 这一步，扫描会把该文件所在目录整个当成记录目录
    $file = $this->directory . '/records.md';
    file_put_contents($file, "# x\n");
    config(['scaffold.plans.path' => $file]);
    expect(RecordScope::directory('plans'))->toBeNull();
});

it('directory()：每个范围读自己的配置键，不互相串', function () {
    $releases = $this->directory . '/releases';
    mkdir($releases);
    config(['scaffold.release_records.path' => $releases]);

    expect(RecordScope::directory('plans'))->toBe(realpath($this->directory))
        ->and(RecordScope::directory('release_records'))->toBe(realpath($releases));
});

it('contains()：只认真正的下级路径，前缀相同的兄弟目录不算', function () {
    expect(RecordScope::contains('/a/b', '/a/b/c.md'))->toBeTrue()
        ->and(RecordScope::contains('/a/b', '/a/b/x/y.md'))->toBeTrue()
        // 少一个分隔符就会把 /a/bc 判成在 /a/b 内 —— 这条是整条判定的意义所在
        ->and(RecordScope::contains('/a/b', '/a/bc/d.md'))->toBeFalse()
        ->and(RecordScope::contains('/a/b', '/a/b'))->toBeFalse()
        ->and(RecordScope::contains('/a/b', '/a'))->toBeFalse()
        // 入参约定：两侧都必须是已 realpath 的路径（`..` 与软链由调用方先折叠，本判定只管前缀）。
        // 两个调用方都守这条：仓库侧传 Finder 的 getRealPath()，编辑侧传 realpath()。
        ->and(RecordScope::contains('/a/b', '/a/b/../c.md'))->toBeTrue();
});

it('allows()：白名单即配置键白名单，docs 不在其中（文档中心是另一套口径）', function () {
    expect(RecordScope::allows('plans'))->toBeTrue()
        ->and(RecordScope::allows('release_records'))->toBeTrue()
        ->and(RecordScope::allows('docs'))->toBeFalse()
        ->and(RecordScope::allows(''))->toBeFalse()
        ->and(RecordScope::allows('Plans'))->toBeFalse();
});

it('编辑入口与只读仓库共用同一条边界：配置指向文件时两边一致拒绝', function () {
    $file = $this->directory . '/records.md';
    file_put_contents($file, "# x\n");
    config(['scaffold.plans.path' => $file, 'scaffold.release_records.path' => $file]);

    expect(app(PlansRepository::class)->all())->toBe([])
        ->and(app(ReleaseRecordsRepository::class)->all())->toBe([]);
    foreach (['plans', 'release_records'] as $scope) {
        expect(fn () => app(LocalMarkdownEditor::class)->read($scope, 'records.md'))->toThrow(HttpException::class);
    }

    // 白名单外的范围连边界都不必算：直接 404（docs 走 TargetContext，不在此编辑）
    expect(fn () => app(LocalMarkdownEditor::class)->read('docs', 'records.md'))->toThrow(HttpException::class);
});
