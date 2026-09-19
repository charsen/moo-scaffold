<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Controllers;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\View\View;
use Mooeen\Scaffold\Utility;

/**
 * Class     Controller
 *
 * @author Charsen
 */
class Controller extends BaseController
{
    protected Utility $utility;

    protected Filesystem $filesystem;

    /**
     * Controller constructor.
     */
    public function __construct(Utility $utility, Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
        $this->utility    = $utility;
    }

    /**
     * Helper to get the config values.
     */
    protected function config(string $key, mixed $default = null): mixed
    {
        return $this->utility->getConfig($key, $default);
    }

    /**
     * Get the evaluated view contents for the given view.
     */
    protected function view(string $view, array $data = [], array $mergeData = []): View
    {
        return view()->make("scaffold::{$view}", $data, $mergeData);
    }

    /**
     * 统一 JSON 成功信封：`{"ok": true, "data": {...}}` —— `/scaffold` 后台全站唯一的成功形状。
     *
     * 这套形状不是新造的：它就是 `DesignerController` 原有的私有 `ok()`，一直是本仓事实标准。
     * 上提到基类是为了把 `/scaffold` 的 JSON 出口收敛成**一个**，让前端能只写一层解包
     * （`public/javascript/api.js`），而不是像以前那样每个页面各写一套 —— 收敛前后端有 10 种形态、
     * 前端 55 处错误提取分两套互不兼容的读法（docs 系把 `error` 当字符串、designer 系当对象）。
     *
     * ⚠ **不适用于** `ApiProxyController`：那里的 body 是**上游 API 的原样透传**，套信封会破坏代理语义；
     * 它的 `_proxy_status` 契约（HTTP 恒 200 + body 里带真实状态）也必须原样保留。
     */
    protected function ok(array $data = []): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => $data]);
    }

    /**
     * 统一 JSON 失败信封：`{"ok": false, "error": {code, msg, detail}}` + HTTP 状态码。
     *
     * 签名与 `DesignerController::error()` 逐字一致（那是本仓事实标准），所以迁移调用点不必改。
     * `$code` 给前端做**分支判断**（机器可读），`$msg` 给人看（直接进 toast），`$detail` 放字段级信息。
     *
     * ⚠ `$http` 故意**不给默认值**，强制调用点表态：失败要么 4xx 要么 5xx，别一律 200 ——
     * 否则前端 `if (! res.ok)` 那条路（designer.js 一直依赖它）会失效。
     *
     * @param string $code   机器可读错误码（如 `SLUG_INVALID` / `HTTP_403`）
     * @param string $msg    人类可读文案
     * @param int    $http   HTTP 状态码
     * @param array  $detail 附加字段（校验明细等）
     */
    protected function error(string $code, string $msg, int $http, array $detail = []): JsonResponse
    {
        return response()->json([
            'ok'    => false,
            'error' => ['code' => $code, 'msg' => $msg, 'detail' => $detail],
        ], $http);
    }

    /**
     * 当前登录用户名 —— ScaffoldAuthenticate 中间件解密 scaffold_auth cookie 后塞进 request attribute。
     * 绝不读 $req->cookies->get('scaffold_auth'):那是 AES-256+HMAC 密文字面(eyJpdi...),被当
     * by/operator 字段写进 yaml 会爆版(ship-checklist #12;2026-05-28 全面 audit catch 出 12+ 脏数据)。
     * authed 路由该 attr 必 set,无 cookie fallback(fallback = dead code + footgun)。
     */
    protected function currentOperator(Request $req): ?string
    {
        $user = $req->attributes->get('scaffold_auth_user');

        return is_string($user) && $user !== '' ? $user : null;
    }
}
