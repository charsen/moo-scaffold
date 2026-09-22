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

        // **累加**而非覆盖（2026-09-21 修）：本方法是 `JsonResource::hide()` 的覆写，写的是本类的
        // `$customFields`（`filterFields()` 真正读取的字段）。原实现直接赋值，于是**连续两次 hide()
        // 只有最后一次生效** —— 实测 `AuthorizationController::index()` 先
        // `hide('role_next_actions')`、非 root 再 `hide(['role_actions'])`，结果只隐藏了后者，
        // 前者（承载**同一份权限清单**的原始落库列）原样透出，非 root 仍能读到他人角色的全量 key。
        $this->customFields = array_values(array_unique([...$this->customFields, ...$fields]));

        return $this;
    }

    /**
     * Set the keys that are only return.
     */
    public function show(string|array $fields): self
    {
        $this->hide = false;

        return $this->hide($fields);
    }
}
