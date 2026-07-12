<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Foundation;

use EloquentFilter\ModelFilter;

/**
 * moo 系扩展包所有 Filter 的共享基类（plan 38：三件套上移 scaffold，原各包自持复制已归一）。
 *
 * 直接继承 `tucker-eric/eloquentfilter` 的 ModelFilter。生成的 `*Filter extends BaseFilter`
 * 由 CreateModelGenerator 的 `use_base_filter` 指向本类（不再逐包生成本地副本，与 Concerns\* 同款上提）。
 *
 * - $drop_id = false：不剥离 `_id` 后缀
 * - $camel_cased_methods = false：保留 snake_case 方法名
 * - 重写 `removeEmptyInput` 跳过 page / page_limit + 兼容数组/null/空串
 */
class BaseFilter extends ModelFilter
{
    /**
     * @var bool
     */
    protected $drop_id = false;

    /**
     * @var bool
     */
    protected $camel_cased_methods = false;

    /**
     * 过滤空字符串 + 兼容 page/page_limit 不入 filter 链。
     *
     * @param array $input
     */
    public function removeEmptyInput($input): array
    {
        $filterableInput = [];

        foreach ($input as $key => $val) {
            if ($key === 'page' || $key === 'page_limit') {
                continue;
            }

            if ($val !== '' && $val !== null && ! (is_array($val) && empty($val))) {
                $filterableInput[$key] = $val;
            }
        }

        return $filterableInput;
    }
}
