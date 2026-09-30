<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Mooeen\Scaffold\Support\LogViewerIntegration;
use Mooeen\Scaffold\Support\ReadonlyMode;

/** 原生 Log Viewer 全部路由共用 Scaffold 认证与只读策略。 */
final class ScaffoldLogViewer
{
    public function __construct(private ScaffoldAuthenticate $authenticate) {}

    public function handle(Request $request, Closure $next)
    {
        abort_unless(LogViewerIntegration::enabled(), 404);

        // 原生 API（含签名下载）无 Accept 头也固定 401；认证只执行一次。
        $path  = trim((string) config('log-viewer.route_path'), '/');
        $isApi = $request->is($path . '/api', $path . '/api/*');

        return $this->authenticate->handle($request, function ($request) use ($next) {
            abort_if($request->isMethod('DELETE') || $request->routeIs('log-viewer.files.delete-multiple-files'), 403);
            abort_if(! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && ReadonlyMode::active(), 403);

            return $next($request);
        }, $isApi);
    }
}
