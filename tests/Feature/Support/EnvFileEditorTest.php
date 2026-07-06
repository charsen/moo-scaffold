<?php declare(strict_types=1);

use Mooeen\Scaffold\Support\EnvFileEditor;

/**
 * EnvFileEditor 回归锁（此前 0 测试）——改真实 .env，destructive。锁住:行级原地替换保留
 * 注释/空行/顺序、拒绝新增不存在 key（且失败时原文件不动）、值的加引号/转义规则、缺文件抛错。
 */
function writeTmpEnv(string $content): string
{
    $p = sys_get_temp_dir() . '/env_' . uniqid() . '.env';
    file_put_contents($p, $content);

    return $p;
}

it('替换已有 key，保留注释/空行/顺序与未触及的 key', function () {
    $p = writeTmpEnv("# 顶部注释\nAPP_ENV=local\n\nDB_HOST=127.0.0.1\nDB_PORT=3306\n");
    try {
        (new EnvFileEditor)->setKeysInFile($p, ['APP_ENV' => 'production', 'DB_PORT' => '5432']);
        $out = file_get_contents($p);

        expect($out)->toContain('# 顶部注释');           // 注释保留
        expect($out)->toContain('APP_ENV=production');
        expect($out)->toContain('DB_PORT=5432');
        expect($out)->toContain('DB_HOST=127.0.0.1');     // 未触及的 key 不变
        // 顺序保留:APP_ENV 在 DB_HOST 之前
        expect(strpos($out, 'APP_ENV'))->toBeLessThan(strpos($out, 'DB_HOST'));
        expect(substr_count($out, "\n\n"))->toBeGreaterThan(0); // 空行保留
    } finally {
        @unlink($p);
    }
});

it('拒绝新增不存在的 key，且原文件保持不动', function () {
    $p      = writeTmpEnv("APP_ENV=local\n");
    $before = file_get_contents($p);
    try {
        expect(fn () => (new EnvFileEditor)->setKeysInFile($p, ['NEW_KEY' => 'x']))
            ->toThrow(RuntimeException::class);
        expect(file_get_contents($p))->toBe($before); // 抛错前未写盘
    } finally {
        @unlink($p);
    }
});

it('值的加引号/转义：安全字符裸写、特殊字符双引号 + 转义 $', function () {
    $p = writeTmpEnv("A=1\nB=1\nC=1\n");
    try {
        (new EnvFileEditor)->setKeysInFile($p, [
            'A' => 'simple_value-1.2/x',  // 全安全字符 → 裸写
            'B' => 'hello world',          // 含空格 → 双引号
            'C' => 'p@ss$word',            // 含 $ → 双引号 + 转义
        ]);
        $out = file_get_contents($p);

        expect($out)->toContain('A=simple_value-1.2/x');
        expect($out)->toContain('B="hello world"');
        expect($out)->toContain('C="p@ss\\$word"'); // $ 被转义为 \$
    } finally {
        @unlink($p);
    }
});

it('缺文件抛异常 / 空 writes 无操作', function () {
    expect(fn () => (new EnvFileEditor)->setKeysInFile('/no/such/path.env', ['A' => '1']))
        ->toThrow(RuntimeException::class);

    $p      = writeTmpEnv("A=1\n");
    $before = file_get_contents($p);
    try {
        (new EnvFileEditor)->setKeysInFile($p, []); // 空 → no-op，不抛
        expect(file_get_contents($p))->toBe($before);
    } finally {
        @unlink($p);
    }
});

it('同 key 重复多行 → 全部更新(dotenv 后者生效,只改第一处是假成功,2026-06-10 修)', function () {
    $p = writeTmpEnv("APP_X=first\nSOME=1\nAPP_X=second\n");
    try {
        (new EnvFileEditor)->setKeysInFile($p, ['APP_X' => 'NEW']);
        $out = file_get_contents($p);

        // 两处都改:否则 dotenv 实际生效的第二处仍是旧值
        expect(substr_count($out, 'APP_X=NEW'))->toBe(2);
        expect($out)->not->toContain('second');
        expect($out)->toContain('SOME=1');
    } finally {
        @unlink($p);
    }
});
