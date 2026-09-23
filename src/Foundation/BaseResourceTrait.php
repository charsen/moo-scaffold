<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2025-07-29 16:38
 * @LastEditors: Charsen
 * @LastEditTime: 2025-07-29 16:39
 * @Description: Base Resource Trait
 */

namespace Mooeen\Scaffold\Foundation;

trait BaseResourceTrait
{
    protected array $hiddenFields = [];

    /**
     * Set the resource collection to include trashed fields.
     */
    public function trashed($val = true): self
    {
        $this->trashed = $val;

        return $this;
    }

    /**
     * Set the keys that are supposed to be filtered out.
     *
     * @return $this
     */
    public function hide(string|array $fields): self
    {
        if (! is_array($fields)) {
            $fields = array_map('trim', explode(',', $fields));
        }

        // 显示白名单和隐藏黑名单分开保存，调用顺序不得重新暴露已隐藏字段。
        $this->hiddenFields = array_values(array_unique([...$this->hiddenFields, ...$fields]));
        if ($this->hide) {
            $this->customFields = $this->hiddenFields;
        }

        return $this;
    }

    /**
     * Set the keys that are only return.
     */
    public function show(string|array $fields): self
    {
        $fields             = is_array($fields) ? $fields : array_map('trim', explode(',', $fields));
        $this->customFields = $this->hide
            ? array_values(array_unique($fields))
            : array_values(array_unique([...$this->customFields, ...$fields]));
        $this->hide = false;

        return $this;
    }
}
