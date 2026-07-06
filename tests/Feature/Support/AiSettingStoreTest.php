<?php declare(strict_types=1);

use Mooeen\Scaffold\Support\AiSettingStore;
use Mooeen\Scaffold\Support\ConfigWriteForbiddenException;
use Symfony\Component\Yaml\Yaml;

/**
 * AiSettingStore 单测:默认值合并 + api_key 留空保持 + .gitignore 自我保护 + readonly 硬拒。
 *
 * AI 配置从 env 迁到 scaffold/ai.yaml(2026-06,入 git、随仓同步)。api_key 明文存储,
 * 被掩码回显冲掉 / 类型转换错都是事故 —— 这几条锁住语义。
 *
 * sandbox:setBasePath 到 temp dir,ai.yaml 落 sandbox/,跑完整目录删。
 */
beforeEach(function () {
    $this->sandbox = sys_get_temp_dir() . '/scaffold_ai_' . uniqid();
    @mkdir($this->sandbox, 0755, true);
    $this->origBase = base_path();
    app()->setBasePath($this->sandbox);
    config(['scaffold.ai.yaml_path' => 'ai.yaml']);   // → sandbox/ai.yaml（入 git 位置）
    $this->store = app(AiSettingStore::class);
});

afterEach(function () {
    app()->setBasePath($this->origBase);
    @unlink($this->sandbox . '/ai.yaml');
    @rmdir($this->sandbox);
});

it('load() 无文件时返回默认值(DeepSeek)', function () {
    $ai = $this->store->load();
    expect($ai['base_url'])->toBe('https://api.deepseek.com/v1');
    expect($ai['api_key'])->toBe('');
    expect($ai['model'])->toBe('deepseek-chat');
    expect($ai['timeout'])->toBe(10);
    // 传输 / 生成参数(借鉴 moo-scaffold-cloud)
    expect($ai['connect_timeout'])->toBe(8);
    expect($ai['max_tokens'])->toBe(8192);
    expect($ai['temperature'])->toBe(0.2);
});

it('save() 持久化传输 / 生成参数并强转类型', function () {
    $this->store->save(['temperature' => '0.5', 'max_tokens' => '4096', 'connect_timeout' => '5']);
    $ai = $this->store->load();
    expect($ai['temperature'])->toBe(0.5);       // float
    expect($ai['max_tokens'])->toBe(4096);       // int
    expect($ai['connect_timeout'])->toBe(5);     // int
});

it('temperature 钳到 [0, 2]', function () {
    $this->store->save(['temperature' => '9']);
    expect($this->store->load()['temperature'])->toBe(2.0);
    $this->store->save(['temperature' => '-3']);
    expect($this->store->load()['temperature'])->toBe(0.0);
});

it('save() 写 yaml 并能被 load() 读回', function () {
    $this->store->save(['base_url' => 'https://x.test/v1', 'api_key' => 'sk-abc', 'model' => 'gpt-x', 'timeout' => 30]);
    $ai = $this->store->load();
    expect($ai['api_key'])->toBe('sk-abc');
    expect($ai['base_url'])->toBe('https://x.test/v1');
    expect($ai['model'])->toBe('gpt-x');
    expect($ai['timeout'])->toBe(30);
});

it('api_key 留空保持原值(不被掩码回显冲掉)', function () {
    $this->store->save(['api_key' => 'sk-keep']);
    // 第二次保存只改 model,api_key 传空 → 应保持 sk-keep
    $this->store->save(['model' => 'deepseek-coder', 'api_key' => '']);
    $ai = $this->store->load();
    expect($ai['api_key'])->toBe('sk-keep');
    expect($ai['model'])->toBe('deepseek-coder');
});

it('read() 不回显明文 key,只给 api_key_set 布尔', function () {
    $this->store->save(['api_key' => 'sk-hidden']);
    $view = $this->store->read();
    expect($view)->not->toHaveKey('api_key');
    expect($view['api_key_set'])->toBeTrue();
    expect($view['defaults']['base_url'])->toBe('https://api.deepseek.com/v1');
});

it('base_url / model 存空时兜底回默认', function () {
    $this->store->save(['base_url' => '', 'model' => '']);
    $ai = $this->store->load();
    expect($ai['base_url'])->toBe('https://api.deepseek.com/v1');
    expect($ai['model'])->toBe('deepseek-chat');
});

it('readonly 模式下 save() 硬拒(ConfigWriteForbiddenException)', function () {
    config(['scaffold.config_ui.readonly' => true]);
    expect(fn () => $this->store->save(['api_key' => 'sk-x']))
        ->toThrow(ConfigWriteForbiddenException::class);
});

it('yaml 文件嵌套在 ai: 键下', function () {
    $this->store->save(['api_key' => 'sk-nested']);
    $parsed = Yaml::parse(file_get_contents($this->sandbox . '/ai.yaml'));
    expect($parsed)->toHaveKey('ai');
    expect($parsed['ai']['api_key'])->toBe('sk-nested');
});
