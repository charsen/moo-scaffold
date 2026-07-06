{{-- plan-22: 通用错误页布局,跟随 light/dark 主题(原 Laravel 默认 errors 强制 dark) --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
@include('scaffold::layouts._theme_boot')
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Scaffold - {{ $code }} {{ $title }}</title>
    <link rel="icon" type="image/svg+xml" href="/vendor/scaffold/images/favicon.svg">
    <link rel="stylesheet" href="/vendor/scaffold/css/index.css?v={{ @filemtime(public_path('vendor/scaffold/css/index.css')) ?: time() }}">
</head>
<body class="p-error">
    <main class="p-error__card" role="main">
        {{-- 2026-06-19:品牌标 —— 用 favicon.svg(自带橙底白 S,明暗主题都安全,无需 dark 底片) --}}
        <img class="p-error__mark" src="/vendor/scaffold/images/favicon.svg" alt="Scaffold" width="48" height="48">
        <div class="p-error__code">{{ $code }}</div>
        <h1 class="p-error__title">{{ $title }}</h1>
        <p class="p-error__desc">{{ $desc }}</p>
        <div class="p-error__actions">
            <a href="{{ url('/scaffold') }}" class="btn btn--primary">回到 Dashboard</a>
            <a href="javascript:history.length>1?history.back():(location.href='{{ url('/scaffold') }}')" class="btn btn--ghost">回上一页</a>
        </div>
        @isset($hint)
            <p class="p-error__hint">{{ $hint }}</p>
        @endisset
    </main>
</body>
</html>
