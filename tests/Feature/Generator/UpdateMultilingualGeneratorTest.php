<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Generator\UpdateMultilingualGenerator;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Output\NullOutput;

/**
 * UpdateMultilingualGenerator 回归锁(此前 0 测试)。
 *
 * 整链路:generator 经 Utility 从 storage 缓存(enums.php)+ schema 目录(_fields.yaml)读数据,
 * 增量同步写 lang/{lang}/{model,db,validation}.php。
 * 测试在临时目录写最小输入(enums.php / _fields.yaml),调 start() 跑通,断言三类 lang 文件内容。
 *
 * 同时锁纯方法:escapeLangValue / stringifyLangValue / getLanguagePath。
 *
 * 全局唯一前缀 mlGen_ 避免 Pest 顶层 redeclare。临时目录 afterEach 清理。
 */
function mlGen_make(): UpdateMultilingualGenerator
{
    return new UpdateMultilingualGenerator(new NullOutput, app(Filesystem::class), app(Utility::class));
}

// 在临时 schema 目录写 _fields.yaml(getLangFields 读 table_fields + append_fields)。
function mlGen_seedFieldsYaml(array $tableFields, array $appendFields = []): void
{
    $fs        = app(Filesystem::class);
    $schemaDir = base_path('mlgen-schema/');
    $fs->ensureDirectoryExists($schemaDir);

    $lines = [];
    $emit  = function (array $fields) use (&$lines) {
        foreach ($fields as $key => $langs) {
            $parts = [];
            foreach ($langs as $lang => $word) {
                $parts[] = "'{$lang}': '{$word}'";
            }
            $lines[] = "    {$key}: { " . implode(', ', $parts) . ' }';
        }
    };

    $yaml = "append_fields:\n";
    if ($appendFields === []) {
        $yaml = "append_fields: {}\n";
    } else {
        $emit($appendFields);
        $yaml .= implode("\n", $lines) . "\n";
    }

    $lines = [];
    if ($tableFields === []) {
        $yaml .= "table_fields: {}\n";
    } else {
        $emit($tableFields);
        $yaml .= "table_fields:\n" . implode("\n", $lines) . "\n";
    }

    $fs->put($schemaDir . '_fields.yaml', $yaml);

    config()->set('scaffold.database.schema', $schemaDir);
}

// 在 storage 缓存写 enums.php(getEnumWords 读;shape: [table => [field => [alias => [val, EnName, CnName]]]])。
function mlGen_seedEnums(array $enums): void
{
    $fs  = app(Filesystem::class);
    $dir = storage_path('scaffold/');
    $fs->ensureDirectoryExists($dir);
    $fs->put($dir . 'enums.php', '<?php return ' . var_export($enums, true) . ';');
}

beforeEach(function () {
    // 隔离 storage:重定向 storage_path 到本测试独占临时目录,喂的 enums.php 写在临时 storage,
    // 绝不覆盖/删除共享 testbench storage/scaffold 下 宿主项目 派生缓存(别的 test 依赖)。
    $this->mlGen_origStorage = app()->storagePath();
    app()->useStoragePath(sys_get_temp_dir() . '/mlGen_st_' . uniqid());
    app(Filesystem::class)->ensureDirectoryExists(storage_path('scaffold'));

    config()->set('scaffold.languages', ['en', 'zh-CN']);
    config()->set('scaffold.author', 'tester');
});

afterEach(function () {
    $fs = app(Filesystem::class);
    $fs->deleteDirectory(base_path('mlgen-schema'));
    foreach (['en', 'zh-CN'] as $lang) {
        foreach (['model', 'db', 'validation'] as $file) {
            $fs->delete(lang_path("{$lang}/{$file}.php"));
        }
    }
    // 拆隔离临时 storage + 还原(共享缓存全程未被触碰)
    $fs->deleteDirectory(storage_path());
    app()->useStoragePath($this->mlGen_origStorage);
});

/* ---------------------------------------------------------------------------
 * 整链路 · start() 写三类 lang 文件
 * ------------------------------------------------------------------------ */

it('start() 增量写 db(字段)语言文件:zh 原值 / en ucwords', function () {
    mlGen_seedEnums([]);
    mlGen_seedFieldsYaml(
        tableFields: ['user_name' => ['en' => 'user name', 'zh-CN' => '用户名']],
    );

    expect(mlGen_make()->start())->toBeTrue();

    $en = require lang_path('en/db.php');
    $zh = require lang_path('zh-CN/db.php');

    // en 走 ucwords
    expect($en)->toHaveKey('user_name')->and($en['user_name'])->toBe('User Name');
    // zh-CN 原值
    expect($zh)->toHaveKey('user_name')->and($zh['user_name'])->toBe('用户名');
});

it('start() 写 model(枚举词)语言文件,key 为 {field}_{alias}', function () {
    mlGen_seedEnums([
        'orders' => [
            'order_status' => [
                'paid'   => [1, 'paid status', '已支付'],
                'unpaid' => [2, 'unpaid status', '未支付'],
            ],
        ],
    ]);
    mlGen_seedFieldsYaml(tableFields: []);

    expect(mlGen_make()->start())->toBeTrue();

    $en = require lang_path('en/model.php');
    $zh = require lang_path('zh-CN/model.php');

    expect($en)->toHaveKey('order_status_paid')->and($en['order_status_paid'])->toBe('Paid Status');
    expect($zh)->toHaveKey('order_status_unpaid')->and($zh['order_status_unpaid'])->toBe('未支付');
});

it('start() 写 validation.attributes 段(只替换 attributes,保留其余结构)', function () {
    mlGen_seedEnums([]);
    mlGen_seedFieldsYaml(
        tableFields: ['email' => ['en' => 'email address', 'zh-CN' => '邮箱']],
    );

    expect(mlGen_make()->start())->toBeTrue();

    $en = require lang_path('en/validation.php');
    $zh = require lang_path('zh-CN/validation.php');

    // attributes 段写入
    expect($en['attributes'])->toHaveKey('email')->and($en['attributes']['email'])->toBe('Email Address');
    expect($zh['attributes'])->toHaveKey('email')->and($zh['attributes']['email'])->toBe('邮箱');
    // 其余 validation 行(来自 stub)仍在
    expect($en)->toHaveKey('accepted');
    expect($zh)->toHaveKey('custom');
});

it('compileValidation · 翻译值含 $数字 不被当反向引用损坏(preg_replace_callback,2026-06-09 修)', function () {
    mlGen_seedEnums([]);
    mlGen_seedFieldsYaml(
        tableFields: ['fee' => ['en' => 'Fee $50', 'zh-CN' => '费用 $50']],
    );

    expect(mlGen_make()->start())->toBeTrue();

    $en = require lang_path('en/validation.php');
    $zh = require lang_path('zh-CN/validation.php');

    // 旧 preg_replace 把替换串里的 '$50' 当捕获组 50(空)→ 'Fee $50' 静默损坏成 'Fee '
    expect($en['attributes'])->toHaveKey('fee')->and($en['attributes']['fee'])->toBe('Fee $50');
    expect($zh['attributes']['fee'])->toBe('费用 $50');
});

it('start() 增量:已存在 lang 文件中被移除的 key 删掉,新增 key 加入', function () {
    mlGen_seedEnums([]);

    // 先放一个旧 db.php,含一个将被移除的 key
    $fs = app(Filesystem::class);
    $fs->ensureDirectoryExists(lang_path('en'));
    $fs->ensureDirectoryExists(lang_path('zh-CN'));
    $fs->put(lang_path('en/db.php'), "<?php return ['stale_key' => 'Stale', 'keep_key' => 'Keep'];");
    $fs->put(lang_path('zh-CN/db.php'), "<?php return ['stale_key' => '旧', 'keep_key' => '保留'];");

    mlGen_seedFieldsYaml(tableFields: [
        'keep_key' => ['en' => 'keep key', 'zh-CN' => '保留键'],
        'new_key'  => ['en' => 'new key', 'zh-CN' => '新键'],
    ]);

    expect(mlGen_make()->start())->toBeTrue();

    $en = require lang_path('en/db.php');

    expect($en)->not->toHaveKey('stale_key'); // 不在 schema → 删除
    expect($en)->toHaveKey('keep_key');        // 仍在 → 更新值
    expect($en)->toHaveKey('new_key');         // 新增
});

/* ---------------------------------------------------------------------------
 * 纯方法 · escapeLangValue / stringifyLangValue / getLanguagePath
 * ------------------------------------------------------------------------ */

it('escapeLangValue · 转义 PHP 单引号串(\' → \\\' / \\ → \\\\),不再用 &apos;(2026-06-11 修)', function () {
    $gen = mlGen_make();
    $ref = new ReflectionMethod($gen, 'escapeLangValue');
    $ref->setAccessible(true);

    // 撇号:escapePhpString → it\'s(进 '{$v}' 后是合法 PHP,parse 回 it's),不再损坏成字面量 &apos;
    expect($ref->invoke($gen, "it's"))->toBe("it\\'s");
    // 反斜杠:必须转义,否则值结尾 \ 会吃掉闭引号 → 语法崩
    expect($ref->invoke($gen, 'C:\\dir\\'))->toBe('C:\\\\dir\\\\');
    expect($ref->invoke($gen, '正常'))->toBe('正常');

    // 端到端:转义后放进单引号串,eval 回来必须等于原值(撇号 + 反斜杠都还原)
    foreach (["Tom's", 'path\\', "a'b\\c"] as $orig) {
        $emitted = "'" . $ref->invoke($gen, $orig) . "'";
        expect(eval("return {$emitted};"))->toBe($orig);
    }
});

it('stringifyLangValue · null/bool/int/array 各类型归一', function () {
    $gen = mlGen_make();
    $ref = new ReflectionMethod($gen, 'stringifyLangValue');
    $ref->setAccessible(true);

    expect($ref->invoke($gen, null))->toBe('');
    expect($ref->invoke($gen, 'abc'))->toBe('abc');
    expect($ref->invoke($gen, true))->toBe('1');
    expect($ref->invoke($gen, false))->toBe('0');
    expect($ref->invoke($gen, 42))->toBe('42');
    expect($ref->invoke($gen, ['a' => 1]))->toBe('{"a":1}');
});

it('getLanguagePath · 拼出 lang/{lang}/{file}.php,relative 用 . 前缀', function () {
    $gen = mlGen_make();

    $abs = $gen->getLanguagePath('db', 'en');
    expect($abs)->toEndWith('en/db.php');

    $rel = $gen->getLanguagePath('validation', 'zh-CN', true);
    expect($rel)->toContain('zh-CN/validation.php');
    expect($rel)->toStartWith('.'); // base_path 被替成 .
});

it('getLanguage · 文件不存在时回落到 stub 模板(db stub 是空 return 数组)', function () {
    $gen = mlGen_make();

    // 确保目标 lang 文件不存在 → 回落 stub
    app(Filesystem::class)->delete(lang_path('en/db.php'));

    $data = $gen->getLanguage('db', 'en');
    expect($data)->toBeArray()->toBeEmpty();
});
