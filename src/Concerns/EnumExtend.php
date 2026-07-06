<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Concerns;

/**
 * Enum 扩展方法集（基础设施，供各包/host 的 PHP enum 复用）。
 *
 * 配合 enum 的静态方法约定：每个 Enum 需提供 `getLabel(self $value): string`。
 */
trait EnumExtend
{
    public function label(): string
    {
        return self::getLabel($this);
    }

    public static function values(): array
    {
        return array_map(static fn ($item) => $item->value, self::cases());
    }

    public static function valueLabels(): array
    {
        $result = [];
        foreach (self::cases() as $item) {
            $result[$item->value] = self::getLabel($item);
        }

        return $result;
    }

    public static function keyValues(): array
    {
        $result = [];
        foreach (self::cases() as $item) {
            $result[$item->name] = $item->value;
        }

        return $result;
    }

    public function equal(string $value): bool
    {
        return self::tryFrom($value) === $this;
    }

    public static function include(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    public static function includeAll(array $names): bool
    {
        $keys = self::values();
        foreach ($names as $name) {
            if (! in_array($name, $keys, true)) {
                return false;
            }
        }

        return true;
    }
}
