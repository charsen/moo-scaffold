<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Concerns;

use Mooeen\Scaffold\Contracts\OperatorResolver;
use Mooeen\Scaffold\Support\OperatorContext;

/**
 * 自动填充 creator_id / updater_id。
 *
 * 直接使用 OperatorResolver 的 nullable 结果；无身份统一为 null。
 */
trait HasOperator
{
    public static function bootHasOperator(): void
    {
        static::creating(function ($model): void {
            $fillable   = $model->getFillable();
            $operatorId = OperatorContext::current() ?? app(OperatorResolver::class)->id();

            if (in_array('creator_id', $fillable, true)) {
                $model->creator_id = $operatorId;
            }

            if (in_array('updater_id', $fillable, true)) {
                $model->updater_id = $operatorId;
            }
        });

        static::updating(function ($model): void {
            if (in_array('updater_id', $model->getFillable(), true)) {
                $model->updater_id = OperatorContext::current() ?? app(OperatorResolver::class)->id();
            }
        });
    }
}
