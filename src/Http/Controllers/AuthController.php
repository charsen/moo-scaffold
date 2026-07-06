<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Controllers;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Mooeen\Scaffold\Auth\ScaffoldAuth;
use Mooeen\Scaffold\Utility;

class AuthController extends Controller
{
    public function __construct(
        protected ScaffoldAuth $auth,
        Utility $utility,
        Filesystem $filesystem
    ) {
        parent::__construct($utility, $filesystem);
    }

    public function showLogin(Request $request)
    {
        if (! $this->auth->isEnabled()) {
            return redirect()->route('scaffold.home');
        }

        if ($this->auth->authenticateRequest($request) !== null) {
            return redirect()->to($this->auth->sanitizeRedirect($request->query('redirect')));
        }

        return $this->renderLogin($request);
    }

    public function login(Request $request)
    {
        if (! $this->auth->isEnabled()) {
            return redirect()->route('scaffold.home');
        }

        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');
        $redirect = $this->auth->sanitizeRedirect((string) $request->input('redirect', ''));

        // plan-40 §五 F2:基础卫生 — 拒绝空值,跟 auth->attempt 失败走同一 UX 渲染
        if ($username === '' || $password === '') {
            return $this->renderLogin($request, '用户名和密码不能为空。', 422, $username);
        }
        if (! $this->auth->attempt($username, $password)) {
            return $this->renderLogin($request, '用户名或密码错误。', 422, $username);
        }

        return redirect()->to($redirect)->withCookie($this->auth->makeCookie($username, $request));
    }

    public function logout(Request $request)
    {
        return redirect()
            ->route('scaffold.login')
            ->withCookie($this->auth->forgetCookie());
    }

    private function renderLogin(Request $request, ?string $error = null, int $status = 200, string $username = '')
    {
        // 没有任何启用账号 → 登录永远会失败，直接在页面上提示去 CLI 引导，避免用户反复尝试。
        $noAccounts = empty($this->auth->getAccounts());

        return response()->view('scaffold::auth.login', [
            'uri'           => $request->getPathInfo(),
            'redirect'      => $this->auth->sanitizeRedirect((string) $request->input('redirect', '')),
            'error'         => $error,
            'username'      => $username,
            'auth_ttl_days' => round($this->auth->getTtlMinutes() / 60 / 24, 1),
            'no_accounts'   => $noAccounts,
        ], $status);
    }
}
