<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2025-07-28 15:16
 * @LastEditors: Charsen
 * @LastEditTime: 2025-07-28 15:50
 * @Description: 重置 collection 返回时使用哪个 Resource 进行格式化
 */

namespace Mooeen\Scaffold\Foundation;

class AnonymousResourceCollection extends BaseResourceCollection
{
    /**
     * The name of the resource being collected.
     *
     * @var string
     */
    public $collects;

    /**
     * Indicates if the collection keys should be preserved.
     *
     * @var bool
     */
    public $preserveKeys = false;

    /**
     * Create a new anonymous resource collection.
     *
     * @param mixed  $resource
     * @param string $collects
     *
     * @return void
     */
    public function __construct($resource, $collects)
    {
        $this->collects = $collects;

        parent::__construct($resource);
    }
}
