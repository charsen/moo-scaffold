<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use Illuminate\Http\JsonResponse;

/**
 * `/scaffold` 全站 JSON 信封的**唯一实现**（统一 JSON 信封 · 第 2 项）。
 *
 *   - 成功：`{ok: true, data: {…}}`            —— 单层解包，前端只写一遍
 *   - 失败：`{ok: false, error: {code, msg, detail}}` + 指定 HTTP 码
 *
 * **为什么收口到一个类**：收敛前全站有 ~10 种响应形态、前端 55 处错误提取分两套互不兼容的读法
 * （docs 系把 `error` 当字符串直接 toast，designer 系当对象取 `.msg` / `.code`）。
 * 同一份契约两种解析方式，任何一次后端改动都要在两边各改一遍、还容易漏。
 *
 * **为什么放在 Support 而不是控制器基类**：信封有两类产出方，控制器只是其中之一 ——
 * 三个 `Enforce*` 中间件不继承控制器基类，但同样要在 403 时产出信封。收口到基类，
 * 中间件就只能去依赖 `Http\Controllers\Controller`（中间件依赖控制器基类，方向是错的）。
 * 与 `Support\ReadonlyMode` 同属「被 Middleware + Controller + Support 三方共用的口径」形态。
 *
 * **为什么是静态方法而不是注入的服务**：两个方法的输入只有入参、没有任何实例状态，
 * 做成可注入服务只会让 Controller / Middleware 两类调用方的构造函数全部改一遍，
 * 换不来任何可测性（同 `ReadonlyMode` 类头的同款判据）。
 *
 * 哨兵：`tests/Feature/Http/JsonEnvelopeTest.php` 正向断言「整个 `src/` 里只有本文件与
 * `ApiProxyController` 会产出裸 JSON」—— 所以这里就是那个**单一出口**，别在别处再拼一份。
 *
 * ⚠ **不适用于** `ApiProxyController`：那里的 body 是**上游 API 的原样透传**，套信封会破坏代理语义；
 * 它的 `_proxy_status` 契约（HTTP 恒 200 + body 里带真实状态）也必须原样保留。
 */
final class JsonEnvelope
{
    /**
     * 成功信封。`$data` 缺省为空数组（不是缺键）⇒ 前端解包不必判 `undefined`。
     */
    public static function ok(array $data = []): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => $data]);
    }

    /**
     * 失败信封。
     *
     * `$code` 给前端做**分支判断**（机器可读），`$msg` 给人看（直接进 toast），`$detail` 放字段级信息。
     *
     * ⚠ `$http` 故意**不给默认值**，强制调用点表态：失败要么 4xx 要么 5xx，别一律 200 ——
     * 否则前端 `if (! res.ok)` 那条路（designer.js 一直依赖它）会失效。
     * 中间件一律 403；控制器按领域语义给 4xx（校验 422 除外，那是框架校验袋）。
     *
     * @param string $code   机器可读错误码（如 `ADMIN_ONLY` / `READONLY_LOCKED`）
     * @param string $msg    人类可读文案
     * @param int    $http   HTTP 状态码
     * @param array  $detail 附加字段（校验明细等）
     */
    public static function error(string $code, string $msg, int $http, array $detail = []): JsonResponse
    {
        return response()->json([
            'ok'    => false,
            'error' => ['code' => $code, 'msg' => $msg, 'detail' => $detail],
        ], $http);
    }
}
