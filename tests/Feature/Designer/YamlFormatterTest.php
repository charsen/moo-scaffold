<?php declare(strict_types=1);

use Mooeen\Scaffold\Designer\YamlFormatter;

/**
 * YamlFormatter 单测 — 纯函数,不依赖 Laravel 任何东西。
 * 覆盖:dump 默认 flags / reattachComments 头部块 + 段间注释 + 行内注释丢失 / dumpPreservingComments 复合调用。
 */
it('dump preserves nested scalars at inline=4', function () {
    $yaml = YamlFormatter::dump([
        'tables' => [
            'users' => [
                'fields' => [
                    'id'   => [],
                    'name' => ['type' => 'varchar', 'size' => 64],
                ],
            ],
        ],
    ]);
    expect($yaml)->toBeString();
    expect($yaml)->toContain('tables:');
    expect($yaml)->toContain('users:');
    expect($yaml)->toContain('name: { type: varchar, size: 64 }');     // inline=4 → field attrs inline-flow
});

it('dumpPreservingComments returns plain dump when original is empty', function () {
    $data = ['x' => 1];
    $a    = YamlFormatter::dumpPreservingComments($data, '');
    $b    = YamlFormatter::dump($data);
    expect($a)->toBe($b);
});

it('reattachComments preserves header comment block', function () {
    $src = <<<'YAML'
###
# Header block
##
tables:
    users:
        fields:
            id: {  }
YAML;
    $data       = ['tables' => ['users' => ['fields' => ['id' => []]]]];
    $normalized = YamlFormatter::dump($data);
    $out        = YamlFormatter::reattachComments($src, $normalized);
    expect($out)->toStartWith('###');
    expect($out)->toContain('# Header block');
    expect($out)->toContain('tables:');
});

it('reattachComments preserves segment comments anchored to next yaml line', function () {
    $src = <<<'YAML'
tables:
    # foo table comment
    foo:
        fields:
            id: {  }
    # bar table comment
    bar:
        fields:
            id: {  }
YAML;
    $data = [
        'tables' => [
            'foo' => ['fields' => ['id' => []]],
            'bar' => ['fields' => ['id' => []]],
        ],
    ];
    $normalized = YamlFormatter::dump($data);
    $out        = YamlFormatter::reattachComments($src, $normalized);
    // 注释挂回各自 anchor 行(foo: / bar:)前
    $fooIdx    = strpos($out, 'foo:');
    $barIdx    = strpos($out, 'bar:');
    $fooComIdx = strpos($out, '# foo table comment');
    $barComIdx = strpos($out, '# bar table comment');
    expect($fooComIdx)->toBeLessThan($fooIdx);
    expect($barComIdx)->toBeLessThan($barIdx);
    expect($fooComIdx)->toBeLessThan($barComIdx);     // foo 注释在 bar 之前
});

it('reattachComments drops inline `# x` comments (known C-type limitation)', function () {
    $src        = "tables:\n    users:\n        fields:\n            id: {  }  # inline comment\n";
    $data       = ['tables' => ['users' => ['fields' => ['id' => []]]]];
    $normalized = YamlFormatter::dump($data);
    $out        = YamlFormatter::reattachComments($src, $normalized);
    expect($out)->not->toContain('# inline comment');     // C 类丢失,符合 docstring
});

it('reattachComments idempotent when src has no comments', function () {
    $data       = ['x' => 1, 'y' => 2];
    $normalized = YamlFormatter::dump($data);
    $out        = YamlFormatter::reattachComments($normalized, $normalized);
    expect($out)->toBe($normalized);
});

it('dumpPreservingComments composes dump + reattach in one call', function () {
    $src  = "###\n# Project schema\n##\ntables:\n    users:\n        fields:\n            id: {  }\n";
    $data = ['tables' => ['users' => ['fields' => ['id' => []]]]];
    $out  = YamlFormatter::dumpPreservingComments($data, $src);
    expect($out)->toStartWith('###');
    expect($out)->toContain('# Project schema');
    expect($out)->toContain('tables:');
});

// ─── 2026-05-23:canonical table key 顺序 + tables 间空行 ────────────────────────

it('dump 把表内 key 归一到 canonical 顺序 model→controller→attrs→index→fields→enums', function () {
    // 故意乱序输入(enums 在前,model 在最后)— dump 后必须按 canonical
    $data = ['tables' => ['t_demo' => [
        'enums'      => ['status' => ['ok' => [1, 'OK', '正常']]],
        'fields'     => ['id' => []],
        'attrs'      => ['name' => '演示'],
        'index'      => ['id' => ['type' => 'primary', 'fields' => 'id']],
        'controller' => ['class' => 'DemoController'],
        'model'      => ['class' => 'Demo'],
    ]]];
    $out = YamlFormatter::dump($data);
    // model 必须比 controller 早出现,controller 比 attrs 早,...
    $posModel  = strpos($out, '        model:');
    $posCtrl   = strpos($out, '        controller:');
    $posAttrs  = strpos($out, '        attrs:');
    $posIdx    = strpos($out, '        index:');
    $posFields = strpos($out, '        fields:');
    $posEnums  = strpos($out, '        enums:');
    expect($posModel)->toBeLessThan($posCtrl);
    expect($posCtrl)->toBeLessThan($posAttrs);
    expect($posAttrs)->toBeLessThan($posIdx);
    expect($posIdx)->toBeLessThan($posFields);
    expect($posFields)->toBeLessThan($posEnums);
});

it('dump attrs sub-key canonical 顺序 name→desc→prefix→created_*→updated_*', function () {
    $data = ['tables' => ['t_demo' => [
        'attrs' => [
            'updated_by' => 'charsen',
            'updated_at' => '2026-05-23 12:00:00',
            'desc'       => '演示表',
            'created_by' => 'charsen',
            'created_at' => '2026-05-01 09:00:00',
            'prefix'     => 'demo_',
            'name'       => '演示',
        ],
    ]]];
    $out          = YamlFormatter::dump($data);
    $posName      = strpos($out, 'name: 演示');
    $posDesc      = strpos($out, 'desc: 演示表');
    $posPrefix    = strpos($out, 'prefix: demo_');
    $posCreatedBy = strpos($out, 'created_by: charsen');
    $posUpdatedBy = strpos($out, 'updated_by: charsen');
    expect($posName)->toBeLessThan($posDesc);
    expect($posDesc)->toBeLessThan($posPrefix);
    expect($posPrefix)->toBeLessThan($posCreatedBy);
    expect($posCreatedBy)->toBeLessThan($posUpdatedBy);
});

it('dump 在 tables 内每张相邻表之间插一空行(跟 System.yaml 老样本对齐)', function () {
    $data = ['tables' => [
        't_a' => ['fields' => ['id' => []]],
        't_b' => ['fields' => ['id' => []]],
        't_c' => ['fields' => ['id' => []]],
    ]];
    $out = YamlFormatter::dump($data);
    // t_b: 前面 1 行 blank;t_c: 前面 1 行 blank;t_a 紧贴 tables:
    expect($out)->toMatch('/\n\n    t_b:/');
    expect($out)->toMatch('/\n\n    t_c:/');
    // t_a 上面是 `tables:` 不插空(不能 `\n\n    t_a:`)
    expect($out)->not->toMatch('/tables:\s*\n\n    t_a:/');
});

it('dump 未知 table key 不丢失,排到 canonical 列表后面', function () {
    $data = ['tables' => ['t_demo' => [
        'attrs'              => ['name' => 'x'],
        'unknown_custom_key' => 'foo',
        'fields'             => ['id' => []],
    ]]];
    $out = YamlFormatter::dump($data);
    expect($out)->toContain('unknown_custom_key: foo');
    // unknown 应该在 fields 之后(canonical 之外的 key fall through 末尾)
    $posFields  = strpos($out, 'fields:');
    $posUnknown = strpos($out, 'unknown_custom_key:');
    expect($posUnknown)->toBeGreaterThan($posFields);
});
