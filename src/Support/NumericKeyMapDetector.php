<?php declare(strict_types=1);

/*
 * @Description: 「整数键映射」探测器 —— 判断一段出参里哪些层级会被 Laravel 资源层重排键。
 *
 * 背景（2026-09-13 小程序真机自测踩到、随后在宿主实测只有 2 处真实命中）：
 * `Illuminate\Http\Resources\ConditionallyLoadsAttributes::removeMissingValues()` 会**递归**遍历
 * Resource 出参，把「键全为数字」的数组 `array_values()` 重排 —— 它本意是让「删掉条件字段后带洞的
 * 列表」仍然序列化成 JSON 数组，但**整数键映射**（如 `{"1":"正常","2":"停用"}`、`{"4":12,"6":3}`）
 * 会被误判成列表、键被抹掉：前端按值取标签会错位，读回来再保存就会把键**永久写坏**。
 *
 * 该行为与"是否连续"无关：`{1:..,2:..}`、`{1:..,3:..}`（带洞）、`{0:..,2:..}` 都会被重排；
 * 字符串键映射与真正的列表（0..n-1）安全。官方开关是该 Resource 上声明
 * `public $preserveKeys = true;`（**必须是实例属性**，声明成 static 会落到 `JsonResource::__get()`）。
 *
 * 本类只做纯计算，不碰 DB / 文件，便于单测与在任意上下文复用。
 */

namespace Mooeen\Scaffold\Support;

class NumericKeyMapDetector
{
    /**
     * 找出所有「会被重排键」的层级路径（深度优先，父路径先出）。
     *
     * @param array<int|string, mixed> $value
     * @param string                   $prefix 路径前缀，便于报告里定位（默认从根 `$` 起）
     *
     * @return list<string> 例如 ['$.options', '$.meta.file_type']
     */
    public static function dangerPaths(array $value, string $prefix = '$'): array
    {
        $paths = [];

        if (self::isDangerousLevel($value)) {
            $paths[] = $prefix;
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $paths = [...$paths, ...self::dangerPaths($item, $prefix . '.' . $key)];
            }
        }

        return $paths;
    }

    /**
     * 这一层是不是「会被重排」：键全为数字，且不是 0..n-1 的连续列表。
     *
     * 空数组返回 false（没有键可丢）。
     *
     * @param array<int|string, mixed> $value
     */
    public static function isDangerousLevel(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        $keys = array_keys($value);
        foreach ($keys as $key) {
            if (! is_numeric($key)) {
                return false;
            }
        }

        // 键全数字，但顺序/起点不连续 → array_values() 会改变键的含义
        return $keys !== range(0, count($keys) - 1);
    }
}
