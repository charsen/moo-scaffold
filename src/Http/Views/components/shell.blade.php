@props([
    'title' => 'Scaffold',
    'menu' => null,             // 传给 header 的菜单覆写
    'withAside' => null,        // null = 自动判断（aside slot 是否存在）；true/false 强制
    'containerClass' => '',     // 追加到 .container 的修饰类（如 is-collapsed-sidebar）
])

@php
$hasAside = $withAside ?? isset($aside);
// 2026-05-21:app-aware 页面(接口调试/文档/记录/路由)在 header 下方插一行 sub-nav,放 app 切换 tabs。
// 其他页面 subnav slot 不传 → 不渲染容器,无 layout 影响。
$hasSubnav = isset($subnav);
// 2026-05-23 plan-50 后续:可选 topBar slot — 跨 middle+right 的横向工具栏(如 api 调试 tabs)
// 用 grid 布局让 topBar 占整行,middle+right 在下方一行(原 flex row 不动)
$hasTopBar = isset($topBar);
$containerClasses = trim('container' . ($hasAside ? '' : ' pl0') . ($hasTopBar ? ' has-top-bar' : '') . ($containerClass ? ' ' . $containerClass : ''));
@endphp

<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
@include('scaffold::layouts._theme_boot')
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="apple-mobile-web-app-status-bar-style" content="black">
    <meta name="apple-mobile-web-app-title" content="Scaffold">
    <meta name="robots" content="none">
    <title>{{ $title }}</title>
    <link rel="icon" type="image/svg+xml" href="/vendor/scaffold/images/favicon.svg">
    <link rel="stylesheet" href="/vendor/scaffold/css/index.css?v={{ @filemtime(public_path('vendor/scaffold/css/index.css')) ?: time() }}">
    {{-- alpine-init.js 必须在 alpine-csp.min.js **之前**加载，否则 alpine:init listener 来不及挂上 --}}
    <script src="/vendor/scaffold/javascript/alpine-init.js?v={{ @filemtime(public_path('vendor/scaffold/javascript/alpine-init.js')) ?: time() }}"></script>
    <script defer src="/vendor/scaffold/javascript/alpine-csp.min.js"></script>
    {{ $head ?? '' }}
</head>
<body class="{{ $hasTopBar ? 'has-shell-top-bar' : '' }}">
    <x-scaffold::header :menu="$menu" />

    @if($hasSubnav)
    <div class="scaffold-subnav-bar">{{ $subnav }}</div>
    @endif

    @if($hasAside)
        <aside class="aside" id="aside_container">
            {{ $aside }}
        </aside>
    @endif

    {{-- 2026-05-23 plan-50 后续:topBar 移出 main 单独 fixed positioned,跨整个 viewport
         width,跟 aside / main 同时对齐。aside top + main padding-top 通过 .has-top-bar
         class 让出 top-bar 高度 --}}
    @if($hasTopBar)
        <div class="scaffold-top-bar" id="main_top_bar">{{ $topBar }}</div>
    @endif

    {{-- <main> landmark:全站只此一处(shell 是单 layout 入口),
         lighthouse landmark-one-main / 屏读 "跳到主内容" 都靠它 --}}
    <main class="{{ $containerClasses }}" id="main_container">
        @if(isset($middle) || isset($right))
            @isset($middle)
                <div class="left" id="left_container">{{ $middle }}</div>
            @endisset
            @isset($right)
                <div class="right transparent" id="right_container">{{ $right }}</div>
            @endisset
        @else
            {{ $slot }}
        @endif
    </main>

    <x-scaffold::toast-container />
    <x-scaffold::confirm-container />

    <script src="/vendor/scaffold/javascript/jquery-3.7.1.min.js"></script>
    <script src="/vendor/scaffold/javascript/main.js?v={{ @filemtime(public_path('vendor/scaffold/javascript/main.js')) ?: time() }}"></script>
    {{ $scripts ?? '' }}
</body>
</html>
