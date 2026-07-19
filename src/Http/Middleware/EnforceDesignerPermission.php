<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Mooeen\Scaffold\Support\AccountStore;

/**
 * designer 写权限(per-user 维度):只有「设计数据库」权限(can_design_db)或 admin 角色的登录用户,
 * 才能对 /scaffold/db/designer 做写操作(POST/PUT/PATCH/DELETE);其余用户只读(GET 永远放行)。
 *
 * 跟 EnforceScaffoldWritable(env / readonly 维度)正交,两层各管一维。
 * 挂在 authed 组上,ScaffoldAuthenticate 已把 username 塞进 scaffold_auth_user attribute。
 * auth 关闭 / 无登录用户(attr 缺)→ 无 per-user 权限体系,放行(单用户/开放模式)。
 */
class EnforceDesignerPermission
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private readonly AccountStore $store) {}

    public function handle(Request $request, Closure $next)
    {
        if (in_array($request->method(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        // 只管 designer 写;其它路径交给别的 middleware
        $prefix   = trim((string) config('scaffold.route.prefix', 'scaffold'), '/');
        $patterns = [$prefix . '/db/designer', $prefix . '/db/designer/*'];
        if (! $request->is(...$patterns)) {
            return $next($request);
        }

        // 当前登录用户(ScaffoldAuthenticate 已塞);auth 关 / 无 user → 不拦(无 per-user 权限体系)
        $user = $request->attributes->get('scaffold_auth_user');
        if (! is_string($user) || $user === '') {
            return $next($request);
        }

        if ($this->store->canDesignDb($user)) {
            return $next($request);
        }

        $message = '无「设计数据库」权限，designer 当前只读；请联系 admin 在「开发人员」里授权。';
        if ($request->ajax() || $request->expectsJson()) {
            return response()->json(['error' => $message], 403);
        }
        if ($request->hasSession()) {
            $request->session()->flash('flash_error', $message);
        }

        return redirect()->back()->withInput()->setStatusCode(303);
    }
}
