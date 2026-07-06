<?php declare(strict_types=1);

use Mooeen\Scaffold\Support\ApiSchemaService;

/**
 * ApiSchemaService 回归锁——API 文档统计页/调试器的数据来源：扫
 * scaffold/api/{app}/**.yaml 数 module/controller/action。
 * 锁住:递归扫子目录折算 module、跳过 `_` 前缀与非 .yaml、损坏 yaml 不计入 controller/action、
 * 缺目录兜底、汇总 api_count。
 *
 * sandbox:setBasePath 到 temp dir，api schema 落 sandbox/scaffold/api/，跑完整目录删。
 */
function apiSchema_writeYaml(string $path, string $body): void
{
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, $body);
}

function apiSchema_appDir(): string
{
    return base_path('scaffold/api/admin');
}

beforeEach(function () {
    $this->sandbox = sys_get_temp_dir() . '/scaffold_apischema_' . uniqid();
    @mkdir($this->sandbox, 0755, true);
    $this->origBase = base_path();
    app()->setBasePath($this->sandbox);
    config(['scaffold.api.schema' => 'scaffold/api/']);   // → sandbox/scaffold/api/
    $this->svc = app(ApiSchemaService::class);
});

afterEach(function () {
    app()->setBasePath($this->origBase);
    // 递归删 sandbox
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->sandbox, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($rii as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($this->sandbox);
});

it('app 目录不存在 → 兜底统计', function () {
    $stats = $this->svc->getAppStats(['admin' => '后台']);

    expect($stats['admin']['name'])->toBe('后台');
    expect($stats['admin']['api_count'])->toBe(0);
    expect($stats['admin']['module_count'])->toBe(0);
    expect($stats['admin']['controller_count'])->toBe(0);
});

it('数 controller / action，并按子目录折算 module（Index = 根目录）', function () {
    // 根目录一个 controller，含 2 actions
    apiSchema_writeYaml(apiSchema_appDir() . '/Book.yaml', <<<'YAML'
        controller:
          class: BookController
        actions:
          index: { title: 列表 }
          store: { title: 新增 }
        YAML);
    // 子目录 User 下一个 controller，含 1 action
    apiSchema_writeYaml(apiSchema_appDir() . '/User/Profile.yaml', <<<'YAML'
        controller:
          class: ProfileController
        actions:
          show: { title: 详情 }
        YAML);

    $stats = $this->svc->getAppStats(['admin' => '后台'])['admin'];

    expect($stats['controller_count'])->toBe(2);
    expect($stats['api_count'])->toBe(3);
    expect($stats['module_count'])->toBe(2); // Index(根) + User
});

it('跳过 `_` 前缀文件与非 .yaml 文件', function () {
    apiSchema_writeYaml(apiSchema_appDir() . '/_shared.yaml', <<<'YAML'
        controller: { class: SharedController }
        actions: { index: {} }
        YAML);
    apiSchema_writeYaml(apiSchema_appDir() . '/notes.txt', 'not yaml');
    apiSchema_writeYaml(apiSchema_appDir() . '/Real.yaml', <<<'YAML'
        controller: { class: RealController }
        actions: { index: {} }
        YAML);

    $stats = $this->svc->getAppStats(['admin' => '后台'])['admin'];

    expect($stats['controller_count'])->toBe(1); // 只有 Real.yaml
    expect($stats['api_count'])->toBe(1);
});

it('损坏 / 空 yaml 计入 module 但不计 controller/action', function () {
    apiSchema_writeYaml(apiSchema_appDir() . '/Broken.yaml', "controller: [unclosed\n  : :");
    apiSchema_writeYaml(apiSchema_appDir() . '/Empty.yaml', '');

    $stats = $this->svc->getAppStats(['admin' => '后台'])['admin'];

    expect($stats['controller_count'])->toBe(0);
    expect($stats['api_count'])->toBe(0);
    // 文件被识别为 schema 文件，folder 进 modules（即便 yamlData 为空）
    expect($stats['module_count'])->toBe(1);
});

it('summarizeApps 汇总跨 app 的 api_count', function () {
    apiSchema_writeYaml(apiSchema_appDir() . '/Book.yaml', <<<'YAML'
        controller: { class: BookController }
        actions: { index: {}, store: {} }
        YAML);
    apiSchema_writeYaml(base_path('scaffold/api/api/Auth.yaml'), <<<'YAML'
        controller: { class: AuthController }
        actions: { login: {} }
        YAML);

    $summary = $this->svc->summarizeApps(['admin' => '后台', 'api' => '接口']);

    expect($summary['api_count'])->toBe(3); // 2 + 1
    expect($summary['apps'])->toHaveKeys(['admin', 'api']);
    expect($summary['apps']['admin']['api_count'])->toBe(2);
    expect($summary['apps']['api']['api_count'])->toBe(1);
});
