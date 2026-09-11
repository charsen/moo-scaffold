<?php declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Mooeen\Scaffold\Http\Middleware\EnforceScaffoldWritable;
use Mooeen\Scaffold\Http\Middleware\ScaffoldAuthenticate;

/**
 * ConfigController HTTP Feature 测试。
 *
 * GET /scaffold/config 与 /scaffold/config/env 冒烟已被 ScaffoldRoutesTest 覆盖。
 * 这里补:
 *   - 读类:show 旧书签 302 到锚点 / 未知 group 404 / env 镜像渲染。
 *   - 写类:EnforceScaffoldWritable 被 withoutMiddleware 绕过(测控制器自身逻辑),
 *     验证 ConfigController::update 的三条分支:
 *       a) 无变更提交 → flash_message "没有变更被写入"(不碰文件);
 *       b) readonly 模式 → ConfigManager::write throw ConfigWriteForbiddenException → flash_error;
 *       c) `->where('group', '[a-z0-9_-]+')` 路由约束 → 大写 group 404。
 *
 * 不实际写 config_path('scaffold.php')/.env(Testbench 环境无该文件,且写盘有副作用):
 * 改 file/env 字段会 throw 进 flash_error,这条 catch 路径用 (b) 等价覆盖。
 */
beforeEach(function () {
    $this->withoutMiddleware([
        ScaffoldAuthenticate::class,
        VerifyCsrfToken::class,
        EnforceScaffoldWritable::class,
    ]);
    config(['scaffold.config_ui.readonly' => false]);
});

// ─── 读类 ────────────────────────────────────────────────────────────────

it('GET /scaffold/config/{group} 旧书签 302 到主页锚点', function () {
    $r = $this->get('/scaffold/config/basic');
    $r->assertRedirect();
    expect($r->headers->get('Location'))->toContain('#group-basic');
});

it('GET /scaffold/config/{unknown-group} → 404', function () {
    // group 合法 regex [a-z0-9_-]+ 但 ConfigManager::read 返回 null → abort(404)
    $this->get('/scaffold/config/nope')->assertNotFound();
});

it('GET /scaffold/config/{GROUP-大写} 被路由 where 约束 404', function () {
    // ->where('group', '[a-z0-9_-]+') 不匹配大写 → Laravel 直接 404,不进 controller
    $this->get('/scaffold/config/Basic')->assertNotFound();
});

it('GET /scaffold/config/env 渲染 env 镜像页', function () {
    $this->get('/scaffold/config/env')->assertOk();
});

it('config_ui.enabled=false → 配置 UI 整体不可访问(404,2026-06-09 接上原 dead 字段)', function () {
    config(['scaffold.config_ui.enabled' => false]);
    $this->get('/scaffold/config')->assertNotFound();
    $this->get('/scaffold/config/env')->assertNotFound();
});

// ─── 写类:update 逻辑 ───────────────────────────────────────────────────

it('POST /scaffold/config/{group} 无变更 → flash_message 没有变更被写入', function () {
    // 不提交 fields(或空)→ ConfigManager::write 写 0 处 → controller flash "没有变更被写入"
    $r = $this->post('/scaffold/config/basic', ['fields' => []]);
    $r->assertRedirect();
    expect($r->headers->get('Location'))->toContain('#group-basic');
    $r->assertSessionHas('flash_message', '没有变更被写入');
    $r->assertSessionHas('flash_group', 'basic');
});

it('POST /scaffold/config/{group} readonly 模式 → flash_error(写被拒)', function () {
    config(['scaffold.config_ui.readonly' => true]);
    // 提交一个会被判定为"有变更"的 author 值 → write 先 assertWritable() throw → catch 进 flash_error
    $r = $this->post('/scaffold/config/basic', [
        'fields' => ['author' => 'changed-author-' . uniqid()],
    ]);
    $r->assertRedirect();
    $r->assertSessionHas('flash_error');
    $r->assertSessionMissing('flash_message');
});

it('POST /scaffold/config/{group} fields 超长值 → skipped 中文提示,不写盘(cap 下沉到 write,2026-06-10)', function () {
    // 原 'fields.*' => 'string|max:2000' HTTP 规则会把 map 嵌套数组整组拒掉(hosts 保存全坏),
    // cap 改在 ConfigManager::write cast 后按类型执行:超长 → skipped + 没有变更被写入。
    $r = $this->post('/scaffold/config/basic', [
        'fields' => ['author' => str_repeat('x', 2100)],
    ]);
    $r->assertRedirect();
    $r->assertSessionHas('flash_message', '没有变更被写入');
    $r->assertSessionHas('flash_skipped');
    $r->assertSessionMissing('flash_error');
    expect(implode(' ', session('flash_skipped')))->toContain('过长');
});

it('POST hosts map(嵌套数组)保存成功 —— plan-40 验证规则曾把它整组拒掉(2026-06-10 修)', function () {
    // 真实 happy path:造最小 config/scaffold.php(PhpFileEditor 只更新已有 key)
    $cfgFile = config_path('scaffold.php');
    expect(is_file($cfgFile))->toBeFalse(); // testbench 骨架无此文件,放心创建/删除
    file_put_contents($cfgFile, "<?php\n\nreturn [\n    'hosts' => [\n        '开发' => 'http://wn.test',\n    ],\n];\n");
    config(['scaffold.hosts' => ['开发' => 'http://wn.test']]);

    try {
        $r = $this->post('/scaffold/config/hosts', [
            'fields' => ['hosts' => [
                'r0'        => ['k' => '开发', 'v' => 'http://wn.test'],
                'r1'        => ['k' => '新环境', 'v' => 'http://new.test'],
                '__present' => '1',
            ]],
        ]);
        $r->assertRedirect();
        // bug 版本:flash_error "The fields.hosts field must be a string.",零写入
        $r->assertSessionMissing('flash_error');
        $r->assertSessionHas('flash_message');
        expect((string) session('flash_message'))->toContain('已写入');
        expect(file_get_contents($cfgFile))->toContain('new.test');
    } finally {
        @unlink($cfgFile);
        @unlink(base_path('scaffold/.local/config-env-map.json'));
    }
});

it('POST int 字段清空 → 跳过不写(原 (int)"" = 0 把 ttl/timeout 写成毒药 0,2026-06-10 修)', function () {
    $before = config('scaffold.proxy.timeout');
    $r      = $this->post('/scaffold/config/proxy', [
        'fields' => ['proxy.timeout' => ''],
    ]);
    $r->assertRedirect();
    $r->assertSessionHas('flash_message', '没有变更被写入'); // bug 版本:试图写 0(testbench 无文件 → flash_error)
    $r->assertSessionMissing('flash_error');
    expect(config('scaffold.proxy.timeout'))->toBe($before);
});

it('POST 标量字段被喂数组 → 按未动处理,不 500 不报错', function () {
    $r = $this->post('/scaffold/config/basic', [
        'fields' => ['author' => ['evil' => 'array']],
    ]);
    $r->assertRedirect();
    $r->assertSessionHas('flash_message', '没有变更被写入');
    $r->assertSessionMissing('flash_error');
});

it('POST /scaffold/config/{GROUP-大写} 写路由被 where 约束 404', function () {
    $this->post('/scaffold/config/Basic', ['fields' => []])->assertNotFound();
});

// ─── AI 配置页(/scaffold/config/ai,2026-06)──────────────────────────────

it('GET /scaffold/config/ai 渲染 AI 配置页(200 而非被 {group} 通配吞掉 302)', function () {
    // 路由顺序回归锁:/config/ai 必须注册在 /config/{group} 通配之前,
    // 否则 `ai` 匹配 {group} regex [a-z0-9_-]+ → show() 302 到 #group-ai,页面消失。
    $r = $this->get('/scaffold/config/ai');
    $r->assertOk();
    $r->assertSee('AI 配置');
    $r->assertSee('base_url');
});

it('POST /scaffold/config/ai 保存写 yaml + flash_message', function () {
    // sandbox yaml(相对 base_path),避免污染 testbench skeleton 的 scaffold/ 目录
    $rel = 'scaffold/ai-test-' . uniqid() . '.yaml';
    config(['scaffold.ai.yaml_path' => $rel]);

    try {
        $r = $this->post('/scaffold/config/ai', [
            'base_url' => 'https://x.test/v1', 'api_key' => 'sk-http-test', 'temperature' => '0.3',
        ]);
        $r->assertRedirect(route('scaffold.config.ai'));
        $r->assertSessionHas('flash_message');
        expect(is_file(base_path($rel)))->toBeTrue();
        $ai = app(\Mooeen\Scaffold\Support\AiSettingStore::class)->load();
        expect($ai['api_key'])->toBe('sk-http-test');
        expect($ai['temperature'])->toBe(0.3);
    } finally {
        @unlink(base_path($rel));
    }
});

it('POST /scaffold/config/ai readonly 模式 → flash_error 不写盘', function () {
    config(['scaffold.config_ui.readonly' => true]);
    $rel = 'scaffold/ai-test-' . uniqid() . '.yaml';
    config(['scaffold.ai.yaml_path' => $rel]);

    $r = $this->post('/scaffold/config/ai', ['api_key' => 'sk-should-not-write']);
    $r->assertRedirect();
    $r->assertSessionHas('flash_error');
    expect(is_file(base_path($rel)))->toBeFalse();
});

it('env 字段保存后运行时 config 即时生效(原 re-require 后 env() 旧值 → 回显旧值像没存上,2026-06-10 修)', function () {
    // 完整 env 链路沙箱:config/scaffold.php(scanner 识别 env 来源)+ .env(EnvFileEditor 写入)
    $cfgFile  = config_path('scaffold.php');
    $envFile  = base_path('.env');
    $mapCache = base_path('scaffold/.local/config-env-map.json');
    // Testbench base_path 落在持久 vendor 目录里(夹具坑),这两文件可能已被别的用途占用。
    // 备份原内容、跑完还原:既不误删用户预存的 .env,也不受预存文件影响——
    // 替代原「断言不存在」的脆前置(一遇持久 base_path 里的残留 .env 就误红)。
    $cfgBak = is_file($cfgFile) ? file_get_contents($cfgFile) : null;
    $envBak = is_file($envFile) ? file_get_contents($envFile) : null;
    @unlink($mapCache); // 防同套件先行测试在同一秒留下的 scanner 缓存
    file_put_contents($cfgFile, "<?php\n\nreturn [\n    'author' => env('SCAFFOLD_AUTHOR', ''),\n];\n");
    file_put_contents($envFile, "SCAFFOLD_AUTHOR=old_author\n");
    config(['scaffold.author' => 'old_author']); // 模拟 boot 时 env 已载入的运行时值

    try {
        $r = $this->post('/scaffold/config/basic', ['fields' => ['author' => 'new_author']]);
        $r->assertRedirect();
        $r->assertSessionMissing('flash_error');
        expect((string) session('flash_message'))->toContain('已写入');

        // 持久层:.env 已更新
        expect(file_get_contents($envFile))->toContain('SCAFFOLD_AUTHOR=new_author');
        // 运行时:bug 版本 reloadConfig 重新 require,env() 返回进程旧值 → config 仍 old_author
        expect(config('scaffold.author'))->toBe('new_author');
    } finally {
        // 还原:有原内容写回,无则删掉本测试造的文件
        $cfgBak === null ? @unlink($cfgFile) : file_put_contents($cfgFile, $cfgBak);
        $envBak === null ? @unlink($envFile) : file_put_contents($envFile, $envBak);
        @unlink($mapCache);
    }
});

it('坏 ai.yaml(git 冲突标记)→ AI 配置页仍 200,回退默认 + 黄条提示(2026-06-10 修)', function () {
    $rel = 'scaffold/ai-broken-' . uniqid() . '.yaml';
    config(['scaffold.ai.yaml_path' => $rel]);
    file_put_contents(base_path($rel), "ai:\n<<<<<<< HEAD\n  model: a\n=======\n  model: b\n>>>>>>> other\n");

    try {
        // bug 版本:Yaml::parse 抛 ParseException → 500,修复入口自身死锁
        $r = $this->get('/scaffold/config/ai');
        $r->assertOk();
        $r->assertSee('ai.yaml 解析失败');
        // load() 回退默认而非半残数据
        $ai = app(\Mooeen\Scaffold\Support\AiSettingStore::class)->load();
        expect($ai['model'])->toBe('deepseek-chat');
    } finally {
        @unlink(base_path($rel));
    }
});

it('POST /scaffold/config/ai base_url 非法 URL → flash_error 拒写(url:http,https)', function () {
    $rel = 'scaffold/ai-test-' . uniqid() . '.yaml';
    config(['scaffold.ai.yaml_path' => $rel]);

    // 无 scheme 裸域名 + javascript: scheme 都该被 validate 拦(ValidationException → catch → flash_error)
    foreach (['api.deepseek.com/v1', 'javascript:alert(1)'] as $bad) {
        $r = $this->post('/scaffold/config/ai', ['base_url' => $bad]);
        $r->assertRedirect();
        $r->assertSessionHas('flash_error');
        // 中文校验消息(2026-06-10 修):原先英文验证原文直接糊给用户
        expect((string) session('flash_error'))->toContain('上游地址');
        expect((string) session('flash_error'))->not->toContain('The ');
    }
    expect(is_file(base_path($rel)))->toBeFalse();
});

// ─── 敏感字段不回显明文(2026-09-11 收口)──────────────────────────────────
//
// 背景:ConfigManager::resolveField 一直把**未掩码**的 raw_value 交给视图,而视图 6 个可编辑
// 分支全渲染它 → 字段一旦命中 config_ui.sensitive_keys(默认 PASSWORD/SECRET/KEY/TOKEN,按
// path 子串匹配),真值就明文进 HTML。现在改成渲染空白输入 + 「留空 = 不修改」。
// 当前 20 个字段无一命中,故测试用 sensitive_keys 覆盖把 author 变成 sensitive。

/** 造 env 链路沙箱(config/scaffold.php 认 author 为 env 来源 + .env 存真值),返回还原句柄 */
function sensitiveSandboxOn(): array
{
    $cfgFile  = config_path('scaffold.php');
    $envFile  = base_path('.env');
    $mapCache = base_path('scaffold/.local/config-env-map.json');

    $bak = [
        'cfg' => is_file($cfgFile) ? file_get_contents($cfgFile) : null,
        'env' => is_file($envFile) ? file_get_contents($envFile) : null,
    ];

    @unlink($mapCache);   // 防同套件先行测试在同一秒留下的 scanner 缓存
    file_put_contents($cfgFile, "<?php\n\nreturn [\n    'author' => env('SCAFFOLD_AUTHOR', ''),\n];\n");
    file_put_contents($envFile, "SCAFFOLD_AUTHOR=old_author\n");

    return [$cfgFile, $envFile, $mapCache, $bak];
}

function sensitiveSandboxOff(array $handle): void
{
    [$cfgFile, $envFile, $mapCache, $bak] = $handle;
    $bak['cfg'] === null ? @unlink($cfgFile) : file_put_contents($cfgFile, $bak['cfg']);
    $bak['env'] === null ? @unlink($envFile) : file_put_contents($envFile, $bak['env']);
    @unlink($mapCache);
}

it('敏感字段不回显明文:GET /scaffold/config 里既无真值、也无掩码回填', function () {
    // 把 author 变成 sensitive(按 path 子串匹配)
    config([
        'scaffold.config_ui.sensitive_keys' => ['AUTHOR'],
        'scaffold.author'                   => 'top-secret-author',
    ]);

    $r = $this->get('/scaffold/config');
    $r->assertOk();

    // bug 版本:可编辑分支渲染 $f['raw_value'] → value="top-secret-author" 明文进 HTML
    $r->assertDontSee('top-secret-author');
    // 掩码串也不能回填:回填 **** 后用户不动直接保存会把真值覆盖成 ****
    $r->assertDontSee('****');
    // 改为空白输入 + 占位提示
    $r->assertSee('留空保持原值');
});

it('敏感字段留空提交 = 不修改:map 空行不会把已有映射清空(HTTP 层不可替代的路径)', function () {
    // 为什么必须用 map 而不是标量驱动:
    //   标量表单提交的 '' / 纯空白会被 Laravel 全局中间件的 TrimStrings + ConvertEmptyStringsToNull
    //   提前中和成 null,撞上 castValueForField(null) === null 那条**既有**规则直接跳过 ——
    //   拿标量驱动测的是旧规则、不是本守卫(实测:把守卫打哑,标量版用例照样通过 = 假绿)。
    //   map 提交的是**数组**:'' 只在元素层被转成 null,顶层仍是数组,既有 null 规则兜不住,
    //   castValueForField 会返回 [] 并把已有映射整个写掉。
    $cfgFile = config_path('scaffold.php');
    $bak     = is_file($cfgFile) ? file_get_contents($cfgFile) : null;

    @unlink(base_path('scaffold/.local/config-env-map.json'));
    file_put_contents($cfgFile, "<?php\n\nreturn [\n    'hosts' => [\n        '开发' => 'http://wn.test',\n    ],\n];\n");
    config([
        'scaffold.config_ui.sensitive_keys' => ['HOSTS'],
        'scaffold.hosts'                    => ['开发' => 'http://wn.test'],
    ]);

    try {
        // 场景:用户点了「+ 添加」却留空(或误操作),然后保存别的字段
        $r = $this->post('/scaffold/config/hosts', ['fields' => ['hosts' => [
            'r0'        => ['k' => '', 'v' => ''],
            '__present' => '1',
        ]]]);
        $r->assertRedirect();
        $r->assertSessionMissing('flash_error');
        // 守卫命中 → 按"没有变更"处理(不是 skipped 警告,与"值未变"同口径)
        $r->assertSessionHas('flash_message', '没有变更被写入');
        $r->assertSessionMissing('flash_skipped');
        // 打哑守卫的版本:castValueForField → [] → 与 ['开发'=>...] 不等 → 写 hosts => [],映射被清空
        expect(file_get_contents($cfgFile))->toContain('wn.test');
    } finally {
        $bak === null ? @unlink($cfgFile) : file_put_contents($cfgFile, $bak);
        @unlink(base_path('scaffold/.local/config-env-map.json'));
    }
});

it('敏感字段 diff 掩码:改了值也不在 flash 里回显新旧明文', function () {
    $sandbox = sensitiveSandboxOn();
    config([
        'scaffold.config_ui.sensitive_keys' => ['AUTHOR'],
        'scaffold.author'                   => 'old_author',
    ]);
    [, $envFile] = $sandbox;

    try {
        $r = $this->post('/scaffold/config/basic', ['fields' => ['author' => 'brand-new-secret']]);
        $r->assertRedirect();
        $r->assertSessionMissing('flash_error');
        $r->assertSessionHas('flash_message');

        // 真的写进去了
        expect(file_get_contents($envFile))->toContain('SCAFFOLD_AUTHOR=brand-new-secret');

        // 但 flash_diff 两侧都得是掩码(config/config.php 的 sensitive_keys 注释
        // 早写明「env 镜像页 / diff 里需要掩码」,此前只有镜像页实现了)
        $diff = (string) json_encode(session('flash_diff'), JSON_UNESCAPED_UNICODE);
        expect($diff)->toContain('****');
        expect($diff)->not->toContain('brand-new-secret');
        expect($diff)->not->toContain('old_author');
    } finally {
        sensitiveSandboxOff($sandbox);
    }
});
