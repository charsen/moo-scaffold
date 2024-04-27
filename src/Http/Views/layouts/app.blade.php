<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
    <meta name="viewport" content="initial-scale=1, maximum-scale=1, user-scalable=no, width=device-width">
    <meta name="apple-mobile-web-app-status-bar-style" content="black">
    <meta name="apple-mobile-web-app-title" content="Scaffold">
    <title>@yield('title', 'Mooeen/Scaffold')</title>
    <link rel="stylesheet" href="/vendor/scaffold/css/index.css" />
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

    <div class="container">
        <div class="left" id="left_container">
            @yield('middle')
        </div>
        <div class="right transparent" id="right_container">
            @yield('right')
        </div>
    </div>

    <script src="/vendor/scaffold/javascript/jquery-1.11.3.min.js"></script>
    <script src="/vendor/scaffold/javascript/jquery.cookie.min.js"></script>
    <script src="/vendor/scaffold/javascript/main.js"></script>
    @yield('scripts')
</body>
</html>
