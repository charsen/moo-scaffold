<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Mooeen\Scaffold\Auth\ScaffoldAuth;
use Mooeen\Scaffold\Support\AccountStore;

class ScaffoldAuthenticate
{
    public function __construct(
        protected ScaffoldAuth $auth,
        protected AccountStore $accounts,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        if (! $this->auth->isEnabled()) {
            return $next($request);
        }

        $user = $this->auth->authenticateRequest($request);
        if ($user === null) {
            $redirectTarget = $request->ajax() || $request->expectsJson()
                ? (string) $request->headers->get('referer', $request->getRequestUri())
                : $request->getRequestUri();
            $loginUrl = $this->auth->loginUrl($request, $redirectTarget);

            if ($request->ajax() || $request->expectsJson()) {
                return response('Unauthorized', 401, [
                    'X-Scaffold-Auth'  => 'required',
                    'X-Scaffold-Login' => $loginUrl,
                ])->withCookie($this->auth->forgetCookie());
            }

            return redirect()->to($loginUrl)->withCookie($this->auth->forgetCookie());
        }

        $request->attributes->set('scaffold_auth_user', $user['username']);
        view()->share('scaffold_auth_user', $user['username']);
        // 人员管理入口 / 权限可见性:把当前用户是否 admin 共享给所有视图
        $isAdmin = $this->accounts->isAdmin($user['username']);
        $request->attributes->set('scaffold_is_admin', $isAdmin);
        view()->share('scaffold_is_admin', $isAdmin);

        Cookie::queue($this->auth->makeCookie($user['username'], $request));

        return $next($request);
    }
}
