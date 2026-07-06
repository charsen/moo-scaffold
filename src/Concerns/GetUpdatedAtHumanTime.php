<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Concerns;

use Illuminate\Support\Carbon;

/**
 * `updated_at` 访问器返回人性化时间（如 "3 分钟前"）。
 */
trait GetUpdatedAtHumanTime
{
    public function getUpdatedAtAttribute($value): string
    {
        return Carbon::parse($value)->diffForHumans();
    }
}
