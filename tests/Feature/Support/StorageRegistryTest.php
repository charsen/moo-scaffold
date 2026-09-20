<?php declare(strict_types=1);

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Support\Paths;
use Mooeen\Scaffold\Support\StorageRegistry;

/**
 * `Support\StorageRegistry` 的**语义**锚点（2026-09-19，中/高风险队列第 3 项 · 阶段 3b-3）。
 *
 * 为什么必须有这个文件：这 9 个方法外迁前**只有生成器链路间接覆盖** —— 生成器测试确实会写
 * `storage/scaffold/*.php` 再跑，但它们断言的是**产物**，缓存读错成什么样只要产物「看起来对」
 * 就发现不了（尤其 `$merge_all` 那两个扁平合并臂：写错合并口径，产物可能只少一两个条目）。
 * 而外迁前 `Utility` 的 docblock 写着 `@throws` —— **文档承诺了没人钉过的东西**，
 * 与 3b-2 给 `Paths` 补 `PathsTest` 是同一处境（`Paths.php` 类注释当时写着三处不对称「都有用例钉着」，
 * 实际没有）。所以这里补的是「搬走时顺手把真源钉死」，不是为新类补门面。
 *
 * 写法沿用 `PathsTest` 的规矩：**每条都带对照**，不是把当前返回值抄一遍 ——
 * 抄一遍的用例会跟实现一起变，等于没钉。
 */
beforeEach(function () {
    $this->reg_fs       = app(Filesystem::class);
    $this->reg_origStor = app()->storagePath();
    app()->useStoragePath(sys_get_temp_dir() . '/scaffold_reg_' . uniqid());
    $this->reg_fs->ensureDirectoryExists(storage_path('scaffold'));
});

afterEach(function () {
    $this->reg_fs->deleteDirectory(storage_path());
    app()->useStoragePath($this->reg_origStor);
});

/** 往 `storage/scaffold/` 写一个聚合缓存文件（与 `moo:fresh` 的产物同形）。 */
function reg_put(string $file, mixed $data): void
{
    (new Filesystem)->put(Paths::storage() . $file, '<?php return ' . var_export($data, true) . ';');
}

it('每个读方法只认自己那个文件名 —— 七个缓存各写各的，不串', function () {
    reg_put('tables.php', ['T']);
    reg_put('models.php', ['M']);
    reg_put('model_ids.php', ['I']);
    reg_put('fields.php', ['F']);
    reg_put('controllers.php', ['C']);
    reg_put('enums.php', ['E']);
    reg_put('widgets.php', ['W']);

    expect(StorageRegistry::tables())->toBe(['T'])
        ->and(StorageRegistry::models())->toBe(['M'])
        ->and(StorageRegistry::modelIds())->toBe(['I'])
        ->and(StorageRegistry::fields())->toBe(['F'])
        ->and(StorageRegistry::controllers(false))->toBe(['C'])
        ->and(StorageRegistry::enums(false))->toBe(['E'])
        ->and(StorageRegistry::table('widgets'))->toBe(['W']);
});

it('table() 缺表抛 InvalidArgumentException；其余缺文件抛 FileNotFoundException —— 都是响亮失败，不回落空数组', function () {
    // 目录在、文件一个都没有（`moo:fresh` 没跑过）
    expect(fn () => StorageRegistry::table('nope'))->toThrow(InvalidArgumentException::class, 'Invalid Argument (Not Found).');

    foreach ([
        'tables'      => static fn () => StorageRegistry::tables(),
        'models'      => static fn () => StorageRegistry::models(),
        'modelIds'    => static fn () => StorageRegistry::modelIds(),
        'fields'      => static fn () => StorageRegistry::fields(),
        'controllers' => static fn () => StorageRegistry::controllers(false),
        'enums'       => static fn () => StorageRegistry::enums(false),
    ] as $method => $call) {
        expect($call)->toThrow(FileNotFoundException::class, null, "StorageRegistry::{$method}() 缺文件应抛 FileNotFoundException");
    }
});

it('controllers(true) 按短类名扁平合并 ⇒ 跨 schema 同名互相覆盖（controllers(false) 才是按 schema 分组）', function () {
    reg_put('controllers.php', [
        'Solution' => ['CategoryController' => ['from' => 'Solution'], 'TrendController' => ['from' => 'Solution']],
        'WorkTask' => ['CategoryController' => ['from' => 'WorkTask']],
    ]);

    $bySchema = StorageRegistry::controllers(false);
    expect($bySchema)->toHaveCount(2)
        ->and($bySchema['Solution'])->toHaveCount(2)
        ->and($bySchema['WorkTask'])->toHaveCount(1);

    // 这就是「首页控制器统计改为按模块求和」的原因：扁平合并只剩 2（不是 3），
    // 且同名那个被**后出现的 schema** 覆盖（`CategoryController` 落到 WorkTask）。
    $flat = StorageRegistry::controllers(true);
    expect($flat)->toHaveCount(2)
        ->and($flat['CategoryController'])->toBe(['from' => 'WorkTask'])
        ->and($flat['TrendController'])->toBe(['from' => 'Solution']);

    // 默认参数就是 `true`（既有形态，外迁时原样保留）
    expect(StorageRegistry::controllers())->toBe($flat);
});

it('enums(true) 按**字段名**扁平合并（不是表名）；enums(false) 保留 表 → 字段 → 值', function () {
    reg_put('enums.php', [
        'tables_a' => ['status' => ['a1', 'a2'], 'title' => ['a3']],
        'tables_b' => ['status' => ['b1']],
    ]);

    $byTable = StorageRegistry::enums(false);
    expect($byTable)->toHaveCount(2)
        ->and($byTable['tables_a'])->toHaveCount(2);

    $flat = StorageRegistry::enums(true);
    // 键是**字段名**：若有人改成按表名合并，这里会变成 ['tables_a','tables_b'] 而立刻红
    expect(array_keys($flat))->toBe(['status', 'title'])
        ->and($flat['status'])->toBe(['b1'])
        ->and($flat['title'])->toBe(['a3']);
});

it('enumWords() 跳 __pending_ 占位，且 zh-CN 取 $attr[2]、en 取 $attr[1]（下标是反的，勿顺手换）', function () {
    // 缓存里每个词条是 `[值, EnName, CnName]`（见 `moo:fresh` 的产出形状）——
    // 注意 En 在前、Cn 在后，而**产物键**是 `zh-CN` 先、`en` 后，所以实现里是 `[2]` 配 `zh-CN`。
    reg_put('enums.php', [
        'demo' => ['status' => [
            'active'      => ['VAL', 'EN', 'ZH'],
            '__pending_0' => ['VALP', 'ENP', 'ZHP'],
        ]],
    ]);

    $words = StorageRegistry::enumWords();

    // 三个值互不相同 ⇒ `[1]`/`[2]` 写反、或错取 `[0]`，都会立刻红
    expect($words)->toBe([
        'status_active' => ['zh-CN' => 'ZH', 'en' => 'EN'],
    ])
        ->and(array_keys($words))->not->toContain('status___pending_0');
});

it('dictionaryStats() 三个计数：只数有枚举的表，value 取原始 case 数；缓存缺失时三个 0，不炸', function () {
    reg_put('tables.php', [
        'Solution' => ['tables' => ['t1' => [], 't2' => []]],
        'WorkTask' => ['tables' => ['t3' => []]],
    ]);
    reg_put('enums.php', [
        't1' => ['status' => ['a', 'b'], 'kind' => ['c']],   // 2 字段 / 3 值
        // t2 无枚举
        't3' => ['level' => ['d']],                          // 1 字段 / 1 值
    ]);

    // modules=2（Solution 与 WorkTask 各有一个带字典的表）; fields=2+1; values=3+1
    expect(StorageRegistry::dictionaryStats())->toBe(['modules' => 2, 'fields' => 3, 'values' => 4]);

    // enums.php 没了（`moo:fresh` 没跑过）⇒ 兜底三个 0，而不是把异常抛给首页
    (new Filesystem)->delete(Paths::storage() . 'enums.php');
    expect(StorageRegistry::dictionaryStats())->toBe(['modules' => 0, 'fields' => 0, 'values' => 0]);
});

it('不缓存结果：重写缓存文件后下一次读到新值（留一条挡住「顺手加个 static 记忆」）', function () {
    reg_put('models.php', ['v1']);
    expect(StorageRegistry::models())->toBe(['v1']);

    reg_put('models.php', ['v2']);
    expect(StorageRegistry::models())->toBe(['v2']);
});
