<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Concerns;

use DateTimeInterface;

/**
 * Eloquent 序列化日期为 `Y-m-d H:i:s` 格式（除非 model 自带 `$dateFormat`）。
 */
trait GetSerializeDate
{
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format($this->dateFormat ?: 'Y-m-d H:i:s');
    }
}
