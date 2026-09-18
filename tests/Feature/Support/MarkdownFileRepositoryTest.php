<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Support\MarkdownFileRepository;
use Mooeen\Scaffold\Support\PlansRepository;
use Mooeen\Scaffold\Support\RecordScope;
use Mooeen\Scaffold\Support\ReleaseRecordsRepository;

/**
 * 两个只读 Markdown 仓库的公共骨架（`Support\MarkdownFileRepository`）。
 *
 * 收口前 PlansRepository / ReleaseRecordsRepository 是逐行同构的复制体：同一份配置目录解析、
 * 同一套 allFiles 遍历、同一条「只收 .md / 不收目录外软链目标 / 不外露点与下划线前缀段」过滤，
 * 差别只在字段与排序。复制体的风险是「改了一个忘了另一个」，所以除了共用行为用例外，
 * 还要一个**结构锚点**：骨架只能由基类实现，子类不许把它抄回去。
 */
beforeEach(function () {
    $this->directory = sys_get_temp_dir() . '/scaffold_records_' . uniqid();
    // 记录目录叫 rec，兄弟目录叫 records —— 名字正是记录目录的**前缀扩展**，
    // 于是「裸前缀」越界判定会把它当成在目录内（这是该判定的核心风险形状）。
    $this->records = $this->directory . '/rec';
    $this->sibling = $this->directory . '/records';
    mkdir($this->records, 0755, true);
    mkdir($this->records . '/sub');
    mkdir($this->sibling);
    config(['scaffold.plans.path' => $this->records, 'scaffold.release_records.path' => $this->records]);
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->directory);
});

it('骨架归属锚点：all() 只由基类实现，子类只声明范围 / 字段 / 排序', function () {
    foreach ([PlansRepository::class, ReleaseRecordsRepository::class] as $class) {
        $reflection = new ReflectionClass($class);

        expect($reflection->getParentClass()?->getName())->toBe(MarkdownFileRepository::class, $class)
            // 子类一旦把扫描循环抄回去（覆写 all()），这条就红
            ->and($reflection->getMethod('all')->getDeclaringClass()->getName())->toBe(MarkdownFileRepository::class, $class)
            ->and($reflection->getMethod('makeRecord')->getDeclaringClass()->getName())->toBe($class)
            ->and($reflection->getMethod('sortRecords')->getDeclaringClass()->getName())->toBe($class);

        // 仓库声明的范围必须也在编辑入口白名单里，否则「列表里有、点不开」
        $scope = (new ReflectionMethod($class, 'scope'))->invoke(app($class));
        expect(RecordScope::allows($scope))->toBeTrue("{$class}::scope() 返回 {$scope}，不在编辑入口白名单里");
    }
});

it('两个范围共用同一条扫描边界：目录外软链、隐藏 / 下划线段、非 Markdown 都不进列表', function () {
    file_put_contents($this->records . '/ok.md', "# Ok\n");
    file_put_contents($this->records . '/sub/deep.md', "# Deep\n");
    file_put_contents($this->records . '/.hidden.md', "# Hidden\n");
    file_put_contents($this->records . '/_draft.md', "# Draft\n");
    file_put_contents($this->records . '/sub/_draft.md', "# Draft\n");
    file_put_contents($this->records . '/note.txt', "plain\n");
    // 目录外的真实文件，且其路径是记录目录的前缀扩展（…/records/leak.md vs …/rec）：
    // 只有「比较时带上分隔符」的越界判定能拦住它
    file_put_contents($this->sibling . '/leak.md', "# Outside\n");
    symlink($this->sibling . '/leak.md', $this->records . '/leak.md');
    expect(is_file($this->records . '/leak.md'))->toBeTrue();   // 从目录内看：存在且可读

    foreach ([PlansRepository::class, ReleaseRecordsRepository::class] as $class) {
        $slugs = array_column(app($class)->all(), 'slug');
        sort($slugs);   // 两个范围的排序口径不同，本用例只钉「收了哪些」
        expect($slugs)->toBe(['ok.md', 'sub/deep.md'], $class);
    }
});

it('配置目录未配置 / 不存在 / 指向文件时返回空列表，且不创建目录', function () {
    foreach (['', $this->directory . '/missing'] as $configured) {
        config(['scaffold.plans.path' => $configured, 'scaffold.release_records.path' => $configured]);
        expect(app(PlansRepository::class)->all())->toBe([], "path={$configured}")
            ->and(app(ReleaseRecordsRepository::class)->all())->toBe([], "path={$configured}");
    }
    expect(is_dir($this->directory . '/missing'))->toBeFalse();

    $file = $this->directory . '/records.md';
    file_put_contents($file, "# x\n");
    config(['scaffold.plans.path' => $file, 'scaffold.release_records.path' => $file]);
    expect(app(PlansRepository::class)->all())->toBe([])
        ->and(app(ReleaseRecordsRepository::class)->all())->toBe([]);
});
