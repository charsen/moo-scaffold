<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Designer\MigrationWriter;
use Mooeen\Scaffold\Designer\SnapshotStore;

/**
 * MigrationWriter 单测。
 *
 * render() 覆盖 created / updated / dropped 三类 status 的 PHP 源码生成路径(synthetic diff)。
 * write() 用 Spy SnapshotStore 验 captureTables 调用(plan-37 P1-5)。
 *
 * Plan 39:GUI 不再调 git commit,write() 签名简化为 write($diff) 单参,无 commit / commit_message。
 */
beforeEach(function () {
    $this->writer = app(MigrationWriter::class);
});

it('render skips unchanged tables (no entry in output)', function () {
    // 用 fake diff 强制有 unchanged + updated 混合,避免依赖 Platform 真实状态导致 risky
    $fakeDiff = [
        'schema' => 'Demo',
        'tables' => [
            't_unchanged' => ['status' => 'unchanged', 'field_changes' => [], 'index_changes' => []],
            't_updated'   => [
                'status'              => 'updated',
                'baseline_definition' => ['fields' => [], 'index' => []],
                'current_definition'  => ['fields' => [], 'index' => []],
                'field_changes'       => [],
                'index_changes'       => [],
                'warnings'            => [],
            ],
        ],
    ];
    $rendered = $this->writer->render($fakeDiff);
    expect($rendered)->not->toHaveKey('t_unchanged');
    expect($rendered)->toHaveKey('t_updated');
});

it('render returns filename + php_source for each non-unchanged table (synthetic updated diff)', function () {
    // plan 36 后 production schemas 在快照刚 capture 时无 drift,改用 synthetic diff 锚定 writer 行为
    $fakeDiff = [
        'schema'   => 'Demo',
        'is_empty' => false,
        'tables'   => [
            'demo_users' => [
                'status'              => 'updated',
                'baseline_definition' => ['fields' => [], 'index' => []],
                'current_definition'  => ['fields' => [], 'index' => []],
                'field_changes'       => [
                    ['op' => 'add', 'field' => 'nickname', 'definition' => ['type' => 'varchar', 'size' => 64, 'name' => '昵称']],
                ],
                'index_changes' => [],
                'warnings'      => [],
            ],
        ],
    ];
    $rendered = $this->writer->render($fakeDiff);
    expect($rendered)->toHaveKey('demo_users');
    $r = $rendered['demo_users'];
    expect($r)->toHaveKey('filename');
    expect($r)->toHaveKey('php_source');
    expect($r['filename'])->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_update_demo_users_table\.php$/');
    expect($r['php_source'])->toContain('<?php');
    expect($r['php_source'])->toContain('return new class');
    expect($r['php_source'])->toContain('public function up');
    expect($r['php_source'])->toContain('public function down');
});

it('render emits Schema::table for updated status (synthetic)', function () {
    $fakeDiff = [
        'schema'   => 'Demo',
        'is_empty' => false,
        'tables'   => [
            'demo_users' => [
                'status'              => 'updated',
                'baseline_definition' => ['fields' => [], 'index' => []],
                'current_definition'  => ['fields' => [], 'index' => []],
                'field_changes'       => [
                    ['op' => 'add', 'field' => 'nickname', 'definition' => ['type' => 'varchar', 'size' => 64, 'name' => '昵称']],
                ],
                'index_changes' => [],
                'warnings'      => [],
            ],
        ],
    ];
    $rendered = $this->writer->render($fakeDiff);
    expect($rendered['demo_users']['php_source'])->toContain("Schema::table('demo_users'");
});

it('render handles created status: emits Schema::create + Schema::dropIfExists', function () {
    // 构造一个 created table 的 fake diff(SchemaDiffService::createdTableDiff 同结构)
    $fakeDiff = [
        'schema'   => 'Demo',
        'is_empty' => false,
        'tables'   => [
            'demo_users' => [
                'status'             => 'created',
                'current_definition' => [
                    'name'   => '演示用户',
                    'fields' => [
                        'id'         => ['type' => 'bigint', 'auto_increment' => true],
                        'user_name'  => ['type' => 'varchar', 'size' => 64, 'name' => '用户名'],
                        'created_at' => ['type' => 'timestamp'],
                        'updated_at' => ['type' => 'timestamp'],
                    ],
                    'index' => [],
                ],
                'field_changes' => [],
                'index_changes' => [],
                'warnings'      => [],
            ],
        ],
    ];
    $rendered = $this->writer->render($fakeDiff);
    expect($rendered)->toHaveKey('demo_users');
    $php = $rendered['demo_users']['php_source'];
    expect($php)->toContain("Schema::create('demo_users'");
    expect($php)->toContain("Schema::dropIfExists('demo_users')");
    expect($php)->toContain('user_name');
    expect($rendered['demo_users']['filename'])->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_create_demo_users_table\.php$/');
});

it('render respects schema-defined id name, type and increment behavior', function () {
    config()->set('scaffold.snow_flake_id', false);

    $fakeDiff = [
        'schema'   => 'Demo',
        'is_empty' => false,
        'tables'   => [
            'uuid_docs' => [
                'status'             => 'created',
                'current_definition' => [
                    'name'   => 'UUID 文档',
                    'fields' => [
                        'id'        => ['name' => 'ID', 'type' => 'char', 'size' => 36],
                        'doc_title' => ['type' => 'varchar', 'size' => 64, 'name' => '标题'],
                    ],
                    'index' => [],
                ],
                'field_changes' => [],
                'index_changes' => [],
                'warnings'      => [],
            ],
            'snowflake_docs' => [
                'status'             => 'created',
                'current_definition' => [
                    'name'   => '雪花 ID 文档',
                    'fields' => [
                        'id'        => ['name' => 'ID', 'type' => 'bigint', 'increment' => false],
                        'doc_title' => ['type' => 'varchar', 'size' => 64, 'name' => '标题'],
                    ],
                    'index' => [],
                ],
                'field_changes' => [],
                'index_changes' => [],
                'warnings'      => [],
            ],
        ],
    ];

    $rendered = $this->writer->render($fakeDiff);

    expect($rendered['uuid_docs']['php_source'])
        ->toContain("\$table->char('id', 36)->primary()->comment('ID');");
    expect($rendered['snowflake_docs']['php_source'])
        ->toContain("\$table->unsignedBigInteger('id')->primary()->comment('ID');")
        ->not->toContain("\$table->unsignedBigInteger('id', true)->primary()->comment('ID');");
});

it('render handles dropped status: emits Schema::dropIfExists + recreate down()', function () {
    $fakeDiff = [
        'schema'   => 'Demo',
        'is_empty' => false,
        'tables'   => [
            'old_table' => [
                'status'              => 'dropped',
                'baseline_definition' => [
                    'name'   => '旧表',
                    'fields' => [
                        'id'   => ['type' => 'bigint', 'auto_increment' => true],
                        'data' => ['type' => 'varchar', 'size' => 128],
                    ],
                    'index' => [],
                ],
                'field_changes' => [],
                'index_changes' => [],
                'warnings'      => [],
            ],
        ],
    ];
    $rendered = $this->writer->render($fakeDiff);
    expect($rendered)->toHaveKey('old_table');
    $php = $rendered['old_table']['php_source'];
    expect($php)->toContain("Schema::dropIfExists('old_table')");
    expect($php)->toContain('data');     // down() recreates field
    expect($rendered['old_table']['filename'])->toMatch('/_drop_old_table_table\.php$/');
});

it('render emits renameColumn for rename op in field_changes', function () {
    $fakeDiff = [
        'schema'   => 'Demo',
        'is_empty' => false,
        'tables'   => [
            'demo_users' => [
                'status'              => 'updated',
                'baseline_definition' => [
                    'fields' => ['user_avatar' => ['type' => 'varchar', 'size' => 64]],
                    'index'  => [],
                ],
                'current_definition' => [
                    'fields' => ['user_thumb' => ['type' => 'varchar', 'size' => 64]],
                    'index'  => [],
                ],
                'field_changes' => [
                    ['op' => 'rename', 'from' => 'user_avatar', 'to' => 'user_thumb', 'after' => ['type' => 'varchar', 'size' => 64]],
                ],
                'index_changes' => [],
                'warnings'      => [],
            ],
        ],
    ];
    $php = $this->writer->render($fakeDiff)['demo_users']['php_source'];
    expect($php)->toContain("\$table->renameColumn('user_avatar', 'user_thumb')");
});

it('render emits dropColumn for drop op in field_changes', function () {
    $fakeDiff = [
        'schema'   => 'Demo',
        'is_empty' => false,
        'tables'   => [
            'demo_users' => [
                'status'              => 'updated',
                'baseline_definition' => [
                    'fields' => ['legacy_col' => ['type' => 'varchar', 'size' => 32]],
                    'index'  => [],
                ],
                'current_definition' => [
                    'fields' => [],
                    'index'  => [],
                ],
                'field_changes' => [
                    ['op' => 'drop', 'field' => 'legacy_col', 'definition' => ['type' => 'varchar', 'size' => 32]],
                ],
                'index_changes' => [],
                'warnings'      => [],
            ],
        ],
    ];
    $php = $this->writer->render($fakeDiff)['demo_users']['php_source'];
    expect($php)->toContain("\$table->dropColumn('legacy_col')");
});

it('render returns empty array when all tables unchanged', function () {
    $emptyDiff = [
        'schema'   => 'Demo',
        'is_empty' => true,
        'tables'   => [
            'users'  => ['status' => 'unchanged', 'field_changes' => [], 'index_changes' => []],
            'orders' => ['status' => 'unchanged', 'field_changes' => [], 'index_changes' => []],
        ],
    ];
    expect($this->writer->render($emptyDiff))->toBe([]);
});

it('pickFilename uses Y_m_d_His timestamp with verb matching status', function () {
    // 端到端验:filename 起头 Y_m_d_His(完整到秒)+ verb 对应 status
    $fake = [
        'schema' => 'Demo',
        'tables' => [
            't_create' => ['status' => 'created', 'current_definition' => ['fields' => [], 'index' => []]],
        ],
    ];
    $r = $this->writer->render($fake);
    // Y_m_d_His = 4_2_2_6,末段 6 位 = HHmmss(2026-05-23 改;旧 Y_m_d_H + %04d seq 全填 0 失真)
    expect($r['t_create']['filename'])->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_create_t_create_table\.php$/');
});

// ─── plan-40 §六 P1 #1:enum-aware default ─────────────────────────────────────────

it('render resolves enum key to int value for int-type default (created status)', function () {
    // yaml `default: highlight` + enums.note_type.highlight=[1,...] → migration ->default(1)
    $fakeDiff = [
        'schema'   => 'Demo',
        'is_empty' => false,
        'tables'   => [
            'demo_notes' => [
                'status'             => 'created',
                'current_definition' => [
                    'name'   => '演示笔记',
                    'fields' => [
                        'id'        => [],
                        'note_type' => ['type' => 'tinyint', 'default' => 'highlight', 'name' => '类型'],
                    ],
                    'index' => [],
                    'enums' => [
                        'note_type' => [
                            'highlight' => [1, 'Highlight', '高亮'],
                            'note'      => [2, 'Note', '笔记'],
                        ],
                    ],
                ],
                'field_changes' => [], 'index_changes' => [], 'warnings' => [],
            ],
        ],
    ];
    $php = $this->writer->render($fakeDiff)['demo_notes']['php_source'];
    expect($php)->toContain('->default(1)');     // 'highlight' → 1 ✓
    expect($php)->not->toContain("->default('highlight')");     // 不能裸写字符串
    expect($php)->not->toContain('->default(0)');     // (int)'highlight' = 0 是 bug,不能出
});

it('render resolves enum key on updated status (add op via field_changes)', function () {
    $fakeDiff = [
        'schema'   => 'Demo',
        'is_empty' => false,
        'tables'   => [
            'demo_notes' => [
                'status'              => 'updated',
                'baseline_definition' => ['fields' => [], 'index' => [], 'enums' => []],
                'current_definition'  => [
                    'fields' => [],
                    'index'  => [],
                    'enums'  => [
                        'note_pinned' => ['yes' => [1, 'Yes', '是'], 'no' => [2, 'No', '否']],
                    ],
                ],
                'field_changes' => [
                    ['op'            => 'add', 'field' => 'note_pinned',
                        'definition' => ['type' => 'tinyint', 'default' => 'no', 'name' => '置顶']],
                ],
                'index_changes' => [], 'warnings' => [],
            ],
        ],
    ];
    $php = $this->writer->render($fakeDiff)['demo_notes']['php_source'];
    expect($php)->toContain('->default(2)');     // 'no' → 2 ✓
});

it('render leaves non-enum string default untouched (varchar)', function () {
    // varchar 字段 default 不走 enum 解析,原样 emit
    $fakeDiff = [
        'schema'   => 'Demo',
        'is_empty' => false,
        'tables'   => [
            'demo_t' => [
                'status'             => 'created',
                'current_definition' => [
                    'fields' => [
                        'status' => ['type' => 'varchar', 'size' => 16, 'default' => 'pending'],
                    ],
                    'index' => [], 'enums' => [],
                ],
                'field_changes' => [], 'index_changes' => [], 'warnings' => [],
            ],
        ],
    ];
    $php = $this->writer->render($fakeDiff)['demo_t']['php_source'];
    expect($php)->toContain("->default('pending')");     // varchar string default 原样
});

it('render falls through to int cast when enum key not found', function () {
    // default 是 string 但 enums 块没匹配 key → 走旧的 (int) cast 路径(归 0)
    // 这种情况 SchemaLoader::loadNormalized 会 emit warning,但 MigrationWriter 不阻塞
    $fakeDiff = [
        'schema'   => 'Demo',
        'is_empty' => false,
        'tables'   => [
            'demo_t' => [
                'status'             => 'created',
                'current_definition' => [
                    'fields' => [
                        'note_type' => ['type' => 'tinyint', 'default' => 'unknown_key'],
                    ],
                    'index' => [], 'enums' => ['note_type' => ['highlight' => [1, 'X', '亮']]],
                ],
                'field_changes' => [], 'index_changes' => [], 'warnings' => [],
            ],
        ],
    ];
    $php = $this->writer->render($fakeDiff)['demo_t']['php_source'];
    expect($php)->toContain('->default(0)');     // (int)'unknown_key' = 0
});

// ─── multi-field index(designer F30):fields 以数组写 yaml,不能撞 indexColumnsExpr(string) ───

it('render emits multi-field index from array fields (created status)', function () {
    // designer GUI 建多字段索引 → SchemaLoader::rebuildTableIndex 写 fields: [a, b](数组)。
    // 回归 anchor:strict_types 下数组撞 indexColumnsExpr(string) 形参会 TypeError。
    $fakeDiff = [
        'schema'   => 'Demo',
        'is_empty' => false,
        'tables'   => [
            'demo_staff' => [
                'status'             => 'created',
                'current_definition' => [
                    'name'   => '演示员工',
                    'fields' => [
                        'id'            => [],
                        'department_id' => ['type' => 'bigint', 'name' => '部门'],
                        'position_id'   => ['type' => 'bigint', 'name' => '岗位'],
                    ],
                    'index' => [
                        'idx_dept_pos' => ['type' => 'index', 'fields' => ['department_id', 'position_id']],
                    ],
                ],
                'field_changes' => [], 'index_changes' => [], 'warnings' => [],
            ],
        ],
    ];
    $php = $this->writer->render($fakeDiff)['demo_staff']['php_source'];
    expect($php)->toContain("\$table->index(['department_id', 'position_id'], 'idx_dept_pos');");
});

it('render emits multi-field unique from array fields (created status)', function () {
    $fakeDiff = [
        'schema'   => 'Demo',
        'is_empty' => false,
        'tables'   => [
            'demo_link' => [
                'status'             => 'created',
                'current_definition' => [
                    'name'   => '演示关联',
                    'fields' => [
                        'id'      => [],
                        'user_id' => ['type' => 'bigint', 'name' => '用户'],
                        'role_id' => ['type' => 'bigint', 'name' => '角色'],
                    ],
                    'index' => [
                        'uniq_user_role' => ['type' => 'unique', 'fields' => ['user_id', 'role_id']],
                    ],
                ],
                'field_changes' => [], 'index_changes' => [], 'warnings' => [],
            ],
        ],
    ];
    $php = $this->writer->render($fakeDiff)['demo_link']['php_source'];
    expect($php)->toContain("\$table->unique(['user_id', 'role_id'], 'uniq_user_role');");
});

it('render emits multi-field index from index_changes add (updated / ALTER path)', function () {
    $fakeDiff = [
        'schema'   => 'Demo',
        'is_empty' => false,
        'tables'   => [
            'demo_staff' => [
                'status'              => 'updated',
                'baseline_definition' => ['fields' => [], 'index' => []],
                'current_definition'  => ['fields' => [], 'index' => []],
                'field_changes'       => [],
                'index_changes'       => [
                    ['op' => 'add', 'name' => 'idx_dept_pos', 'type' => 'index', 'fields' => ['department_id', 'position_id']],
                ],
                'warnings' => [],
            ],
        ],
    ];
    $php = $this->writer->render($fakeDiff)['demo_staff']['php_source'];
    expect($php)->toContain("\$table->index(['department_id', 'position_id'], 'idx_dept_pos');");
});

it('render normalizes single-element array fields to scalar column (no array brackets)', function () {
    // 单元素数组归一为标量:$table->index('only_col', ...) 而非 (['only_col'], ...)
    $fakeDiff = [
        'schema'   => 'Demo',
        'is_empty' => false,
        'tables'   => [
            'demo_one' => [
                'status'             => 'created',
                'current_definition' => [
                    'name'   => '演示单列',
                    'fields' => ['id' => [], 'only_col' => ['type' => 'bigint', 'name' => '单列']],
                    'index'  => ['idx_only' => ['type' => 'index', 'fields' => ['only_col']]],
                ],
                'field_changes' => [], 'index_changes' => [], 'warnings' => [],
            ],
        ],
    ];
    $php = $this->writer->render($fakeDiff)['demo_one']['php_source'];
    expect($php)->toContain("\$table->index('only_col', 'idx_only');");
    expect($php)->not->toContain("['only_col']");
});

// ─── 2026-06-09:float/double 对齐 Laravel 12 签名 / timestamp current / 单 timestamp ───

it('render emits Laravel 12 float/double signature (no legacy 3-arg total,places)', function () {
    $fakeDiff = [
        'schema'   => 'Demo',
        'is_empty' => false,
        'tables'   => [
            'demo_t' => [
                'status'             => 'created',
                'current_definition' => [
                    'name'   => '演示',
                    'fields' => [
                        'id'    => [],
                        'rate'  => ['type' => 'float',  'size' => 8, 'precision' => 2, 'name' => '率'],
                        'ratio' => ['type' => 'double', 'size' => 8, 'precision' => 2, 'name' => '比'],
                    ],
                    'index' => [], 'enums' => [],
                ],
                'field_changes' => [], 'index_changes' => [], 'warnings' => [],
            ],
        ],
    ];
    $php = $this->writer->render($fakeDiff)['demo_t']['php_source'];
    expect($php)->toContain("\$table->float('rate')");
    expect($php)->toContain("\$table->double('ratio')");
    // L12 float($col,$precision=53) / double($col) — 旧 3 参会把 size 当 precision / 全忽略
    expect($php)->not->toContain("float('rate', 8, 2)");
    expect($php)->not->toContain("double('ratio', 8, 2)");
});

it('render emits ->useCurrent() for timestamp default current (not ->default(current) → migrate 1067)', function () {
    $fakeDiff = [
        'schema'   => 'Demo',
        'is_empty' => false,
        'tables'   => [
            'demo_t' => [
                'status'             => 'created',
                'current_definition' => [
                    'name'   => '演示',
                    'fields' => [
                        'id'      => [],
                        'seen_at' => ['type' => 'timestamp', 'default' => 'current', 'required' => false, 'name' => '时间'],
                    ],
                    'index' => [], 'enums' => [],
                ],
                'field_changes' => [], 'index_changes' => [], 'warnings' => [],
            ],
        ],
    ];
    $php = $this->writer->render($fakeDiff)['demo_t']['php_source'];
    expect($php)->toContain('->useCurrent()');
    expect($php)->not->toContain("->default('current')");
});

it('render emits a lone updated_at as a timestamp column (not silently dropped)', function () {
    $fakeDiff = [
        'schema'   => 'Demo',
        'is_empty' => false,
        'tables'   => [
            'demo_t' => [
                'status'             => 'created',
                'current_definition' => [
                    'name'   => '演示',
                    'fields' => [
                        'id'         => [],
                        'data'       => ['type' => 'varchar', 'size' => 32, 'name' => '数据'],
                        'updated_at' => ['type' => 'timestamp'],     // 只有 updated_at,没 created_at
                    ],
                    'index' => [], 'enums' => [],
                ],
                'field_changes' => [], 'index_changes' => [], 'warnings' => [],
            ],
        ],
    ];
    $php = $this->writer->render($fakeDiff)['demo_t']['php_source'];
    expect($php)->toContain("timestamp('updated_at')");      // 单个作为普通列 emit
    expect($php)->not->toContain('$table->timestamps()');    // 不折成 timestamps()(那会建两列)
});

// ─── plan-37 P1-5 / plan-39:write() 必须 captureTables 本次写过的表 ──────────────────────

/** Spy SnapshotStore — 记录 captureTables 调用,不真写盘 */
class SpySnapshotStore extends SnapshotStore
{
    public array $captureTablesCalls = [];

    public array $captureCalls = [];

    public function capture(string $schema): void
    {
        $this->captureCalls[] = $schema;
    }

    public function captureTables(string $schema, array $tableKeys): void
    {
        $this->captureTablesCalls[] = ['schema' => $schema, 'tables' => $tableKeys];
    }
}

it('write() captures only rendered tables via captureTables (P1-5)', function () {
    // Spy 替换 SnapshotStore,跑 write() 验调用
    $spy = new SpySnapshotStore(app(Filesystem::class));
    app()->instance(SnapshotStore::class, $spy);
    $writer = app()->make(MigrationWriter::class);     // 重新拿,注入新 spy

    $tempDir = sys_get_temp_dir() . '/p15_test_' . uniqid();
    mkdir($tempDir, 0755, true);
    // MigrationWriter::migrationPath() 用 database_path('migrations'),改 useDatabasePath
    app()->useDatabasePath($tempDir);

    try {
        $fakeDiff = [
            'schema'   => 'Demo',
            'is_empty' => false,
            'tables'   => [
                'demo_a' => [
                    'status'              => 'updated',
                    'baseline_definition' => ['fields' => [], 'index' => []],
                    'current_definition'  => ['fields' => [], 'index' => []],
                    'field_changes'       => [['op' => 'add', 'field' => 'x', 'definition' => ['type' => 'varchar', 'size' => 32]]],
                    'index_changes'       => [],
                    'warnings'            => [],
                ],
                'demo_unchanged' => ['status' => 'unchanged', 'field_changes' => [], 'index_changes' => []],
            ],
        ];

        // Plan 39:write() 单参,GUI 不调 commit
        $result = $writer->write($fakeDiff);

        expect($result)->toHaveKey('files_written');
        expect($result)->not->toHaveKey('git_committed');     // plan 39 砍
        expect($result)->not->toHaveKey('commit_sha');        // plan 39 砍

        expect($spy->captureTablesCalls)->toHaveCount(1);
        expect($spy->captureTablesCalls[0]['schema'])->toBe('Demo');
        // 关键:只 capture render() 实际生成的表(demo_a),不包括 demo_unchanged
        expect($spy->captureTablesCalls[0]['tables'])->toBe(['demo_a']);
    } finally {
        // cleanup
        foreach (glob($tempDir . '/migrations/*.php') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($tempDir . '/migrations');
        @rmdir($tempDir);
    }
});

// ─── plan-40 §三 R-6:atomic claim — fopen('xb') 防 web+CLI 同分钟撞 filename ─────

it('R-6 · MigrationWriter.php 源码必须含 atomicWriteWithRetry + fopen xb 模式', function () {
    // anchor 测试:防 R-6 实现被误删 / 改成非原子的 file_put_contents
    $src = file_get_contents(__DIR__ . '/../../../src/Designer/MigrationWriter.php');
    expect($src)->toContain('atomicWriteWithRetry');
    expect($src)->toContain("fopen(\$abs, 'xb')");
    expect($src)->toContain('plan-40 §三 R-6');
});

it("R-6 · fopen 'xb' 模式行为 — 已存在文件 fopen 返 false 触发 retry", function () {
    // 在 tmp 验 fopen 'xb' 原子语义(不依赖 MigrationWriter,纯 PHP fopen 行为)
    $tmp = tempnam(sys_get_temp_dir(), 'r6_');
    file_put_contents($tmp, 'first writer');     // 占名
    $fh = @fopen($tmp, 'xb');
    expect($fh)->toBeFalse();     // x 模式撞已存在文件必须 fail(原子语义)
    @unlink($tmp);

    // 干净 tmp 路径上 fopen 'xb' 应成功并创建文件
    $fresh = sys_get_temp_dir() . '/r6_atomic_' . uniqid();
    $fh    = @fopen($fresh, 'xb');
    expect($fh)->not->toBeFalse();
    fwrite($fh, 'content');
    fclose($fh);
    expect(file_get_contents($fresh))->toBe('content');
    @unlink($fresh);
});
