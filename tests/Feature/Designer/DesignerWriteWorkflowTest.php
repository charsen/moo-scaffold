<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Mooeen\Scaffold\Designer\SchemaLoader;
use Mooeen\Scaffold\Designer\SnapshotStore;
use Mooeen\Scaffold\Generator\FreshStorageGenerator;
use Mooeen\Scaffold\Http\Middleware\ScaffoldAuthenticate;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    $this->sandbox      = sys_get_temp_dir() . '/scaffold-workflow-' . uniqid();
    $this->origBase     = base_path();
    $this->origStorage  = storage_path();
    $this->origDatabase = database_path();
    app()->setBasePath($this->sandbox);
    app()->useStoragePath($this->sandbox . '/storage');
    app()->useDatabasePath($this->sandbox . '/database');
    mkdir($this->sandbox . '/scaffold/database/.snapshots', 0755, true);
    config(['scaffold.database.schema' => $this->sandbox . '/scaffold/database/', 'scaffold.config_ui.readonly' => false]);
    $this->withoutMiddleware([ScaffoldAuthenticate::class, VerifyCsrfToken::class]);
    $table          = ['fields' => ['id' => [], 'label' => ['name' => '标签', 'type' => 'varchar', 'size' => 64]]];
    $this->baseline = ['module' => ['name' => '演示', 'folder' => 'demo'], 'tables' => ['demo_a' => $table, 'demo_b' => $table]];
    $yaml           = Yaml::dump($this->baseline, 8, 4);
    file_put_contents($this->sandbox . '/scaffold/database/Demo.yaml', $yaml);
    file_put_contents($this->sandbox . '/scaffold/database/.snapshots/Demo.yaml', $yaml);
    app()->forgetInstance(SchemaLoader::class);
    app()->forgetInstance(SnapshotStore::class);
});

afterEach(function () {
    app()->setBasePath($this->origBase);
    app()->useStoragePath($this->origStorage);
    app()->useDatabasePath($this->origDatabase);
    (new Filesystem)->deleteDirectory($this->sandbox);
});

it('保存成功但缓存刷新失败时返回真实警告，已写入的 YAML 不回滚', function () {
    app()->bind(FreshStorageGenerator::class, fn () => new class(new NullOutput, new Filesystem, app(Utility::class)) extends FreshStorageGenerator
    {
        public function start($clean = false, $silence = false): bool
        {
            throw new RuntimeException('fixture refresh failure');
        }
    });
    $r = $this->postJson('/scaffold/db/designer/Demo/save', ['tables' => ['demo_a' => ['name' => '已修改']]]);
    $r->assertOk()->assertJsonPath('ok', true);
    expect($r->json('data.warnings.0'))->toContain('文件已保存')->toContain('php artisan moo:fresh');
    expect(Yaml::parseFile($this->sandbox . '/scaffold/database/Demo.yaml')['tables']['demo_a']['attrs']['name'])->toBe('已修改');
});

it('保存和刷新均成功时返回空 warnings，缓存落在隔离目录', function () {
    $r = $this->postJson('/scaffold/db/designer/Demo/save', ['tables' => ['demo_a' => ['name' => '已修改']]]);
    $r->assertOk()->assertJsonPath('data.warnings', []);
    expect(file_exists($this->sandbox . '/storage/scaffold/tables.php'))->toBeTrue();
});

it('删除 A 表只生成 A 的 drop migration，B 表的变更及基线保持待处理', function (bool $suspectedRename) {
    $current = $this->baseline;
    if ($suspectedRename) {
        $current['tables']['demo_b']['fields']['new_label'] = $current['tables']['demo_b']['fields']['label'];
        unset($current['tables']['demo_b']['fields']['label']);
    } else {
        $current['tables']['demo_b']['fields']['label']['size'] = 128;
    }
    file_put_contents($this->sandbox . '/scaffold/database/Demo.yaml', Yaml::dump($current, 8, 4));
    $r = $this->deleteJson('/scaffold/db/designer/Demo/tables/demo_a', ['confirm_key' => 'demo_a']);
    $r->assertOk();
    expect($r->json('data.migration_files'))->toHaveCount(1);
    $files = glob($this->sandbox . '/database/migrations/*.php');
    expect($files)->toHaveCount(1);
    expect(file_get_contents($files[0]))->toContain("dropIfExists('demo_a')")->not->toContain('demo_b');
    $baseline = Yaml::parseFile($this->sandbox . '/scaffold/database/.snapshots/Demo.yaml');
    expect($baseline['tables'])->not->toHaveKey('demo_a');
    expect($baseline['tables']['demo_b'])->toBe($this->baseline['tables']['demo_b']);
    expect(Yaml::parseFile($this->sandbox . '/scaffold/database/Demo.yaml')['tables']['demo_b'])->toBe($current['tables']['demo_b']);
})->with([false, true]);

it('迁移已落盘且快照已推进时，刷新返回 false 仍须提示缓存未刷新', function () {
    app()->bind(FreshStorageGenerator::class, fn () => new class(new NullOutput, new Filesystem, app(Utility::class)) extends FreshStorageGenerator
    {
        public function start($clean = false, $silence = false): bool
        {
            return false;
        }
    });
    $current                                                = $this->baseline;
    $current['tables']['demo_a']['fields']['label']['size'] = 128;
    file_put_contents($this->sandbox . '/scaffold/database/Demo.yaml', Yaml::dump($current, 8, 4));
    $r = $this->postJson('/scaffold/db/designer/Demo/migrate', ['only_table' => 'demo_a']);
    $r->assertOk()->assertJsonPath('ok', true);
    expect($r->json('data.files_written'))->toHaveCount(1);
    expect($r->json('data.warnings.0'))->toContain('文件已保存')->toContain('php artisan moo:fresh');
    $snapshot = Yaml::parseFile($this->sandbox . '/scaffold/database/.snapshots/Demo.yaml');
    expect($snapshot['tables']['demo_a']['fields']['label']['size'])->toBe(128);
    expect($snapshot['tables']['demo_b'])->toBe($this->baseline['tables']['demo_b']);
});
