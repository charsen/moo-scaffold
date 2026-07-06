{{-- plan-52:Mermaid 隔离渲染帧。独立最小 HTML(不套 shell),被文档页/编辑器预览同源 iframe 嵌入。
     SecurityHeaders 给本路由(docs.diagram)单独放宽 CSP(脚本放开 eval、样式放开内联、frame-ancestors
     'self'),把 mermaid 的 eval 与注入样式关在这一个隔离帧,主站策略不动。样式复用主站 index.css
     (.doc-diagram-frame*,见 7-pages/_docs-center.scss),引导脚本外提到 pages/docs-diagram-frame.js;
     图源不在本页 —— 由父页 postMessage 传入,渲染纯客户端。 --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>流程图</title>
    <link rel="stylesheet" href="/vendor/scaffold/css/index.css?v={{ @filemtime(public_path('vendor/scaffold/css/index.css')) ?: time() }}">
    <script src="/vendor/scaffold/javascript/vendor/mermaid.min.js?v={{ @filemtime(public_path('vendor/scaffold/javascript/vendor/mermaid.min.js')) ?: time() }}"></script>
</head>
<body class="doc-diagram-frame">
    <div id="d" class="doc-diagram-frame__out"></div>
    <script src="/vendor/scaffold/javascript/pages/docs-diagram-frame.js?v={{ @filemtime(public_path('vendor/scaffold/javascript/pages/docs-diagram-frame.js')) ?: time() }}"></script>
</body>
</html>
