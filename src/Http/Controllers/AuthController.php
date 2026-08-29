<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Controllers;

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Auth\ScaffoldAuth;
use Mooeen\Scaffold\Foundation\FormRequest;
use Mooeen\Scaffold\Http\Requests\Auth\LoginRequest;
use Mooeen\Scaffold\Http\Requests\Auth\ShowLoginRequest;
use Mooeen\Scaffold\Http\Requests\ContextRequest;
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

    public function showLogin(ShowLoginRequest $request)
    {
        $validated = $request->validated();

        if (! $this->auth->isEnabled()) {
            return redirect()->route('scaffold.home');
        }

        if ($this->auth->authenticateRequest($request) !== null) {
            return redirect()->to($this->auth->sanitizeRedirect($validated['redirect'] ?? null));
        }

        return $this->renderLogin($request, redirect: (string) ($validated['redirect'] ?? ''));
    }

    public function login(LoginRequest $request)
    {
        if (! $this->auth->isEnabled()) {
            return redirect()->route('scaffold.home');
        }

        $validated = $request->validated();
        $username  = trim((string) ($validated['username'] ?? ''));
        $password  = (string) ($validated['password'] ?? '');
        $redirect  = $this->auth->sanitizeRedirect((string) ($validated['redirect'] ?? ''));

        // plan-40 §五 F2:基础卫生 — 拒绝空值,跟 auth->attempt 失败走同一 UX 渲染
        if ($username === '' || $password === '') {
            return $this->renderLogin($request, '用户名和密码不能为空。', 422, $username, $redirect);
        }
        if (! $this->auth->attempt($username, $password)) {
            return $this->renderLogin($request, '用户名或密码错误。', 422, $username, $redirect);
        }

        return redirect()->to($redirect)->withCookie($this->auth->makeCookie($username, $request));
    }

    public function logout(ContextRequest $request)
    {
        return redirect()
            ->route('scaffold.login')
            ->withCookie($this->auth->forgetCookie());
    }

    private function renderLogin(FormRequest $request, ?string $error = null, int $status = 200, string $username = '', string $redirect = '')
    {
        // 没有任何启用账号 → 登录永远会失败，直接在页面上提示去 CLI 引导，避免用户反复尝试。
        $noAccounts = empty($this->auth->getAccounts());

        return response()->view('scaffold::auth.login', [
            'uri'           => $request->getPathInfo(),
            'redirect'      => $this->auth->sanitizeRedirect($redirect),
            'error'         => $error,
            'username'      => $username,
            'auth_ttl_days' => round($this->auth->getTtlMinutes() / 60 / 24, 1),
            'no_accounts'   => $noAccounts,
        ], $status);
    }
}
