<?php

declare(strict_types=1);

/*
 * @Author: Charsen
 * @Description: Api 调试代理转发（SSRF 白名单 + TLS 强制 + 禁 redirect）
 */

namespace Mooeen\Scaffold\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 接口调试器的 HTTP 转发代理（解决跨域）。
 *
 * 从 ApiController 析出：与接口文档/参数装配自成闭环、零共享状态、SSRF 安全敏感，
 * 单独成类便于审计。基类 config() 即够用，无独立构造。
 */
class ApiProxyController extends Controller
{
    /**
     * 代理转发接口请求（解决跨域）
     */
    public function proxy(Request $req)
    {
        // plan-40 §五 F3:url + method 上 validate 作为白名单的第二防线
        // (isAllowedProxyUrl 是主防线,但代码迁移 / 异步队列重组时容易绕开,validate 永远先跑)
        $req->validate([
            '_proxy_url'    => 'required|string|url|max:2000',
            '_proxy_method' => 'nullable|string|in:GET,POST,PUT,PATCH,DELETE,get,post,put,patch,delete',
        ]);
        $url            = $req->input('_proxy_url');
        $method         = strtoupper($req->input('_proxy_method', 'GET'));
        $headers        = $req->input('_proxy_headers', []);
        $params         = $req->input('_proxy_params', []);
        $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

        if (empty($url)) {
            return response()->json(['_proxy_status' => 400, 'message' => 'Missing _proxy_url']);
        }

        if (! in_array($method, $allowedMethods, true)) {
            return response()->json(['_proxy_status' => 400, 'message' => 'Unsupported _proxy_method']);
        }

        if (! $this->isAllowedProxyUrl($req, $url)) {
            // plan-22 安全审计 Q3:拒绝时 audit log,便于排查异常流量
            Log::warning('scaffold.api.proxy.denied', [
                'url'    => $url,
                'method' => $method,
                'ip'     => $req->ip(),
                'user'   => $req->attributes->get('scaffold_auth_user', '?'),
            ]);

            return response()->json(['_proxy_status' => 403, 'message' => 'Proxy target is not allowed']);
        }

        try {
            $http = $this->buildProxyClient(is_array($headers) ? $headers : []);

            if ($method === 'GET') {
                $response = $http->get($url, $params);
            } else {
                $response = $http->asForm()->{strtolower($method)}($url, $params);
            }

            // 故意不 follow redirect:server 把 http 跳到 https 这种通常说明 host config 写错了,
            // 直接把 301/302 返回给前端,UI 能立刻看到 Location 头,自己改正确的 hosts 配置。
            //
            // _proxy_status / _proxy_headers 只能挂在「关联数组」上:
            //   - 上游返回标量 JSON("pong" / 123 / true)→ 往标量挂键直接 throw
            //     "Cannot use a scalar value as an array" → 被 catch 误报成 502 Proxy Error;
            //   - 上游返回 JSON 列表([{...},{...}])→ 挂 string key 会把 array 改形成 object
            //     ({"0":...,"1":...}),前端展示 / form-preview 的 Array shape 检测全被破坏。
            // 两类都包进 data 下原样透传(2026-06-10 修);非 JSON body 维持 _raw 约定。
            $json = $response->json();
            if (is_array($json) && ! array_is_list($json)) {
                $body = $json;
            } elseif ($json !== null) {
                $body = ['data' => $json];
            } else {
                $body = ['_raw' => $response->body()];
            }
            $body['_proxy_status'] = $response->status();
            // 多值响应头(典型:登录响应一次性 Set-Cookie 多条)原先只取 [0] → 调试时只能看到第一
            // 个 cookie,其余静默丢失。改为全部值逗号拼接展示(2026-06-10 修)。
            $body['_proxy_headers'] = array_map(
                static fn ($v) => is_array($v) ? implode(', ', $v) : (string) $v,
                array_change_key_case($response->headers(), CASE_LOWER)
            );

            return response()->json($body);
        } catch (\Throwable $e) {
            return response()->json(['_proxy_status' => 502, 'message' => 'Proxy Error: ' . $e->getMessage()]);
        }
    }

    // ---- Private Methods ----

    private function buildProxyClient(array $headers)
    {
        // 强制校验 TLS + 不 follow redirect:HTTPS 证书 / 协议配错就报真错,不留绕过开关。
        $timeout = (int) ($this->config('proxy.timeout') ?? 30);

        // connectTimeout:不设的话 Guzzle 连接阶段无独立上限,只受总 timeout 约束 → 调试一个
        // 白名单里但已宕机/防火墙黑洞的 host,要干等满 30s 才报错、整个调试器卡死。给连接阶段一个
        // 较短上限(≤10s 且不超过总超时),连不上快速失败(2026-06-10 修)。
        return Http::withHeaders($headers)
            ->timeout($timeout)
            ->connectTimeout(min(10, max(1, $timeout)))
            ->withOptions(['allow_redirects' => false]);
    }

    private function isAllowedProxyUrl(Request $req, string $url): bool
    {
        // plan-22 安全审计 Q3:显式协议白名单(原靠 origin match 隐含挡 file/gopher,显式写出来更稳)
        $scheme = strtolower((string) parse_url(trim($url), PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $targetOrigin = $this->normalizeOrigin($url);
        if ($targetOrigin === null) {
            return false;
        }

        return in_array($targetOrigin, $this->getAllowedProxyOrigins($req), true);
    }

    private function getAllowedProxyOrigins(Request $req): array
    {
        $origins = [];
        foreach (array_values($this->config('hosts') ?: []) as $hostUrl) {
            $origin = $this->normalizeOrigin((string) $hostUrl);
            if ($origin !== null) {
                $origins[] = $origin;
            }
        }

        if (! empty($origins)) {
            return array_values(array_unique($origins));
        }

        return [$this->buildOrigin([
            'scheme' => $req->getScheme(),
            'host'   => $req->getHost(),
            'port'   => $req->getPort(),
        ])];
    }

    private function normalizeOrigin(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        return $this->buildOrigin($parts);
    }

    private function buildOrigin(array $parts): string
    {
        $scheme      = strtolower((string) ($parts['scheme'] ?? 'http'));
        $host        = strtolower((string) ($parts['host'] ?? ''));
        $port        = isset($parts['port']) ? (int) $parts['port'] : null;
        $defaultPort = $scheme === 'https' ? 443 : 80;

        return $scheme . '://' . $host . (($port !== null && $port !== $defaultPort) ? ':' . $port : '');
    }
}
