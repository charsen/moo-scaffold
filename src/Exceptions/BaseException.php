<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Mooeen\Scaffold\Support\JsonEnvelope;
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
 *
 * render() 走统一失败信封（`Support\JsonEnvelope`）。原先这里是**第 6 种**旧形态
 * `{message:"…"}` —— 没有 `ok` 布尔、也没有机器码，前端只能拿到一句文案、拿不到分支依据。
 * 子类**不要各自重写 render()**：机器码由类名自动派生（见 machineCode()），
 * 新加子类（含下游 host 的）不必也不会忘记声明码。守卫见 `tests/Feature/Exceptions/BaseExceptionTest.php`。
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

    /**
     * 渲染为统一失败信封：`{ok:false, error:{code,msg,detail}}` + 异常自身的 code 作 HTTP 状态。
     *
     * HTTP 状态**沿用 `$this->code`**（默认 522，`FormLayoutException` 覆写为 402）——
     * 这是本类既有的对外契约，信封迁移不许顺手改成 4xx（下游可能按这个码做判断）。
     */
    public function render($request): JsonResponse
    {
        return JsonEnvelope::error(static::machineCode(), $this->message, $this->code);
    }

    /**
     * 机器可读错误码 = **类名**转 SCREAMING_SNAKE：
     *   `BaseException` → `BASE_EXCEPTION`、`FormLayoutException` → `FORM_LAYOUT_EXCEPTION`、
     *   `SyncCDNException` → `SYNC_CDN_EXCEPTION`（连续大写当成一个词，不逐字母拆）。
     *
     * **为什么从类名派生而不是逐个声明**：子类有 7 个、下游 host 还会自带（如 moo-system 的
     * `BusinessException`）。逐个声明就是一份「靠人记得同步」的窄清单 —— 本仓在类型清单上
     * 已复发过两次「漏一项导致整类静默降级」（见 `Support\FieldTypes` 的类头）。派生法没有漏口：
     * 新加子类自动获得唯一码，且与类名 1:1 可反查。
     *
     * 两条分裂规则（分两趟，各自只有一个捕获组；见 `BaseExceptionTest` 的逐名列表）：
     *   ① 小写/数字后面的大写 —— 词边界（`Sync|C`）；
     *   ② 大写 + 小写、且前面还是大写 —— 缩写词的结束边界（`CDN|Ex`）。
     * 只留 ① 会把 `CDN` 拆成 `C_D_N`；只留 ② 会漏掉 `Form|L`。
     */
    private static function machineCode(): string
    {
        $short = (new \ReflectionClass(static::class))->getShortName();

        $snake = (string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $short);
        $snake = (string) preg_replace('/(?<=[A-Z])([A-Z][a-z])/', '_$1', $snake);

        return strtoupper($snake);
    }
}
