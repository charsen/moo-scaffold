<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

/**
 * moo:assets:check 特征测试。
 *
 * 临时目录构造最小宿主：engine/vendor/<pkg>/public 为源、engine/public/vendor/<short> 为
 * 已发布副本，覆盖一致 / 缺失 / 内容不一致 / 多余、--out 报告、--publish-command 提示与退出码。
 * 只读：命令绝不应改写发布副本或源目录。
 */
$GLOBALS['mooAssetsCheckFixtures'] = [];

afterEach(function () {
    $fs = new Filesystem;
    foreach ($GLOBALS['mooAssetsCheckFixtures'] ?? [] as $dir) {
        if (is_dir($dir)) {
            $fs->deleteDirectory($dir);
        }
    }
    $GLOBALS['mooAssetsCheckFixtures'] = [];
});

/**
 * @param array<string, string>      $source    相对路径 => 源内容
 * @param array<string, string>|null $published 相对路径 => 已发布内容；null = 与源完全一致
 */
function assetsCheckHostFixture(array $source = [], ?array $published = null, bool $withManifestEntry = true): string
{
    if ($source === []) {
        $source = ['css/index.css' => 'body{}', 'javascript/main.js' => 'console.log(1)'];
    }
    if ($published === null) {
        $published = $source;
    }

    $base   = sys_get_temp_dir() . '/moo-assets-' . bin2hex(random_bytes(4));
    $engine = $base . '/engine';
    $srcDir = $engine . '/vendor/charsen/moo-scaffold/public';
    $pubDir = $engine . '/public/vendor/scaffold';
    mkdir($srcDir, 0777, true);
    mkdir($pubDir, 0777, true);

    foreach ($source as $relative => $content) {
        $path = $srcDir . '/' . $relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }

    foreach ($published as $relative => $content) {
        $path = $pubDir . '/' . $relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }

    $manifest = [
        'extra' => ['moo-private-packages' => $withManifestEntry ? [[
            'name'         => 'charsen/moo-scaffold',
            'repo-key'     => 'scaffold',
            'provider-rel' => 'src/ScaffoldProvider.php',
            'publish-tag'  => 'public',
        ]] : []],
        'repositories' => ['scaffold' => ['type' => 'path', 'url' => '../../moo-scaffold']],
        'require'      => ['charsen/moo-scaffold' => '^2.1@dev'],
    ];
    file_put_contents($engine . '/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $GLOBALS['mooAssetsCheckFixtures'][] = $base;

    return $base;
}

it('moo:assets:check 一致时退出码 0 并打印一行确认', function () {
    $root = assetsCheckHostFixture();

    $this->artisan('moo:assets:check', ['--root' => $root])
        ->expectsOutputToContain('发布副本与包内 public/ 一致（2 个文件，md5 全等）')
        ->assertExitCode(0);
});

it('发布副本缺文件时非 0 退出并报「缺失」', function () {
    $root = assetsCheckHostFixture(
        ['css/index.css' => 'a', 'javascript/main.js' => 'b'],
        ['css/index.css' => 'a'],
    );

    $this->artisan('moo:assets:check', ['--root' => $root])
        ->expectsOutputToContain('缺失 1 / 内容不一致 0 / 多余 0')
        ->assertExitCode(1);
});

it('已发布文件内容陈旧（md5 不同）时非 0 退出并报「内容不一致」', function () {
    $root = assetsCheckHostFixture(
        ['javascript/pages/route.js' => "console.log('new');"],
        ['javascript/pages/route.js' => "console.log('old');"],
    );

    $this->artisan('moo:assets:check', ['--root' => $root])
        ->expectsOutputToContain('缺失 0 / 内容不一致 1 / 多余 0')
        ->assertExitCode(1);
});

it('发布目录完全不存在时全部计为缺失', function () {
    $root = assetsCheckHostFixture();
    (new Filesystem)->deleteDirectory($root . '/engine/public/vendor/scaffold');

    $this->artisan('moo:assets:check', ['--root' => $root])
        ->expectsOutputToContain('缺失 2 / 内容不一致 0 / 多余 0')
        ->assertExitCode(1);
});

it('多余文件默认只提示不判失败，--strict 时判失败', function () {
    $root = assetsCheckHostFixture(
        ['css/index.css' => 'a'],
        ['css/index.css' => 'a', 'sass/_stale.scss' => 'x'],
    );

    $this->artisan('moo:assets:check', ['--root' => $root])
        ->expectsOutputToContain('多余 1')
        ->expectsOutputToContain('陈旧残留')
        ->assertExitCode(0);

    $this->artisan('moo:assets:check', ['--root' => $root, '--strict' => true])
        ->expectsOutputToContain('缺失 0 / 内容不一致 0 / 多余 1')
        ->assertExitCode(1);
});

it('--out 写出分类报告，stdout 同时给摘要', function () {
    $root = assetsCheckHostFixture(
        ['css/index.css' => 'new', 'javascript/main.js' => 'x'],
        ['css/index.css' => 'old', 'sass/_stale.scss' => 'y'],
    );

    $this->artisan('moo:assets:check', ['--root' => $root, '--out' => 'storage/assets-check.md'])
        ->expectsOutputToContain('报告已写入')
        ->expectsOutputToContain('缺失 1 / 内容不一致 1 / 多余 1')
        ->assertExitCode(1);

    $report = (string) file_get_contents($root . '/storage/assets-check.md');
    expect($report)
        ->toContain('## 缺失（包内有、发布副本没有）')
        ->toContain('- javascript/main.js')
        ->toContain('## 内容不一致（md5 不同）')
        ->toContain('css/index.css')
        ->toContain('## 多余（发布副本有、包内没有）')
        ->toContain('- sass/_stale.scss')
        ->toContain('- 结果：不一致');
});

it('--publish-command 从 manifest 读出 publish-tag 给出修复命令', function () {
    $root = assetsCheckHostFixture();

    $this->artisan('moo:assets:check', ['--root' => $root, '--publish-command' => true])
        ->expectsOutputToContain('php artisan vendor:publish --tag=public --force')
        ->assertExitCode(0);
});

it('包未纳入 manifest 时提示无法推导 publish-tag', function () {
    $root = assetsCheckHostFixture(
        ['css/index.css' => 'new'],
        ['css/index.css' => 'old'],
        withManifestEntry: false,
    );

    $this->artisan('moo:assets:check', ['--root' => $root, '--publish-command' => true])
        ->expectsOutputToContain('未纳入宿主 manifest')
        ->assertExitCode(1);
});

it('命令只读：运行后发布副本与源目录内容不变', function () {
    $root = assetsCheckHostFixture(
        ['css/index.css' => 'new'],
        ['css/index.css' => 'old', 'sass/_stale.scss' => 'y'],
    );

    $this->artisan('moo:assets:check', ['--root' => $root])->assertExitCode(1);

    expect(file_get_contents($root . '/engine/public/vendor/scaffold/css/index.css'))->toBe('old')
        ->and(file_get_contents($root . '/engine/vendor/charsen/moo-scaffold/public/css/index.css'))->toBe('new')
        ->and(is_file($root . '/engine/public/vendor/scaffold/sass/_stale.scss'))->toBeTrue();
});
