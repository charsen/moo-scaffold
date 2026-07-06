<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Tests\Feature\Designer\Support;

use Illuminate\Foundation\Application;
use Mooeen\Scaffold\Designer\SchemaLoader;
use Mooeen\Scaffold\Designer\SnapshotStore;

/**
 * plan-37 P1-1:测试基准 schema 脱钩 production yaml。
 *
 * 用法:在 beforeEach() 调 FixtureSchema::activate($app),把 scaffold.database.schema
 * 切到 tests/Feature/Designer/fixtures/database/,跑完测试调 deactivate() 还原。
 *
 * fixture 目录:
 *   tests/Feature/Designer/fixtures/database/Demo.yaml          ← 源 yaml
 *   tests/Feature/Designer/fixtures/database/.snapshots/Demo.yaml ← baseline 快照(默认跟源对齐)
 *
 * 这个 fixture 不会被 Pest 副作用 mutate,因为每个测试自带 sandbox copy(snapshotCopy())。
 */
final class FixtureSchema
{
    public const SCHEMA = 'Demo';

    public const TABLE = 'demo_users';

    public static function fixtureDir(): string
    {
        return __DIR__ . '/../fixtures/database';
    }

    public static function sourcePath(string $schema = self::SCHEMA): string
    {
        return self::fixtureDir() . '/' . $schema . '.yaml';
    }

    public static function snapshotPath(string $schema = self::SCHEMA): string
    {
        return self::fixtureDir() . '/.snapshots/' . $schema . '.yaml';
    }

    /**
     * 把当前 scaffold.database.schema 切到 fixture 目录。返回原值供 deactivate 还原。
     */
    public static function activate(Application $app): array
    {
        $cfg  = $app['config'];
        $orig = [
            'database.schema' => $cfg->get('scaffold.database.schema'),
        ];
        $cfg->set('scaffold.database.schema', self::fixtureDir() . '/');
        // 清单例 cache(SchemaLoader 内存 cache 跟 path 强绑定)
        $app->forgetInstance(SchemaLoader::class);
        $app->forgetInstance(SnapshotStore::class);

        return $orig;
    }

    public static function deactivate(Application $app, array $orig): void
    {
        $cfg = $app['config'];
        foreach ($orig as $key => $val) {
            $cfg->set('scaffold.' . $key, $val);
        }
        $app->forgetInstance(SchemaLoader::class);
        $app->forgetInstance(SnapshotStore::class);
    }
}
