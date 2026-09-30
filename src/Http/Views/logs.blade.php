<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {!! \Opcodes\LogViewer\Facades\LogViewer::favicon() !!}
    <title>应用日志 - {{ config('app.name', 'Scaffold') }}</title>
    {{-- 直接加载安装版本的资源，避免已发布的旧资源与当前 API 不匹配。 --}}
    {!! preg_replace('/^<style>/', '<style nonce="' . e($cspNonce) . '">', (string) \Opcodes\LogViewer\Facades\LogViewer::css(), 1) !!}
    <link rel="stylesheet" href="{{ asset('vendor/scaffold/css/log-viewer.css') }}">
</head>
<body class="h-full px-3 lg:px-5 bg-gray-100 dark:bg-gray-900">
<div id="log-viewer" class="flex h-full max-h-screen max-w-full">
    <router-view></router-view>
</div>
@if (\Mooeen\Scaffold\Support\ReadonlyMode::active())
    <div id="scaffold-log-viewer-readonly" role="status">只读模式</div>
@endif
<div id="scaffold-log-viewer-auth" role="alertdialog" aria-modal="true" aria-labelledby="scaffold-log-viewer-auth-title" hidden>
    <div>
        <h1 id="scaffold-log-viewer-auth-title">登录已失效，请重新登录</h1>
        <p>重新登录后将返回当前日志页面。</p>
        <a id="scaffold-log-viewer-login" href="{{ route('scaffold.login', [], false) }}">重新登录</a>
    </div>
</div>
@php
    // 本布局直接内联安装资源，已发布资源的版本不会影响当前页面。
    $logViewerScriptVariables['assets_outdated'] = false;
    $scaffoldLogViewer = [
        'apiPath' => '/' . trim(config('log-viewer.route_path'), '/') . '/api',
        'loginPath' => route('scaffold.login', [], false),
        'readonly' => \Mooeen\Scaffold\Support\ReadonlyMode::active(),
    ];
@endphp
<script nonce="{{ $cspNonce }}">
    window.LogViewer = @json($logViewerScriptVariables);
    window.ScaffoldLogViewer = @json($scaffoldLogViewer);
    {!! \Mooeen\Scaffold\Support\LogViewerIntegration::script() !!}
</script>
{!! preg_replace('/^<script>/', '<script nonce="' . e($cspNonce) . '">', (string) \Opcodes\LogViewer\Facades\LogViewer::js(), 1) !!}
</body>
</html>
