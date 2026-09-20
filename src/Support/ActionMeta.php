<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

/**
 * API action **元信息**的归一（2026-09-19 自 `Utility` 外迁）。
 *
 * 与 {@see ActionDoc} 的分工：ActionDoc 负责「从 DocBlock / 反射**解析出**字段」，
 * 本类负责「把解析好（或手写 YAML 里）的字段**归一成稳定形状**」—— 两者都只做纯计算，
 * 不碰文件系统、不读配置，故与 `Paths` / `FieldName` / `ControllerName` 同形：`final` + 全静态。
 *
 * 迁来时的对应关系（旧名在 `Utility` 上）：
 *   `normalizeApiActionMeta()` → `normalize()`
 *   `isApiActionDeprecated()`  → `isDeprecated()`
 *   `normalizeMenusTransform()` → `normalizeMenus()`
 *   `removeActionNameMethod()` → `removeMethodSuffix()`
 *   `formatDisplayDate()`      → `formatDate()`（private）
 */
final class ActionMeta
{
    /**
     * 统一解析 API action 中的元信息。
     */
    public static function normalize(array $actionData, bool $formatDates = false): array
    {
        $meta         = $actionData['meta'] ?? [];
        $meta         = is_array($meta) ? $meta : [];
        $createdAt    = trim((string) ($meta['created_at'] ?? ($actionData['created_at'] ?? '')));
        $updatedAt    = trim((string) ($meta['updated_at'] ?? ($actionData['updated_at'] ?? '')));
        $deprecatedAt = trim((string) ($meta['deprecated_at'] ?? ($actionData['deprecated_at'] ?? '')));

        $data = [
            'creator'           => trim((string) ($meta['creator'] ?? ($actionData['creator'] ?? ($actionData['user'] ?? '')))),
            'created_at'        => $createdAt,
            'updated_by'        => trim((string) ($meta['updated_by'] ?? ($actionData['updated_by'] ?? ''))),
            'updated_at'        => $updatedAt,
            'deprecated_by'     => trim((string) ($meta['deprecated_by'] ?? ($actionData['deprecated_by'] ?? ''))),
            'deprecated_at'     => $deprecatedAt,
            'deprecated_reason' => trim((string) ($meta['deprecated_reason'] ?? ($actionData['deprecated_reason'] ?? ''))),
        ];

        if (! $formatDates) {
            return $data;
        }

        $data['created_at']    = self::formatDate($data['created_at']);
        $data['updated_at']    = self::formatDate($data['updated_at']);
        $data['deprecated_at'] = self::formatDate($data['deprecated_at']);

        return $data;
    }

    /**
     * `deprecated` 字段被手写 YAML 写成 bool / 数字 / 字符串都算数。
     */
    public static function isDeprecated(array $actionData): bool
    {
        $deprecated = $actionData['deprecated'] ?? false;

        if (is_bool($deprecated)) {
            return $deprecated;
        }

        if (is_numeric($deprecated)) {
            return (int) $deprecated === 1;
        }

        return in_array(strtolower(trim((string) $deprecated)), ['1', 'true', 'yes', 'deprecated'], true);
    }

    /**
     * 兼容旧的扁平菜单格式，并保证 controllers 字段始终可安全追加。
     */
    public static function normalizeMenus(array $data): array
    {
        foreach ($data as $key => $val) {
            if (is_string($val)) {
                $data[$key] = ['name' => $val, 'controllers' => []];

                continue;
            }

            if (is_array($val)) {
                $data[$key]['controllers'] = is_array($val['controllers'] ?? null)
                    ? $val['controllers']
                    : [];

                continue;
            }

            unset($data[$key]);
        }

        return $data;
    }

    /**
     * 移除 action key 中的 HTTP 方法后缀。
     */
    public static function removeMethodSuffix(string|array $action): string|array
    {
        if (is_array($action)) {
            return array_map(
                fn (string $val): string => self::removeMethodSuffix($val),
                $action
            );
        }

        return (string) preg_replace(
            '/_(?:get|post|delete|put|patch|head|options|any)(?:\|(?:get|post|delete|put|patch|head|options|any))*$/i',
            '',
            $action
        );
    }

    /**
     * 日期统一展示为 Y-m-d
     */
    private static function formatDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $matches) === 1) {
            return $matches[0];
        }

        $timestamp = strtotime($value);

        return $timestamp !== false ? date('Y-m-d', $timestamp) : $value;
    }
}
