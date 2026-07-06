<?php declare(strict_types=1);

use Mooeen\Scaffold\Command\SnapshotInitCommand;

/**
 * 回归锁(2026-06-18 market_services 事故):
 * moo:snapshot:init 全量 capture() 会把 yaml 所有表拍进 baseline。一张「yaml 有、但从没生成过
 * create migration」的表被吸进去后,SchemaDiffService 永远判它 unchanged → 再也不生成。
 * tablesWithoutCreateMigration 是落基线前的纯文件系统预警:列出无 create migration 的表。
 *
 * 纯静态匹配,不需 DB / fixture。
 */
it('tablesWithoutCreateMigration:无 create migration 的表被列出,有的不列', function () {
    $files = [
        '2026_06_18_102554_create_market_base_services_table.php',
        '2026_01_01_000000_create_market_carts_table.php',
    ];
    $tables = ['market_base_services', 'market_services', 'market_carts'];

    $missing = SnapshotInitCommand::tablesWithoutCreateMigration($tables, $files);

    // market_services 没有 create 文件 → 被列出(正是本次事故的表)
    expect($missing)->toBe(['market_services']);
});

it('tablesWithoutCreateMigration:market_services 不被 market_base_services 的文件误命中(后缀精确)', function () {
    // 只有 base_services 的 create 文件,market_services 仍应判缺失 —— 防 str_contains 式误匹配
    $files   = ['2026_06_18_102554_create_market_base_services_table.php'];
    $missing = SnapshotInitCommand::tablesWithoutCreateMigration(['market_services'], $files);

    expect($missing)->toBe(['market_services']);
});

it('tablesWithoutCreateMigration:全有 create 文件 → 空;全无 → 全列', function () {
    $files = [
        '2020_01_01_000000_create_users_table.php',
        '2020_01_01_000001_create_orders_table.php',
    ];
    expect(SnapshotInitCommand::tablesWithoutCreateMigration(['users', 'orders'], $files))->toBe([]);
    expect(SnapshotInitCommand::tablesWithoutCreateMigration(['users', 'orders'], []))->toBe(['users', 'orders']);
});
