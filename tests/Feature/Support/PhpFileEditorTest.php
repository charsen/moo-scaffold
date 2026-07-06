<?php declare(strict_types=1);

use Mooeen\Scaffold\Support\PhpFileEditor;

/**
 * PhpFileEditor 回归锁（此前 0 测试）——对真实 config/*.php 做位置级外科替换，最 destructive。
 * 锁住:只改字面量叶子值且保留排版/注释/env() 等函数节点、拒绝写非字面量、路径不存在/根非
 * return[] 抛错（且失败原文件不动）、各字面量类型 + 字符串转义、空 writes / 缺文件。
 */
function writeTmpPhpConfig(string $php): string
{
    $p = sys_get_temp_dir() . '/cfg_' . uniqid() . '.php';
    file_put_contents($p, $php);

    return $p;
}

const PHP_CFG_FIXTURE = <<<'PHP'
<?php

return [
    // 顶部注释保留
    'name'  => 'old-name',
    'debug' => env('APP_DEBUG', false),
    'limits' => [
        'max'     => 100,
        'enabled' => true,
    ],
    'tags' => ['a', 'b'],
];
PHP;

it('替换字面量叶子值（含嵌套 dot-path），保留注释/env()/未触及 key', function () {
    $p = writeTmpPhpConfig(PHP_CFG_FIXTURE);
    try {
        (new PhpFileEditor)->setValuesInFile($p, ['name' => 'new-name', 'limits.max' => 250]);

        $src = file_get_contents($p);
        expect($src)->toContain('// 顶部注释保留');         // 注释不丢
        expect($src)->toContain("env('APP_DEBUG', false)");  // 函数节点原样不动

        $cfg = include $p;                                   // 唯一路径 → 取到新值
        expect($cfg['name'])->toBe('new-name');
        expect($cfg['limits']['max'])->toBe(250);
        expect($cfg['limits']['enabled'])->toBeTrue();        // 未触及
    } finally {
        @unlink($p);
    }
});

it('拒绝写非字面量值（env() 等），原文件不动', function () {
    $p      = writeTmpPhpConfig(PHP_CFG_FIXTURE);
    $before = file_get_contents($p);
    try {
        expect(fn () => (new PhpFileEditor)->setValuesInFile($p, ['debug' => true]))
            ->toThrow(RuntimeException::class);
        expect(file_get_contents($p))->toBe($before);
    } finally {
        @unlink($p);
    }
});

it('路径不存在 / 根非 return[] → 抛错且原文件不动', function () {
    $p      = writeTmpPhpConfig(PHP_CFG_FIXTURE);
    $before = file_get_contents($p);
    try {
        expect(fn () => (new PhpFileEditor)->setValuesInFile($p, ['no.such.path' => 1]))
            ->toThrow(RuntimeException::class);
        expect(file_get_contents($p))->toBe($before);
    } finally {
        @unlink($p);
    }

    $bad = writeTmpPhpConfig("<?php\n\$x = 1;\n");
    try {
        expect(fn () => (new PhpFileEditor)->setValuesInFile($bad, ['x' => 2]))
            ->toThrow(RuntimeException::class);
    } finally {
        @unlink($bad);
    }
});

it('各字面量类型 + 数组重写 + 字符串转义', function () {
    $p = writeTmpPhpConfig(PHP_CFG_FIXTURE);
    try {
        (new PhpFileEditor)->setValuesInFile($p, [
            'name'           => "it's a \\ test", // 含引号 + 反斜杠 → 转义
            'limits.max'     => 3.5,               // float
            'limits.enabled' => false,             // bool
            'tags'           => ['x', 'y', 'z'],   // 数组整体重写
        ]);

        $cfg = include $p;
        expect($cfg['name'])->toBe("it's a \\ test");
        expect($cfg['limits']['max'])->toBe(3.5);
        expect($cfg['limits']['enabled'])->toBeFalse();
        expect($cfg['tags'])->toBe(['x', 'y', 'z']);
    } finally {
        @unlink($p);
    }
});

it('缺文件抛异常 / 空 writes 无操作', function () {
    expect(fn () => (new PhpFileEditor)->setValuesInFile('/no/such/cfg.php', ['a' => 1]))
        ->toThrow(RuntimeException::class);

    $p      = writeTmpPhpConfig(PHP_CFG_FIXTURE);
    $before = file_get_contents($p);
    try {
        (new PhpFileEditor)->setValuesInFile($p, []); // 空 → no-op
        expect(file_get_contents($p))->toBe($before);
    } finally {
        @unlink($p);
    }
});
