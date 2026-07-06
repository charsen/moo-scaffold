<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Concerns;

/**
 * 多态关系的 morph 类名用表名（而不是 class FQN）。
 */
trait UseTableNameAsMorphClass
{
    public function getMorphClass(): string
    {
        return $this->getTable();
    }
}
