<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Concerns;

/**
 * Model 可操作动作（给 `$appends` 加 'options' 键用）。
 *
 * `getOptionsAttribute()` 默认按 soft-delete 状态返回 edit/destroy 或 restore/force-destroy。
 * model 可定义 `getOptions()` 返回额外动作数组，会前缀到默认动作。
 *
 * 软删后是否提供「彻底删除」由 `optionsAllowForceDestroy()` 控制（默认 true）；
 * model override 返回 false = 只 restore、不 force-destroy（旧 OptionalSimple 行为）。
 */
trait Optional
{
    public function getOptionsAttribute(): array
    {
        $res = [];
        if ($this->deleted_at === null) {
            $res[] = ['type' => 'edit'];
            if ($this->optionsAllowDestroy()) {
                $res[] = ['type' => 'destroy'];
            }
        } else {
            $res[] = ['type' => 'restore'];
            if ($this->optionsAllowForceDestroy()) {
                $res[] = ['type' => 'force-destroy'];
            }
        }

        if (method_exists($this, 'getOptions')) {
            $appends = $this->getOptions();
            foreach ($appends as $item) {
                array_unshift($res, $item);
            }
        }

        return $res;
    }

    /**
     * 是否提供「删除」动作。默认 true
     */
    protected function optionsAllowDestroy(): bool
    {
        return true;
    }

    /**
     * 软删后是否提供「彻底删除」动作。默认 true
     */
    protected function optionsAllowForceDestroy(): bool
    {
        return true;
    }
}
