<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
    <meta name="viewport" content="initial-scale=1, maximum-scale=1, user-scalable=no, width=device-width">
    <meta name="apple-mobile-web-app-status-bar-style" content="black">
    <meta name="apple-mobile-web-app-title" content="Scaffold">
    <title>@yield('title', 'Charsen/Scaffold')</title>
    <link rel="stylesheet" href="/scaffold/css/index.css?v={{$version}}" />
    <meta name="robots" content="none" />
    @yield('styles')
</head>

<body>
    @include('scaffold::layouts._header')

    <div class="aside" id="aside_container">
        <ul>
            @yield('sidebar')
        </ul>
    </div>

    <div class="container pl0">
        <div class="right ml0">
            @yield('right')
        </div>
    </div>

    <script src="/scaffold/javascript/jquery-1.11.3.min.js"></script>
    <script src="/scaffold/javascript/jquery.cookie.min.js"></script>
    <script src="/scaffold/javascript/main.js?v={{$version}}"></script>
    @yield('scripts')
</body>
</html>
