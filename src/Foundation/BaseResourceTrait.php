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

        $this->customFields = $fields;

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
