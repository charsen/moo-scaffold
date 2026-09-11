<?php declare(strict_types=1);

use Mooeen\Scaffold\Support\Concerns\AtomicFileWrite;

/**
 * AtomicFileWrite 回归锁（2026-09-11 收口，此前 0 测试）。
 *
 * 锁住三件事：
 *   1. **原子替换** —— 走 rename 而不是原地 truncate + 写，所以目标 inode 会变：并发读者
 *      （designer autosave 与终端 `moo:*` 同时跑）永远看到完整旧版或完整新版，不会看到半份；
 *      成功后不留 tmp 残留。
 *   2. **保留权限位** —— 本次收口修的实质 bug：原先 EnvFileEditor / PhpFileEditor 手写的
 *      tmp + rename 不 chmod，0600 的 .env 每次保存都会被 umask 放宽成 0644。这是确定性的，
 *      与"崩溃概率"无关。
 *   3. **失败不留痕** —— 目录不可写 / rename 失败都抛 RuntimeException，目标文件保持原样、
 *      临时文件被清掉。
 */
function atomic_file_write_writer(): object
{
    return new class
    {
        use AtomicFileWrite;

        public function write(string $path, string $content): void
        {
            $this->writeFileAtomically($path, $content);
        }
    };
}

function atomic_file_write_dir(): string
{
    $dir = sys_get_temp_dir() . '/atomic_' . uniqid();
    mkdir($dir, 0755, true);

    return $dir;
}

it('新建文件：内容正确且目录里不留 tmp 残留', function () {
    $dir  = atomic_file_write_dir();
    $path = $dir . '/X.yaml';
    try {
        atomic_file_write_writer()->write($path, "module: {}\n");

        expect(file_get_contents($path))->toBe("module: {}\n");
        // tmp 后缀是 .tmp.{hex}，既不会被 *.yaml glob 命中，也不该在成功后残留
        expect(scandir($dir))->toBe(['.', '..', 'X.yaml']);
    } finally {
        @unlink($path);
        @rmdir($dir);
    }
});

it('覆盖已存在文件：走 rename 换 inode（证明不是原地截断写）', function () {
    $dir  = atomic_file_write_dir();
    $path = $dir . '/X.yaml';
    try {
        file_put_contents($path, "old\n");
        clearstatcache(true, $path);
        $before = stat($path);

        atomic_file_write_writer()->write($path, str_repeat("tables: []\n", 200));

        clearstatcache(true, $path);
        $after = stat($path);

        expect(file_get_contents($path))->toBe(str_repeat("tables: []\n", 200));
        // 原地写会复用 inode；rename 一定换 inode —— 这就是"读者看不到半份"的机制
        expect($after['ino'])->not->toBe($before['ino']);
        expect(scandir($dir))->toBe(['.', '..', 'X.yaml']);
    } finally {
        @unlink($path);
        @rmdir($dir);
    }
});

it('保留原文件权限位：.env 的 0600 保存后仍是 0600（本次收口的实质修复）', function () {
    $dir  = atomic_file_write_dir();
    $path = $dir . '/.env';
    try {
        file_put_contents($path, "APP_ENV=local\n");
        chmod($path, 0600);
        clearstatcache(true, $path);

        atomic_file_write_writer()->write($path, "APP_ENV=production\n");

        clearstatcache(true, $path);
        expect(file_get_contents($path))->toBe("APP_ENV=production\n");
        // 收口前这里会变成 0644（umask 默认）——正是"每次保存悄悄放宽"的 bug
        expect(fileperms($path) & 07777)->toBe(0600);
    } finally {
        @unlink($path);
        @rmdir($dir);
    }
});

it('保留原文件权限位：0640 组读也一样（07777 全位继承，含 SGID 场景）', function () {
    $dir  = atomic_file_write_dir();
    $path = $dir . '/X.yaml';
    try {
        file_put_contents($path, "old\n");
        chmod($path, 0640);
        clearstatcache(true, $path);

        atomic_file_write_writer()->write($path, "new\n");

        clearstatcache(true, $path);
        expect(fileperms($path) & 07777)->toBe(0640);
    } finally {
        @unlink($path);
        @rmdir($dir);
    }
});

it('目录不存在 → 抛异常，不创建任何文件', function () {
    $missing = sys_get_temp_dir() . '/no_such_dir_' . uniqid() . '/X.yaml';

    expect(fn () => atomic_file_write_writer()->write($missing, 'x'))
        ->toThrow(RuntimeException::class, '目录不存在或不可写');
    expect(file_exists($missing))->toBeFalse();
});

it('rename 失败（目标已是目录）→ 抛异常且不留 tmp', function () {
    $dir  = atomic_file_write_dir();
    $path = $dir . '/sub';
    mkdir($path);
    try {
        expect(fn () => atomic_file_write_writer()->write($path, "x\n"))
            ->toThrow(RuntimeException::class, 'rename 失败');
        expect(scandir($dir))->toBe(['.', '..', 'sub']);
    } finally {
        @rmdir($path);
        @rmdir($dir);
    }
});

it('目录不可写 → 抛异常且目标文件保持原样、不留 tmp', function () {
    $dir  = atomic_file_write_dir();
    $path = $dir . '/X.yaml';
    file_put_contents($path, 'before');
    chmod($dir, 0555);
    clearstatcache(true, $dir);
    try {
        expect(fn () => atomic_file_write_writer()->write($path, 'after'))
            ->toThrow(RuntimeException::class);
        expect(file_get_contents($path))->toBe('before');
        expect(array_diff(scandir($dir) ?: [], ['.', '..', 'X.yaml']))->toBe([]);
    } finally {
        @chmod($dir, 0755);
        @unlink($path);
        @rmdir($dir);
    }
})->skip(fn () => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root 不受 0555 目录约束');
