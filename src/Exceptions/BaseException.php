<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * 业务异常基类（scaffold 生成的 controller / FormRequest 共用的业务异常词汇）。
 *
 * 默认 code 522（项目自定义业务异常码，对标 422，render 时原样作 HTTP status）；
 * 子类（FormLayout/ModelDelete/Upload 等）按需覆盖 code。
 *
 * `implements ShouldntReport`：业务异常是故意抛的预期控制流（render() 直接回 JSON），不是 bug。
 * Laravel 框架层据此直接不上报 —— scaffold 的 runtime 落盘通道（经 reportable 回调触发）与
 * Laravel 默认 log 一并豁免，host **无需**再在 bootstrap/app.php 的 dontReport([...]) 里逐个登记。
 * 下游业务异常（含 moo-system BusinessException）继承本类即自动豁免，避免漏配上报到生产。
 */
class BaseException extends RuntimeException implements ShouldntReport
{
    /**
     * Construct the exception. Note: The message is NOT binary safe.
     *
     * @param string $message [optional] The Exception message to throw.
     * @param int    $code    [optional] The Exception code.
     */
    public function __construct($message, int $code = 522)
    {
        parent::__construct($message, $code);
    }

    public function render($request): JsonResponse
    {
        return response()->json(['message' => $this->message], $this->code);
    }
}
