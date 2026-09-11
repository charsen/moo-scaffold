<?php declare(strict_types=1);

use Mooeen\Scaffold\Support\ConfigManager;

/**
 * 敏感字段「留空 = 不修改」+「diff 掩码」语义单测（2026-09-11，此前 0 测试）。
 *
 * 背景：配置表单对 sensitive 字段渲染**空白输入**（不回显明文，也不回填 `****`），
 * 所以空白提交必须按「用户没改」处理 —— 否则「改了同组别的字段顺手保存」会把敏感值
 * 覆盖成空串（静默 data loss）。
 *
 * 为什么标量必须测在 ConfigManager 层：HTTP 层 Laravel 全局中间件的 `TrimStrings` +
 * `ConvertEmptyStringsToNull` 会把 `''` / 纯空白都变成 `null`，撞上 `castValueForField(null)
 * === null` 那条**既有**规则 —— 拿标量走 HTTP 是假绿（实测确认：把守卫打哑，标量版 HTTP 用例
 * 照样通过）。而 `write()` 是公开 API，CLI / 其他包 / 测试都会直连，必须自证。
 * 只有 map（数组顶层）能绕过中间件在 HTTP 层驱动本守卫，那条在 ConfigControllerTest 里锁。
 *
 * 页面侧（无明文进 HTML）也在 ConfigControllerTest 里锁。
 */

/** 让 author 字段被判定为 sensitive（按 path 子串匹配）+ 造 env 链路沙箱 */
function configSensitiveSandboxOn(): array
{
    $cfgFile  = config_path('scaffold.php');
    $envFile  = base_path('.env');
    $mapCache = base_path('scaffold/.local/config-env-map.json');

    $bak = [
        'cfg' => is_file($cfgFile) ? file_get_contents($cfgFile) : null,
        'env' => is_file($envFile) ? file_get_contents($envFile) : null,
    ];

    @unlink($mapCache);   // 防同套件先行测试在同一秒留下的 scanner 缓存
    // author 走 env（scanner 认 env() 为 env 来源）；only_in_local 是普通 file 字面量，
    // 留着当"非敏感字段"对照组，也保证 PhpFileEditor 有可更新的已有 key。
    file_put_contents($cfgFile, "<?php\n\nreturn [\n    'author' => env('SCAFFOLD_AUTHOR', ''),\n    'only_in_local' => false,\n];\n");
    file_put_contents($envFile, "SCAFFOLD_AUTHOR=old_author\n");

    config([
        'scaffold.config_ui.sensitive_keys' => ['AUTHOR'],
        'scaffold.author'                   => 'old_author',
    ]);

    return [$cfgFile, $envFile, $mapCache, $bak];
}

function configSensitiveSandboxOff(array $handle): void
{
    [$cfgFile, $envFile, $mapCache, $bak] = $handle;
    $bak['cfg'] === null ? @unlink($cfgFile) : file_put_contents($cfgFile, $bak['cfg']);
    $bak['env'] === null ? @unlink($envFile) : file_put_contents($envFile, $bak['env']);
    @unlink($mapCache);
}

function configSensitiveIsEmpty(mixed $raw): bool
{
    $cm  = app(ConfigManager::class);
    $ref = new ReflectionMethod($cm, 'isEmptySubmission');
    $ref->setAccessible(true);

    return (bool) $ref->invoke($cm, $raw);
}

// ─── 空值判定形状 ────────────────────────────────────────────────────────

it('标量：空串 / 纯空白 / null / 空数组 = 未填；"0" 是用户显式填的值', function () {
    expect(configSensitiveIsEmpty(null))->toBeTrue();
    expect(configSensitiveIsEmpty(''))->toBeTrue();
    expect(configSensitiveIsEmpty('   '))->toBeTrue();     // 视觉上就是空输入
    expect(configSensitiveIsEmpty("\t\n"))->toBeTrue();
    expect(configSensitiveIsEmpty([]))->toBeTrue();

    expect(configSensitiveIsEmpty('0'))->toBeFalse();      // 不能把 "0" 当空
    expect(configSensitiveIsEmpty(0))->toBeFalse();
    expect(configSensitiveIsEmpty(false))->toBeFalse();    // bool 提交的 0/1 也不算空
    expect(configSensitiveIsEmpty('x'))->toBeFalse();
});

it('list：全空元素 = 未填，有一个非空即已填', function () {
    expect(configSensitiveIsEmpty(['', '', '']))->toBeTrue();
    expect(configSensitiveIsEmpty([' ', '']))->toBeTrue();
    expect(configSensitiveIsEmpty([]))->toBeTrue();

    expect(configSensitiveIsEmpty(['a']))->toBeFalse();
    expect(configSensitiveIsEmpty(['', 'a', '']))->toBeFalse();
});

it('map：只有 __present 哨兵 = 未填（UI 渲染过不等于用户填了值）', function () {
    // 哨兵是"客户端渲染过该字段"的标志，必须剔除后再判，否则敏感 map 永远判"已填"
    expect(configSensitiveIsEmpty(['__present' => '1']))->toBeTrue();
    expect(configSensitiveIsEmpty(['r0' => ['k' => '', 'v' => ''], '__present' => '1']))->toBeTrue();

    expect(configSensitiveIsEmpty(['r0' => ['k' => '开发', 'v' => ''], '__present' => '1']))->toBeFalse();
    expect(configSensitiveIsEmpty(['r0' => ['k' => '', 'v' => 'http://x.test'], '__present' => '1']))->toBeFalse();
});

// ─── 写入路径：空白不覆盖 + diff 掩码 ─────────────────────────────────────

it('敏感字段留空提交 = 不修改：不会把 .env 里的真值清空', function () {
    $sandbox     = configSensitiveSandboxOn();
    [, $envFile] = $sandbox;

    try {
        $res = app(ConfigManager::class)->write('basic', ['author' => ''], 'tester');

        // 打哑守卫的版本：'' ≠ 'old_author' → written=1 → .env 写成 SCAFFOLD_AUTHOR=（真值被抹）
        expect($res['written'])->toBe(0);
        expect($res['diff'])->toBe([]);
        expect($res['env_dirty'])->toBeFalse();
        expect(file_get_contents($envFile))->toContain('SCAFFOLD_AUTHOR=old_author');
    } finally {
        configSensitiveSandboxOff($sandbox);
    }
});

it('敏感字段纯空白提交 = 不修改（HTTP 层挡不住：ConvertEmptyStringsToNull 只处理真空串）', function () {
    $sandbox     = configSensitiveSandboxOn();
    [, $envFile] = $sandbox;

    try {
        $res = app(ConfigManager::class)->write('basic', ['author' => '   '], 'tester');

        expect($res['written'])->toBe(0);
        expect(file_get_contents($envFile))->toContain('SCAFFOLD_AUTHOR=old_author');
    } finally {
        configSensitiveSandboxOff($sandbox);
    }
});

it('敏感字段填了新值 → 正常写入，但 diff 两侧都是掩码', function () {
    $sandbox     = configSensitiveSandboxOn();
    [, $envFile] = $sandbox;

    try {
        $res = app(ConfigManager::class)->write('basic', ['author' => 'brand-new-secret'], 'tester');

        expect($res['written'])->toBe(1);
        // 真值确实落盘了（不是"什么都不写"）
        expect(file_get_contents($envFile))->toContain('SCAFFOLD_AUTHOR=brand-new-secret');

        // config/config.php 的 sensitive_keys 注释早写明「env 镜像页 / diff 里需要掩码」，
        // 但此前只有镜像页实现了；diff 会经 flash_diff 直接渲染进页面 → 改一次就回显一次旧值。
        expect($res['diff']['author'])->toBe(['****', '****']);
    } finally {
        configSensitiveSandboxOff($sandbox);
    }
});

it('敏感字段的「默认值」列也掩码：packageDefault 是 require 包 config，env() 会带出真值', function () {
    // 包 config 里 `auth.cookie_name` 默认是字面量 'scaffold_auth'，确定性可断言。
    // 这条走的是「包默认值」列（视图 index.blade.php 的 $f['default']），此前**无掩码**——
    // 而 packageDefault() 是直接 require 包的 config.php，字段若写成 env('SOME_SECRET', '')
    // 就会把部署环境里的真值带进"默认"列明文回显。
    config(['scaffold.config_ui.sensitive_keys' => ['COOKIE']]);

    $fields = collect(app(ConfigManager::class)->read('auth')['fields'])->keyBy('path');

    expect($fields['auth.cookie_name']['sensitive'])->toBeTrue();
    expect($fields['auth.cookie_name']['default'])->toBe('****');       // bug 版本:'scaffold_auth'

    // 同组非敏感字段的默认值不受影响（别把普通字段也打星）
    expect($fields['auth.ttl_minutes']['sensitive'])->toBeFalse();
    expect($fields['auth.ttl_minutes']['default'])->not->toBe('****');
});

it('非敏感字段的 diff 不受掩码影响（别把普通字段也打星）', function () {
    $sandbox = configSensitiveSandboxOn();
    try {
        // 只把 author 视为敏感：同为 basic 组的 only_in_local(bool) 应保持明文 diff
        config(['scaffold.only_in_local' => true]);
        $res = app(ConfigManager::class)->write('basic', ['only_in_local' => '0'], 'tester');

        expect($res['diff']['only_in_local'])->toBe([true, false]);
    } finally {
        configSensitiveSandboxOff($sandbox);
    }
});
