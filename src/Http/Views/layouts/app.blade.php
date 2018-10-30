<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
     <title>@yield('title', 'Charsen/Scaffold')</title>
    <link href="https://lib.baomitu.com/semantic-ui/2.3.3/semantic.min.css" rel="stylesheet">
    <link rel="icon" href="/favicon.ico" type="image/x-icon" />
    <script src="https://libs.baidu.com/jquery/1.11.3/jquery.min.js"></script>
    <script src="https://lib.baomitu.com/semantic-ui/2.3.3/semantic.min.js"></script>
    <meta name="robots" content="none" />
    @yield('styles')
</head>

<body style="padding: 35px 0">
    @include('scaffold::layouts._header')

    @yield('content')
    <br /><br />
    @yield('scripts')
</body>
</html>
