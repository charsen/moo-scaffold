<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Support\PackageRegistry;

/**
 * plan-53 Phase 1:扩展包自动发现 + 写权硬线单测。
 * fixture 用 sys temp 造假包(composer.json + scaffold/database marker),显式 roots 注入。
 */

/** 造一个符合约定的假扩展包,返回包根。 */
function makeFakePackage(string $dir, string $name = 'acme/demo', string $ns = 'Acme\\Demo\\', bool $marker = true): string
{
    @mkdir($dir . '/src', 0755, true);
    if ($marker) {
        @mkdir($dir . '/scaffold/database', 0755, true);
    }
    file_put_contents($dir . '/composer.json', json_encode([
        'name'     => $name,
        'autoload' => ['psr-4' => [$ns => 'src/']],
    ], JSON_UNESCAPED_SLASHES));

    return $dir;
}

beforeEach(function () {
    $this->sandbox = sys_get_temp_dir() . '/scaffold_pkg_' . uniqid();
    @mkdir($this->sandbox, 0755, true);
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->sandbox);
});

it('带 scaffold/database marker 的包被发现,key 取包名短段、命名空间取 psr-4 首键', function () {
    $root = makeFakePackage($this->sandbox . '/moo-demo', 'acme/moo-demo', 'Acme\\Demo\\');
    $reg  = new PackageRegistry([$root]);

    expect($reg->has('moo-demo'))->toBeTrue();
    $pkg = $reg->get('moo-demo');
    expect($pkg['name'])->toBe('acme/moo-demo');
    expect($pkg['base_path'])->toBe($root . '/');
    expect($pkg['namespace'])->toBe('Acme\\Demo');
});

it('无 marker 的包不入列', function () {
    $root = makeFakePackage($this->sandbox . '/plain', 'acme/plain', 'Acme\\Plain\\', marker: false);
    expect((new PackageRegistry([$root]))->all())->toBe([]);
});

it('有 marker 但缺 psr-4 → 抛错(改包不兜底)', function () {
    $dir = $this->sandbox . '/broken';
    @mkdir($dir . '/scaffold/database', 0755, true);
    file_put_contents($dir . '/composer.json', json_encode(['name' => 'acme/broken']));

    expect(fn () => (new PackageRegistry([$dir]))->all())->toThrow(InvalidArgumentException::class);
});

it('key 重名(不同 vendor 同短名)→ 抛错', function () {
    $a = makeFakePackage($this->sandbox . '/a/moo-x', 'acme/moo-x', 'Acme\\X\\');
    $b = makeFakePackage($this->sandbox . '/b/moo-x', 'other/moo-x', 'Other\\X\\');

    expect(fn () => (new PackageRegistry([$a, $b]))->all())->toThrow(InvalidArgumentException::class);
});

it('写权硬线:vendor 外的真目录可写,vendor 内拷贝只读,vendor 内软链(realpath 逃逸)可写', function () {
    // ① vendor 外(测试沙箱工作副本)→ 可写
    $outside = makeFakePackage($this->sandbox . '/outside', 'acme/outside', 'Acme\\O\\');
    expect((new PackageRegistry([$outside]))->get('outside')['writable'])->toBeTrue();

    // ② base_path('vendor') 内的普通目录(= vcs 拷贝)→ 只读
    $vendorDir = base_path('vendor/acme-test/copied');
    makeFakePackage($vendorDir, 'acme-test/copied', 'AcmeTest\\C\\');
    expect((new PackageRegistry([$vendorDir]))->get('copied')['writable'])->toBeFalse();

    // ③ vendor 内软链 → 真目录在外(realpath 逃出 vendor)→ 可写
    $real = makeFakePackage($this->sandbox . '/linked-real', 'acme-test/linked', 'AcmeTest\\L\\');
    $link = base_path('vendor/acme-test/linked');
    @mkdir(dirname($link), 0755, true);
    @symlink($real, $link);
    expect((new PackageRegistry([$link]))->get('linked')['writable'])->toBeTrue();

    // 清理 testbench vendor 里的临时物
    @unlink($link);
    (new Filesystem)->deleteDirectory(base_path('vendor/acme-test'));
});

it('all() 按 key 升序、结果缓存(同实例二次调用同引用)', function () {
    $b   = makeFakePackage($this->sandbox . '/bb', 'acme/bb', 'Acme\\B\\');
    $a   = makeFakePackage($this->sandbox . '/aa', 'acme/aa', 'Acme\\A\\');
    $reg = new PackageRegistry([$b, $a]);

    expect(array_keys($reg->all()))->toBe(['aa', 'bb']);
    expect($reg->all())->toBe($reg->all());
});

it('composer runtime 发现路径:无异常且不含 host 自己(root package 排除)', function () {
    // testbench 环境里 moo-scaffold 自身 vendor 无带 marker 的包 → 空;重点锁"不抛 + 是数组"
    $reg = new PackageRegistry;
    expect($reg->all())->toBeArray();
    expect($reg->has(basename(base_path())))->toBeFalse();
});
