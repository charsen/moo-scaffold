<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * 阻止 scaffold 在 production / readonly 模式下对"高风险簇"做 unsafe HTTP method 写入。
 *
 * 历史背景：AccountController 和 ConfigManager 各自实现了写入守护，但
 * ApiController 没做检查 —— 任意已登录用户在 production 也能调代理。
 * 这一层 middleware 统一兜底。
 *
 * plan-22 修订(用户决定):
 *   - 锁死(production / readonly 时拒 POST/PUT/PATCH/DELETE):
 *     · /scaffold/db/designer/*  - 改 yaml / 写 migration / 新建删除 schema 表
 *     · /scaffold/accounts*      - 改账号(密码/启停/删)
 *     · /scaffold/config*        - 改 scaffold 自身配置
 *     · /scaffold/cloud/push     - 手动推送 + 回收本地缓冲(只适用于本地)
 *   - 放行(即使 production / readonly):
 *     · api/cache + proxy          - 调试日常
 *     · csp-report                          - 日志
 *
 * 安全方法(GET / HEAD / OPTIONS)永远放行。
 */
class EnforceScaffoldWritable
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * 生产/只读环境下被锁的路径(相对路由前缀,不带前导 `/`)。
     * handle() 里按 config('scaffold.route.prefix') 拼上实际前缀 —— 不能写死 `scaffold/`,
     * 否则 host 改了 route.prefix 后所有 pattern 失配、写防线静默失效(2026-06-09 修;
     * SecurityHeaders 早就按 config 取前缀,这里之前漏了对齐)。
     */
    private const LOCKED_SUFFIXES = [
        'db/designer',
        'db/designer/*',
        'accounts',
        'accounts/*',
        'config',
        'config/*',
        // 手动触发 moo:cloud:push:推送 + 回收本地缓冲属写类,只适用于本地;生产/只读拒绝。
        // (GET /scaffold/cloud 状态页是 safe method,永远放行,任何环境可看。)
        'cloud/push',
        // plan-52 文档中心:新建/编辑/删除/实时预览全是写类(团队本地编辑),生产只读预览。
        // (GET /docs、/docs/{path}、/docs/_diagram 是 safe method,永远放行,生产可看可点深链。)
        'docs',
        'docs/*',
    ];

    public function handle(Request $request, Closure $next)
    {
        if (in_array($request->method(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        $isProduction = app()->environment('production');
        $isReadonly   = (bool) config('scaffold.config_ui.readonly', false);

        // 开发环境且非强制只读 → 放行所有 W
        if (! $isProduction && ! $isReadonly) {
            return $next($request);
        }

        // 生产 / 只读:仅锁高风险簇,其它 W 仍放行。按实际配置的路由前缀拼 pattern。
        $prefix   = trim((string) config('scaffold.route.prefix', 'scaffold'), '/');
        $patterns = array_map(static fn ($s) => $prefix . '/' . $s, self::LOCKED_SUFFIXES);
        if (! $request->is(...$patterns)) {
            return $next($request);
        }

        $reason = $isProduction
            ? '生产环境禁止改 designer / accounts / config(影响代码与账号体系)'
            : '当前为强制只读模式(SCAFFOLD_CONFIG_READONLY),禁止改 designer / accounts / config';

        return $this->forbidden($request, $reason);
    }

    private function forbidden(Request $request, string $message)
    {
        if ($request->ajax() || $request->expectsJson()) {
            return response()->json(['error' => $message], 403);
        }
        if ($request->hasSession()) {
            $request->session()->flash('flash_error', $message);
        }

        return redirect()->back()->withInput()->setStatusCode(303);
    }
}
