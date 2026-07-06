<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Exceptions;

/**
 * 表单布局异常（code 402）。由 scaffold 的 Foundation\FormRequest 在 form_widgets 布局
 * 解析失败时抛出——唯一抛出点就在包内，故定义随之收进包。
 */
class FormLayoutException extends BaseException
{
    public function __construct($message, int $code = 402)
    {
        parent::__construct($message, $code);
    }
}
