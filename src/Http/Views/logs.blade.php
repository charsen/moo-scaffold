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
</head>
<body class="h-full px-3 lg:px-5 bg-gray-100 dark:bg-gray-900">
<div id="log-viewer" class="flex h-full max-h-screen max-w-full">
    <router-view></router-view>
</div>
<script nonce="{{ $cspNonce }}">
    window.LogViewer = @json($logViewerScriptVariables);
</script>
{!! preg_replace('/^<script>/', '<script nonce="' . e($cspNonce) . '">', (string) \Opcodes\LogViewer\Facades\LogViewer::js(), 1) !!}
</body>
</html>
