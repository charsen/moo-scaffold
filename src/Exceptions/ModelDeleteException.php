<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Exceptions;

/** 模型删除受阻业务异常（存在关联数据不可删等）。 */
class ModelDeleteException extends BaseException {}
