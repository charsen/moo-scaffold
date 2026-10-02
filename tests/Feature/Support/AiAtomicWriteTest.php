<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Support\AiSettingStore;

beforeEach(function () {
    $this->directory = sys_get_temp_dir() . '/ai_atomic_' . uniqid();
    mkdir($this->directory, 0755, true);
    $this->originalBase = base_path();
    app()->setBasePath($this->directory);
    app()->instance('env', 'local');
    config(['scaffold.ai.yaml_path' => 'ai.yaml', 'scaffold.config_ui.readonly' => false]);
    $this->store = new AiSettingStore(app('config'), new Filesystem);
    $this->old   = "ai:\n  api_key: E2eFixtureOnly\n  model: old-model\n";
});

afterEach(function () {
    app()->setBasePath($this->originalBase);
    (new Filesystem)->deleteDirectory($this->directory);
});

it('AI config save preserves old readers, the existing key and private permissions', function () {
    $path = $this->directory . '/ai.yaml';
    file_put_contents($path, $this->old);
    chmod($path, 0600);
    $reader = fopen($path, 'r');
    try {
        $this->store->save(['model' => 'new-model', 'api_key' => '']);
        expect(stream_get_contents($reader))->toBe($this->old);
        expect($this->store->load())->toMatchArray(['api_key' => 'E2eFixtureOnly', 'model' => 'new-model']);
        clearstatcache(true, $path);
        expect(fileperms($path) & 07777)->toBe(0600);
        expect(glob($path . '.tmp.*'))->toBe([]);
    } finally {
        fclose($reader);
    }
});

it('AI config save preserves a valid symlink and updates its configured target', function () {
    $target = $this->directory . '/target.yaml';
    $link   = $this->directory . '/ai.yaml';
    file_put_contents($target, $this->old);
    chmod($target, 0640);
    symlink('target.yaml', $link);
    $this->store->save(['model' => 'new-model']);
    expect(is_link($link))->toBeTrue();
    expect($this->store->load()['model'])->toBe('new-model');
    clearstatcache(true, $target);
    expect(fileperms($target) & 07777)->toBe(0640);
});

it('AI config save rejects a dangling symlink without replacing it', function () {
    $link = $this->directory . '/ai.yaml';
    symlink('missing.yaml', $link);
    expect(fn () => $this->store->save(['model' => 'new-model']))
        ->toThrow(RuntimeException::class, '写入失败，AI 配置未变更');
    expect(is_link($link))->toBeTrue();
    expect(file_exists($this->directory . '/missing.yaml'))->toBeFalse();
});

it('AI config write failure preserves the existing configuration', function () {
    $path = $this->directory . '/ai.yaml';
    file_put_contents($path, $this->old);
    chmod($this->directory, 0555);
    clearstatcache();
    try {
        expect(fn () => $this->store->save(['model' => 'new-model']))
            ->toThrow(RuntimeException::class, '写入失败，AI 配置未变更');
        expect(file_get_contents($path))->toBe($this->old);
        expect(glob($path . '.tmp.*'))->toBe([]);
    } finally {
        chmod($this->directory, 0755);
    }
})->skip(fn () => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root 不受目录权限约束');

it('AI config cannot replace a read-only file through a writable directory', function () {
    $path = $this->directory . '/ai.yaml';
    file_put_contents($path, $this->old);
    chmod($path, 0400);
    clearstatcache();
    expect(fn () => $this->store->save(['model' => 'new-model']))
        ->toThrow(RuntimeException::class, '写入失败，AI 配置未变更');
    expect(file_get_contents($path))->toBe($this->old);
})->skip(fn () => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root 不受文件权限约束');
