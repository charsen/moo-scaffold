<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Mooeen\Scaffold\Support\AccountStore;

/**
 * 覆盖两处 admin-only：
 *   - 人员管理(/scaffold/accounts)：含「进入」(GET) 与「管理」(写)，两者都仅 admin。
 *   - 配置(/scaffold/config/*)：仅**写**路由仅 admin（GET 是只读且敏感字段已掩码，不拦）。
 *     依据 docs/overview.md 的角色表「admin 可改任何账号 / 配置 / member 仅自管资料」——
 *     配置里的 `scaffold.hosts` 是接口代理的 SSRF 白名单来源，不能由 member 改。
 *
 * 非 admin 登录用户:GET 跳首页 + flash 提示;AJAX/写 → 403。
 *
 * 挂在 authed 组上自过滤路径(跟 EnforceScaffoldWritable / EnforceDesignerPermission 同模式)。
 * auth 关闭 / 无登录用户(attr 缺)→ 无 role 体系,放行(单用户/开放模式)。
 */
class EnforceAdminOnly
{
    public function __construct(private readonly AccountStore $store) {}

    public function handle(Request $request, Closure $next)
    {
        $prefix = trim((string) config('scaffold.route.prefix', 'scaffold'), '/');

        // 人员管理(/scaffold/accounts)：「进入」(GET) 与「管理」(写) 都仅 admin。
        $isAccounts = $request->is($prefix . '/accounts', $prefix . '/accounts/*');

        // 配置(/scaffold/config/*)：**只有写**仅 admin。
        //
        // 依据 docs/overview.md 的角色表：「admin（可改任何账号 / 配置）/ member（仅自管资料）」。
        // 原先这里只盖了 accounts，于是「改配置」这一半落空 —— 而 `scaffold.hosts` 正是接口代理的
        // **SSRF 白名单来源**：member 可以 POST /scaffold/config/hosts 把任意 origin 塞进白名单，
        // 再用 /scaffold/api/proxy 打出去。改白名单的权限不该和用白名单的权限一样低。
        //
        // GET 不拦是刻意的：配置页只读、敏感字段已掩码（见 config/index.blade.php 的空白输入），
        // 与 accounts 的「进入即拦」口径不同 —— 后者的列表本身就是人员隐私。
        $isConfigWrite = $request->is($prefix . '/config', $prefix . '/config/*')
            && ! $request->isMethodSafe();

        if (! $isAccounts && ! $isConfigWrite) {
            return $next($request);
        }

        $user = $request->attributes->get('scaffold_auth_user');
        if (! is_string($user) || $user === '') {
            return $next($request);     // auth 关 / 无 user → 不拦
        }

        if ($this->store->isAdmin($user)) {
            return $next($request);
        }

        $message = $isConfigWrite ? '只有 admin 可以修改配置。' : '人员管理仅 admin 可访问。';
        if ($request->ajax() || $request->expectsJson()) {
            return response()->json(['error' => $message], 403);
        }
        if ($request->hasSession()) {
            $request->session()->flash('flash_error', $message);
        }

        return redirect()->to(route('scaffold.home'))->setStatusCode(303);
    }
}
