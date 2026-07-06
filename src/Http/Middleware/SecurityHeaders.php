<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * 给所有 /scaffold/* 响应附加防御性 HTTP 安全头 + CSP。
 *
 * CSP 设计取舍：
 *   - script-src 用 nonce + 'self'。Alpine 已切到 CSP build（不再 new Function()），
 *     所有 x-data 组件提前通过 Alpine.data() 注册在 /vendor/scaffold/javascript/
 *     alpine-init.js；外部注入的 <script> 标签会因没 nonce 被浏览器拒绝执行。
 *   - style-src 用 nonce + 'self'。所有 <style> 块加 nonce；'unsafe-inline'
 *     做兜底（CSP3 浏览器看到 nonce 会忽略它，老浏览器才用兜底）。
 *   - style-src-attr 'unsafe-inline'。HTML 内联 style="..." 属性大量存在
 *     （表格列宽 / display 等结构性），refactor 不实际；XSS 经此能改样式但
 *     无法执行 JS。
 *   - img-src 加 data: 允许 SVG 内联图标 + base64 截图。
 *   - frame-ancestors 'none' 双保险（已有 X-Frame-Options: DENY）。
 *   - form-action 'self' 阻止表单被骗到外站。
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        // 生成 per-request nonce 用于 inline <script>；视图通过 $cspNonce 拿到
        $nonce = base64_encode(random_bytes(16));
        $request->attributes->set('csp_nonce', $nonce);
        View::share('cspNonce', $nonce);

        $response = $next($request);

        if (! $response instanceof Response) {
            return $response;
        }

        $headers = $response->headers;

        // plan-52:Mermaid 流程图渲染帧（/scaffold/docs/_diagram）单独处理 ——
        //   1) mermaid 运行时用 eval + 注入无 nonce 的 <style>，全站严格 CSP 会拦死它，
        //      故这一个隔离帧放宽到 script-src 'unsafe-eval' + style-src 'unsafe-inline'。
        //   2) 它要被文档页同源 iframe 嵌入，故 frame-ancestors 'self' + X-Frame-Options SAMEORIGIN
        //      （全站默认是 DENY / 'none'，会把这个帧也挡掉）。
        //   隔离帧本身不含任何 doc 内容/密钥：图源由父页 postMessage 传入，渲染逻辑是纯客户端。
        if ($request->routeIs('docs.diagram')) {
            $headers->set('X-Frame-Options', 'SAMEORIGIN');
            $headers->set('X-Content-Type-Options', 'nosniff');
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
            $headers->set('Content-Security-Policy',
                "default-src 'self'; "
                . "script-src 'self' 'unsafe-eval' 'nonce-{$nonce}'; "
                . "style-src 'self' 'unsafe-inline'; "
                . "img-src 'self' data:; "
                . "font-src 'self' data:; "
                . "connect-src 'self'; "
                . "frame-ancestors 'self'; "
                . "base-uri 'self';");

            return $response;
        }

        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');

        // Content-Security-Policy + 违规上报到 /scaffold/csp-report
        $reportUri = '/' . trim((string) config('scaffold.route.prefix', 'scaffold'), '/') . '/csp-report';
        $csp       = "default-src 'self'; "
            . "script-src 'self' 'nonce-{$nonce}'; "
            . "style-src 'self' 'nonce-{$nonce}' 'unsafe-inline'; "
            . "style-src-attr 'unsafe-inline'; "
            . "img-src 'self' data: blob:; "
            . "font-src 'self' data:; "
            . "media-src 'self' blob:; "
            . "connect-src 'self'; "
            . "frame-ancestors 'none'; "
            . "base-uri 'self'; "
            . "form-action 'self'; "
            . "report-uri {$reportUri};";
        $headers->set('Content-Security-Policy', $csp);

        return $response;
    }
}
