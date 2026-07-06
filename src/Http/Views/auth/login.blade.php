<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
@include('scaffold::layouts._theme_boot')
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Scaffold - 登录</title>
    <link rel="icon" type="image/svg+xml" href="/vendor/scaffold/images/favicon.svg">
    <link rel="stylesheet" href="/vendor/scaffold/css/index.css?v={{ @filemtime(public_path('vendor/scaffold/css/index.css')) ?: time() }}">
</head>
<body class="p-login">
    {{-- 2026-06-19 plan-A:登录页升为分栏 —— 左品牌/身份栏(流水线 signature 呼应首页 hero)+ 右表单。
         左栏底用 accent 渐变(主题感知),文字走 text-heading/-desc(明暗都 legible);流水线节点与首页同语汇。 --}}
    <div class="p-login__split">
        <aside class="p-login__brand-panel">
            <div class="p-login__brand-inner">
                <img class="p-login__logo" src="/vendor/scaffold/images/logo.png" alt="Scaffold" width="280" height="40">
                <h1 class="p-login__headline">Schema 驱动代码生成</h1>
                <p class="p-login__tagline">一份 YAML schema，一条命令铺出 Model · Resource · Controller · Request · Migration。</p>

                <ol class="p-login__pipeline" aria-label="代码生成流水线">
                    <li class="p-login__node"><span class="p-login__stage is-input">YAML</span></li>
                    <li class="p-login__node"><span class="p-login__stage">fresh</span></li>
                    <li class="p-login__node"><span class="p-login__stage">model</span></li>
                    <li class="p-login__node"><span class="p-login__stage">controller</span></li>
                    <li class="p-login__node"><span class="p-login__stage">api</span></li>
                </ol>
            </div>
        </aside>

        <main class="p-login__form-panel">
            <div class="p-login__form-inner">
                <header class="p-login__form-head">
                    <h2 class="p-login__form-title">登录开发后台</h2>
                    <p class="p-login__intro">请使用 scaffold/accounts.yaml 中的账号登录</p>
                </header>

                @if (! empty($no_accounts))
                    <div class="p-login__alert" role="alert">
                        尚未配置任何启用的开发人员账号。<br>
                        请在服务器上执行：<code>php artisan moo:account:add</code> 引导第一个账号。
                    </div>
                @endif

                <form class="p-login__form" method="post" action="{{ route('scaffold.login.submit') }}">
                    @csrf
                    <input type="hidden" name="redirect" value="{{ $redirect }}">

                    <x-scaffold::field label="用户名" for="username">
                        <x-scaffold::input
                            id="username"
                            size="xl"
                            name="username"
                            value="{{ $username ?? '' }}"
                            autocomplete="username"
                            :autofocus="empty($username)"
                            required
                        />
                    </x-scaffold::field>

                    <x-scaffold::field label="密码" for="password">
                        <x-scaffold::input
                            id="password"
                            size="xl"
                            type="password"
                            name="password"
                            autocomplete="current-password"
                            :autofocus="! empty($username)"
                            required
                        />
                    </x-scaffold::field>

                    @if (! empty($error))
                        <div class="p-login__alert" role="alert">{{ $error }}</div>
                    @endif

                    <x-scaffold::btn variant="primary" size="xl" type="submit" block>登录</x-scaffold::btn>

                    <p class="p-login__meta">登录成功后会写入一枚仅用于 scaffold 的认证 Cookie，连续 {{ $auth_ttl_days }} 天未操作后需要重新登录。</p>
                </form>
            </div>
        </main>
    </div>
</body>
</html>
