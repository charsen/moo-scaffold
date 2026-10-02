<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Support\Concerns\AtomicFileWrite;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Designer AI 翻译配置存储（plan 19 → 2026-06 GUI 化）。
 *
 * 原本走 SCAFFOLD_AI_* env，改为在 /scaffold/config/ai 可视化编辑，存
 * scaffold/ai.yaml（跟 accounts.yaml 一样**入 git**，随仓同步到各机器/部署）：
 *   - 运行时读 yaml（不走 env）→ config:cache 安全；改完即时生效，无需重启 worker。
 *   - ⚠ api_key 以**明文**存储（用户 2026-06-10 明确选定 plaintext-in-git，已知会进 git
 *     history → 请确保本仓私有）。要换 secret-safe 存法见 git log 当时的方案对比。
 *
 * 写入策略：每次整体重写（不增量 patch），历史回溯走 git。
 * 删文件即恢复默认（api_key 清空 = AI 翻译关闭）。production / 强制只读时硬拒。
 */
class AiSettingStore
{
    use AtomicFileWrite;

    /**
     * 默认上游（DeepSeek）。api_key 默认空 = 未配置（翻译关闭）。
     *
     * 传输 / 生成参数（temperature / max_tokens / connect_timeout）借鉴 moo-scaffold-cloud
     * 的 AiConfig::DEFAULTS：
     *   - temperature 0.2 —— 中文→snake_case 是确定性任务，低温让同字段多次翻译稳定不飘
     *     （不传时 DeepSeek 默认 1.0 偏高；DeepSeek 官方建议 coding 类 ~0.0）。
     *   - max_tokens 8192 —— 单次生成上限；批量字段翻译够用，留足。
     *   - connect_timeout 8 —— 连接超时，区别于总 timeout。
     * 类型即来源：这里写 int/float，load() 据此强转。
     */
    private const DEFAULTS = [
        'base_url'        => 'https://api.deepseek.com/v1',
        'api_key'         => '',
        'model'           => 'deepseek-chat',
        'timeout'         => 10,
        'connect_timeout' => 8,
        'max_tokens'      => 8192,
        'temperature'     => 0.2,
    ];

    /** 最近一次 load() 是否遇到坏 yaml(解析失败回退默认时置真,供页面黄条提示)。 */
    private bool $yamlBroken = false;

    public function __construct(
        private readonly Repository $config,
        private readonly Filesystem $fs,
    ) {}

    public function path(): string
    {
        $rel = (string) $this->config->get('scaffold.ai.yaml_path', 'scaffold/ai.yaml');

        return $this->basePath($rel);
    }

    public function exists(): bool
    {
        return $this->fs->exists($this->path());
    }

    /**
     * 合并默认值 + yaml 文件，返回归一后的 base_url / api_key / model / timeout。
     * TranslationService binding 直接吃这个。
     *
     * @return array{base_url:string, api_key:string, model:string, timeout:int, connect_timeout:int, max_tokens:int, temperature:float}
     */
    public function load(): array
    {
        $data             = self::DEFAULTS;
        $this->yamlBroken = false;
        $ai               = $this->readAiConfig();

        foreach (self::DEFAULTS as $key => $default) {
            $value = $ai[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $numeric = is_int($default) || is_float($default);
            if (! is_scalar($value) || ($numeric && ! is_numeric($value))) {
                $this->yamlBroken = true;

                continue;
            }
            $data[$key] = $value;
        }

        return [
            'base_url'        => (string) $data['base_url'],
            'api_key'         => (string) $data['api_key'],
            'model'           => (string) $data['model'],
            'timeout'         => max(1, (int) $data['timeout']),
            'connect_timeout' => max(1, (int) $data['connect_timeout']),
            'max_tokens'      => max(1, (int) $data['max_tokens']),
            'temperature'     => min(2.0, max(0.0, (float) $data['temperature'])),
        ];
    }

    /**
     * UI 展示用：load() 基础上加 api_key_set（是否已配置）+ defaults（占位提示用）。
     * api_key 本身**不回显明文**（密钥）。
     */
    public function read(): array
    {
        $data                = $this->load();
        $data['api_key_set'] = $data['api_key'] !== '';
        unset($data['api_key']);
        $data['defaults']    = self::DEFAULTS;
        $data['yaml_broken'] = $this->yamlBroken;

        return $data;
    }

    /**
     * 保存。base_url / model / timeout 直接覆盖；api_key 空 = 保持原值
     * （跟 AccountStore 密码"留空不改"同语义 —— 避免 UI 掩码回显把真 key 冲掉）。
     * 要清空 key 请删 scaffold/ai.yaml。
     *
     * @return array{base_url:string, api_key:string, model:string, timeout:int, connect_timeout:int, max_tokens:int, temperature:float}
     */
    public function save(array $payload): array
    {
        $this->assertWritable();

        $next = $this->load();

        foreach (['base_url', 'model'] as $k) {
            if (array_key_exists($k, $payload)) {
                $next[$k] = trim((string) $payload[$k]);
            }
        }
        // 整数传输 / 生成参数：空 / null 跳过（保持原值），否则强转 + 下限保护
        foreach (['timeout', 'connect_timeout', 'max_tokens'] as $k) {
            if (array_key_exists($k, $payload) && $payload[$k] !== null && $payload[$k] !== '') {
                $next[$k] = max(1, (int) $payload[$k]);
            }
        }
        // temperature：float，钳到 [0, 2]
        if (array_key_exists('temperature', $payload) && $payload['temperature'] !== null && $payload['temperature'] !== '') {
            $next['temperature'] = min(2.0, max(0.0, (float) $payload['temperature']));
        }
        // api_key：空保持原值（不清空）；非空更新
        if (array_key_exists('api_key', $payload)) {
            $newKey = trim((string) $payload['api_key']);
            if ($newKey !== '') {
                $next['api_key'] = $newKey;
            }
        }
        // 空兜底回默认（否则 base_url='' 让翻译指向相对路径、model='' 让上游 400）
        if ($next['base_url'] === '') {
            $next['base_url'] = self::DEFAULTS['base_url'];
        }
        if ($next['model'] === '') {
            $next['model'] = self::DEFAULTS['model'];
        }

        $this->writeYaml($next);

        return $next;
    }

    /**
     * production / 强制只读时硬拒（跟 ConfigManager / AccountStore 字面一致）。
     */
    public function assertWritable(): void
    {
        if (ReadonlyMode::productionActive()) {
            throw new ConfigWriteForbiddenException('生产环境禁止修改 AI 配置');
        }
        if (ReadonlyMode::configLocked()) {
            throw new ConfigWriteForbiddenException('当前为强制只读模式（SCAFFOLD_CONFIG_READONLY）');
        }
    }

    /**
     * 总体只读判定 —— 口径唯一来源见 {@see ReadonlyMode}；本方法只转发，保留给既有调用方。
     */
    public function isReadonly(): bool
    {
        return ReadonlyMode::active();
    }

    // -------------------------------------------------------------------------

    /** 读取嵌套或平铺配置；损坏时提示并回退，让配置页仍可修复文件。 */
    private function readAiConfig(): array
    {
        if (! $this->exists()) {
            return [];
        }

        try {
            $parsed = Yaml::parse($this->fs->get($this->path())) ?? [];
        } catch (\Throwable) {
            $this->yamlBroken = true;

            return [];
        }

        if (! $this->isMapping($parsed)) {
            $this->yamlBroken = true;

            return [];
        }
        $ai = array_key_exists('ai', $parsed) ? $parsed['ai'] : $parsed;
        if (! $this->isMapping($ai)) {
            $this->yamlBroken = true;

            return [];
        }

        return $ai;
    }

    private function isMapping(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }

    /**
     * 整体重写 YAML（持久化终点）。
     *
     * 写失败必须被感知：本方法零返回值，静默吞掉会让上游以为已落盘 ——
     * ConfigController::updateAi() 会照常打「AI 配置已保存」绿条，磁盘上却还是旧配置。
     * Web/Support 层没有 console 可打 failed，口径是**抛异常**（同 AtomicFileWrite、
     * 以及 ConfigManager 经 EnvFileEditor / PhpFileEditor 的写法）；ConfigController
     * 已 `catch (\Throwable $e)` 把 message 落进 `flash_error` 红条并抑制成功绿条。
     *
     * @throws RuntimeException 原子写入失败时，文件保持原内容
     */
    private function writeYaml(array $ai): void
    {
        $path = $this->path();
        $this->ensureDir(dirname($path));

        $body = "# Scaffold Designer AI 翻译配置（plan 19）\n"
            . "# 由 /scaffold/config/ai 维护，**入 git**（随仓同步到各机器/部署，跟 accounts.yaml 一致）。\n"
            . "# ⚠ api_key 以明文存储（用户选定 plaintext-in-git）—— 请确保本仓私有。\n"
            . "# 删本文件即恢复默认（api_key 清空 = AI 翻译关闭）。\n\n"
            . Yaml::dump(['ai' => $ai], 4, 4, Yaml::DUMP_OBJECT_AS_MAP);

        try {
            $target = realpath($path);
            // 已有软链接继续写目标；断链拒绝写入，不能把链接替换成普通文件。
            if (($target === false && is_link($path)) || (is_file($path) && ! is_writable($path))) {
                throw new RuntimeException('AI 配置目标不存在或不可写。');
            }
            $this->writeFileAtomically($target ?: $path, $body);
        } catch (RuntimeException $e) {
            throw new RuntimeException("写入失败，AI 配置未变更：{$path}", 0, $e);
        }
    }

    private function ensureDir(string $dir): void
    {
        if (! $this->fs->isDirectory($dir)) {
            $this->fs->makeDirectory($dir, 0755, true);
        }
    }

    private function basePath(string $relative): string
    {
        return function_exists('base_path') ? base_path($relative) : $relative;
    }
}
