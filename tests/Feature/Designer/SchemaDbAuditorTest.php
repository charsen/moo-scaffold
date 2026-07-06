<?php declare(strict_types=1);

use Mooeen\Scaffold\Designer\SchemaDbAuditor;
use Mooeen\Scaffold\Designer\SchemaLoader;

/**
 * SchemaDbAuditor 对账逻辑回归锁（此前 0 测试）。
 *
 * 真正的价值是 type/size/unique 漂移比对，但它耦合 MySQL information_schema（SQLite 跑不到）。
 * 这里用两个测试替身打开比对逻辑:
 *   - SchemaDbAuditorFakeLoader 覆写 loadNormalized 注入 yaml 侧声明
 *   - SchemaDbAuditorFake 覆写 protected dbColumns/dbSingleColUniqueColumns 注入「DB 侧」实况
 * 这样 audit() 的真实比对 + 分类逻辑被完整跑到、断言到，不依赖任何 DB。
 */
class SchemaDbAuditorFakeLoader extends SchemaLoader
{
    public array $normalized = [];

    public function __construct() {} // 不调 parent:本替身只用 loadNormalized

    public function loadNormalized(string $schema): array
    {
        return $this->normalized;
    }
}

class SchemaDbAuditorFake extends SchemaDbAuditor
{
    /** @var array<string,array<string,array{type:string,len:?int}>> */
    public array $cols = [];

    /** @var array<string,array<string,true>> */
    public array $uniq = [];

    protected function dbColumns(string $table): array
    {
        return $this->cols[$table] ?? [];
    }

    protected function dbSingleColUniqueColumns(string $table): array
    {
        return $this->uniq[$table] ?? [];
    }
}

function makeFakeAuditor(array $tables, array $cols, array $uniq = []): SchemaDbAuditorFake
{
    $loader             = new SchemaDbAuditorFakeLoader;
    $loader->normalized = ['tables' => $tables];
    $a                  = new SchemaDbAuditorFake($loader);
    $a->cols            = $cols;
    $a->uniq            = $uniq;

    return $a;
}

it('列类型 / size / 缺列漂移；id 与 _system 跳过；boolean→tinyint 不算漂移', function () {
    $auditor = makeFakeAuditor(
        tables: ['users' => ['fields' => [
            'id'         => ['type' => 'bigint'],                    // id 跳过
            'name'       => ['type' => 'varchar', 'size' => 100],    // type 漂移
            'bio'        => ['type' => 'varchar', 'size' => 200],    // size 漂移
            'age'        => ['type' => 'int'],                       // 一致
            'ghost'      => ['type' => 'varchar', 'size' => 50],     // DB 缺列
            'active'     => ['type' => 'boolean'],                   // boolean↔tinyint 一致
            'created_at' => ['type' => 'timestamp', '_system' => true], // _system 跳过
        ]]],
        cols: ['users' => [
            'name'   => ['type' => 'longtext', 'len' => null],
            'bio'    => ['type' => 'varchar', 'len' => 150],
            'age'    => ['type' => 'int', 'len' => null],
            'active' => ['type' => 'tinyint', 'len' => null],
        ]],
    );

    $rows  = $auditor->audit('Demo');
    $byCol = [];
    foreach ($rows as $r) {
        $byCol[$r['column']] = $r;
    }

    expect($byCol['name']['kind'])->toBe('type');
    expect($byCol['name']['yaml'])->toBe('varchar');
    expect($byCol['name']['db'])->toBe('longtext');
    expect($byCol['bio']['kind'])->toBe('size');
    expect($byCol['bio']['db'])->toBe('150');
    expect($byCol['ghost']['kind'])->toBe('missing-column');
    expect($byCol)->not->toHaveKeys(['id', 'age', 'active', 'created_at']); // 一致/跳过的不报
});

it('单列 unique 索引双向漂移:yaml unique 但 DB 普通 / DB unique 但 yaml 普通', function () {
    $auditor = makeFakeAuditor(
        tables: ['posts' => [
            'fields' => ['slug' => ['type' => 'varchar', 'size' => 120], 'cat' => ['type' => 'int']],
            'index'  => [
                'posts_slug_unique' => ['type' => 'unique', 'fields' => 'slug'],
                'posts_cat_index'   => ['type' => 'index', 'fields' => 'cat'],
            ],
        ]],
        cols: ['posts' => ['slug' => ['type' => 'varchar', 'len' => 120], 'cat' => ['type' => 'int', 'len' => null]]],
        uniq: ['posts' => ['cat' => true]], // DB:cat 唯一、slug 不唯一
    );

    $rows  = array_values(array_filter($auditor->audit('Demo'), fn ($r) => $r['kind'] === 'unique-index'));
    $byCol = [];
    foreach ($rows as $r) {
        $byCol[$r['column']] = $r;
    }

    expect($byCol['slug']['yaml'])->toBe('unique');
    expect($byCol['slug']['db'])->toBe('普通索引');
    expect($byCol['cat']['db'])->toBe('unique');
});

it('多列复合索引不参与单列对账', function () {
    $auditor = makeFakeAuditor(
        tables: ['t' => [
            'fields' => ['a' => ['type' => 'int'], 'b' => ['type' => 'int']],
            'index'  => ['t_ab_unique' => ['type' => 'unique', 'fields' => ['a', 'b']]],
        ]],
        cols: ['t' => ['a' => ['type' => 'int', 'len' => null], 'b' => ['type' => 'int', 'len' => null]]],
    );

    expect($auditor->audit('Demo'))->toBe([]); // 复合唯一 → 跳过、无漂移
});

it('表在 DB 不存在（dbColumns 空）→ 整表跳过，交给 diff 当 create', function () {
    $auditor = makeFakeAuditor(
        tables: ['newbie' => ['fields' => ['x' => ['type' => 'varchar', 'size' => 10]]]],
        cols: [], // DB 无此表
    );

    expect($auditor->audit('Demo'))->toBe([]);
});

it('完全一致 → 无漂移', function () {
    $auditor = makeFakeAuditor(
        tables: ['ok' => ['fields' => ['title' => ['type' => 'varchar', 'size' => 64]]]],
        cols: ['ok' => ['title' => ['type' => 'varchar', 'len' => 64]]],
    );

    expect($auditor->audit('Demo'))->toBe([]);
});

it('kindLabel 映射 + isSupported 在非 mysql 连接下为 false', function () {
    expect(SchemaDbAuditor::kindLabel('type'))->toBe('类型');
    expect(SchemaDbAuditor::kindLabel('size'))->toBe('size');
    expect(SchemaDbAuditor::kindLabel('unique-index'))->toBe('唯一索引');
    expect(SchemaDbAuditor::kindLabel('missing-column'))->toBe('DB 缺列');
    expect(SchemaDbAuditor::kindLabel('unknown'))->toBe('unknown'); // 默认原样

    // 测试套件默认 sqlite 连接 → 非 mysql → isSupported() false（调用方据此跳过）
    expect((new SchemaDbAuditor(new SchemaDbAuditorFakeLoader))->isSupported())->toBeFalse();
});
