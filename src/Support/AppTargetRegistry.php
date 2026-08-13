<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use InvalidArgumentException;

/**
 * Scaffold 应用端注册表。
 *
 * app key 表示业务消费者/运行边界（admin、mobi、web、rpa……），不是 HTTP API 的同义词。
 * 路径、模板和路由策略全部由 host 配置声明；生成器不再根据 app 名猜目录或 stub。
 */
final class AppTargetRegistry
{
    public const ROUTE_MODE_RESOURCE = 'resource';

    public const ROUTE_MODE_MANUAL = 'manual';

    /**
     * @return array<string,array<string,mixed>>
     */
    public function all(): array
    {
        $configured = config('scaffold.controller', []);
        if (! is_array($configured)) {
            throw new InvalidArgumentException('scaffold.controller 必须是数组。');
        }

        $targets = [];
        $order   = 0;

        foreach ($configured as $key => $config) {
            $key = trim((string) $key);
            if (preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1) {
                throw new InvalidArgumentException("应用端 key [{$key}] 无效；只允许小写字母、数字和下划线，且必须以字母开头。");
            }
            if (! is_array($config)) {
                throw new InvalidArgumentException("scaffold.controller.{$key} 必须是数组。");
            }

            $targets[$key] = $this->normalize($key, $config, $order++);
        }

        uasort($targets, static fn (array $left, array $right): int => [$left['sort'], $left['_order']] <=> [$right['sort'], $right['_order']]);

        return $targets;
    }

    /**
     * @return array<string,mixed>
     */
    public function get(string $app): array
    {
        $app     = strtolower(trim($app));
        $targets = $this->all();

        if (! isset($targets[$app])) {
            $available = $targets === [] ? '（无）' : implode(', ', array_keys($targets));

            throw new InvalidArgumentException("应用端 [{$app}] 未配置；当前可用：{$available}。");
        }

        return $targets[$app];
    }

    /**
     * @return array<string,string>
     */
    public function labels(): array
    {
        $labels = [];
        foreach ($this->all() as $app => $target) {
            $labels[$app] = $target['label'];
        }

        return $labels;
    }

    /**
     * 校验 schema 中声明的端都已由 host 注册，避免拼错 key 后静默少生成文件。
     *
     * @param iterable<mixed> $apps
     */
    public function assertConfigured(iterable $apps, string $context): void
    {
        $targets = $this->all();
        $unknown = [];

        foreach ($apps as $app) {
            $app = trim((string) $app);
            if ($app !== '' && ! isset($targets[$app])) {
                $unknown[$app] = $app;
            }
        }

        if ($unknown === []) {
            return;
        }

        $available = $targets === [] ? '（无）' : implode(', ', array_keys($targets));

        throw new InvalidArgumentException(
            "{$context} 声明了未配置的应用端：" . implode(', ', $unknown) . "；当前可用：{$available}。"
        );
    }

    /**
     * 确认一个端具备自动生成 Controller/Request/Resource 所需的完整配置。
     *
     * @return array<string,mixed>
     */
    public function codegen(string $app): array
    {
        $target   = $this->get($app);
        $required = ['path', 'request_path', 'resource_path', 'stub', 'controller_trait_stub', 'trait_stub'];
        $missing  = [];

        foreach ($required as $key) {
            if (trim((string) ($target[$key] ?? '')) === '') {
                $missing[] = $key;
            }
        }

        if (! is_array($target['requests'] ?? null)) {
            $missing[] = 'requests';
        }
        if ($target['route_mode'] === self::ROUTE_MODE_RESOURCE && trim((string) ($target['route'] ?? '')) === '') {
            $missing[] = 'route';
        }

        if ($missing !== []) {
            throw new InvalidArgumentException(
                "应用端 [{$app}] 不能用于代码生成，缺少配置：" . implode(', ', array_unique($missing)) . '。'
            );
        }

        return $target;
    }

    /**
     * @param array<string,mixed> $config
     *
     * @return array<string,mixed>
     */
    private function normalize(string $key, array $config, int $order): array
    {
        $stub = trim((string) ($config['stub'] ?? ''));
        // 兼容既有 host 发布配置：旧配置没有 profile / controller_trait_stub / route_mode。
        $isAdminProfile = $stub === 'controller-admin';
        $routeMode      = strtolower(trim((string) ($config['route_mode'] ?? self::ROUTE_MODE_RESOURCE)));

        if (! in_array($routeMode, [self::ROUTE_MODE_RESOURCE, self::ROUTE_MODE_MANUAL], true)) {
            throw new InvalidArgumentException(
                "scaffold.controller.{$key}.route_mode [{$routeMode}] 无效；只支持 resource 或 manual。"
            );
        }

        $name  = $config['name'] ?? $key;
        $label = $config['api_name']
            ?? (is_array($name) ? ($name['zh-CN'] ?? $name['en'] ?? $key) : $name);

        return array_merge($config, [
            'key'                   => $key,
            'label'                 => (string) $label,
            'sort'                  => (int) ($config['sort'] ?? (($order + 1) * 10)),
            'profile'               => (string) ($config['profile'] ?? ($isAdminProfile ? 'admin-crud' : 'readonly-api')),
            'route_mode'            => $routeMode,
            'controller_trait_stub' => (string) ($config['controller_trait_stub'] ?? ($isAdminProfile ? 'controller-admin-trait' : 'controller-api-trait')),
            '_order'                => $order,
        ]);
    }
}
