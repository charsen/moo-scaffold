<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Mooeen\Scaffold\Support\AccountStore;

/**
 * 人员管理(/scaffold/accounts)仅 admin 可访问 —— 含「进入」(GET)与「管理」(写)。
 * 非 admin 登录用户:GET 跳首页 + flash 提示;AJAX/写 → 403。
 *
 * 挂在 authed 组上自过滤 accounts 路径(跟 EnforceScaffoldWritable / EnforceDesignerPermission 同模式)。
 * auth 关闭 / 无登录用户(attr 缺)→ 无 role 体系,放行(单用户/开放模式)。
 */
class EnforceAdminOnly
{
    public function __construct(private readonly AccountStore $store) {}

    public function handle(Request $request, Closure $next)
    {
        $prefix   = trim((string) config('scaffold.route.prefix', 'scaffold'), '/');
        $patterns = [$prefix . '/accounts', $prefix . '/accounts/*'];
        if (! $request->is(...$patterns)) {
            return $next($request);
        }

        $user = $request->attributes->get('scaffold_auth_user');
        if (! is_string($user) || $user === '') {
            return $next($request);     // auth 关 / 无 user → 不拦
        }

        if ($this->store->isAdmin($user)) {
            return $next($request);
        }

        $message = '人员管理仅 admin 可访问。';
        if ($request->ajax() || $request->expectsJson()) {
            return response()->json(['error' => $message], 403);
        }
        if ($request->hasSession()) {
            $request->session()->flash('flash_error', $message);
        }

        return redirect()->to(route('scaffold.home'))->setStatusCode(303);
    }
}
