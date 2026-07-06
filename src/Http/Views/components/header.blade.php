@props([
    'menu' => null,         // 自定义菜单，传 null 用默认
    'user' => null,         // 用户名，传 null 用 $scaffold_auth_user
    'showLogo' => true,
])

@php
// 默认菜单（与原 _header.blade.php 一致）
$defaultMenu = [
    // 数据库设计 = 改(designer 编辑器);2026-06-22 数据字典(只读)入口移到「数据库文档」侧,matches 同步剥离
    ['route' => 'db.designer.index', 'label' => '数据库设计', 'icon' => 'database', 'matches' => ['db.designer.*']],
    // 2026-06-20:数据库文档(只读 doc 视图)—— 跟「数据库设计」是 看/改 一对(类比 接口文档/接口调试)
    // 2026-06-22:数据字典(全模块枚举速查,只读)入口归到这里,故 matches 含 dictionaries
    ['route' => 'db.docs',       'label' => '数据库文档', 'icon' => 'wordbook', 'matches' => ['db.docs', 'dictionaries']],
    ['route' => 'api.request',   'label' => '接口调试',  'icon' => 'send', 'matches' => ['api.request']],
    // plan-29:查看 ACL 已合并进接口路由(行 click → 抽屉),顶栏砍掉
    ['route' => 'api.list',      'label' => '接口文档',  'icon' => 'file', 'matches' => ['api.list', 'api.show', 'api.param']],
    ['route' => 'route.list',    'label' => '接口路由',  'icon' => 'protocol', 'matches' => ['route.list', 'acl.list']],
    // plan-52:文档中心(MD + 深链 + Mermaid 流程图;本地编辑,生产只读预览)
    ['route' => 'docs.index',    'label' => '开发文档',  'icon' => 'book', 'matches' => ['docs.*']],
    // 云端汇聚:本地两类缓冲状态 + 云端控制台入口 + 手动推送(详见 /scaffold/cloud)。
    // 运行时错误 / 慢 SQL / Todos 查看器已退役 → 处置统一在 moo-scaffold-cloud,顶栏只留这一个云端入口。
    ['route' => 'cloud.index',   'label' => 'S-Cloud',   'icon' => 'cloud', 'matches' => ['cloud.*']],
];

$menuItems = $menu ?? $defaultMenu;
$userName = $user ?? ($scaffold_auth_user ?? 'Scaffold');
$isHome = request()->routeIs('scaffold.home');
@endphp

{{-- plan-22 T9: inline <style> 已外迁到 5-layout/_header.scss(badge 部分) --}}
<header class="header">
    @if($showLogo)
        <a href="{{ route('scaffold.home') }}"
           class="header__logo {{ $isHome ? 'is-active' : '' }}"
           title="首页"
           aria-label="首页"
           @if($isHome) aria-current="page" @endif
        >
            <img src="/vendor/scaffold/images/logo.png" alt="Scaffold" width="280" height="40">
        </a>
    @endif

    <nav class="header__menu" role="navigation">
        @foreach($menuItems as $item)
            @php
                $matches = $item['matches'] ?? [$item['route']];
                $active = false;
                foreach ($matches as $name) {
                    if (request()->routeIs($name)) { $active = true; break; }
                }
            @endphp
            <a
                href="{{ route($item['route']) }}"
                class="header__menu-item {{ $active ? 'is-active' : '' }}"
                @if($active) aria-current="page" @endif
            >@if (! empty($item['icon']))<x-scaffold::icon :name="$item['icon']" :size="15" class="header__menu-icon" />@endif{{ $item['label'] }}</a>
        @endforeach
    </nav>

    <div class="header__right">
        {{-- 主题切换：单按钮（亮=sun / 暗=moon），点击逻辑由 main.js 中 #theme_toggle 处理（避免与 Alpine 双触发） --}}
        <button
            type="button"
            class="header__theme"
            id="theme_toggle"
            title="切换主题"
            aria-label="切换主题"
        >
            <x-scaffold::icon name="sun" :size="16" class="icon-sun" />
            <x-scaffold::icon name="moon" :size="16" class="icon-moon" />
        </button>

        {{-- P2-B 帮助/文档入口:外链 README,target=_blank + noopener --}}
        <a
            href="https://github.com/charsen/laravel-scaffold"
            target="_blank"
            rel="noopener noreferrer"
            class="header__help-trigger"
            title="文档(GitHub README)"
            aria-label="文档"
        >
            <x-scaffold::icon name="help-circle" :size="16" />
        </a>

        {{-- 齿轮 = 配置中心直达；开发人员管理作为 sidebar 子项，从配置中心进入。
             config_ui.enabled=false 时配置中心整体不可访问(ConfigController abort 404),齿轮一并隐藏避免 404。 --}}
        @if(config('scaffold.config_ui.enabled', true))
        @php
            $configActive = request()->routeIs('scaffold.config*') || request()->routeIs('scaffold.accounts*');
        @endphp
        <a
            href="{{ route('scaffold.config') }}"
            class="header__settings-trigger {{ $configActive ? 'is-active' : '' }}"
            title="Scaffold 配置中心"
            aria-label="Scaffold 配置中心"
            @if($configActive) aria-current="page" @endif
        >
            <x-scaffold::icon name="settings" :size="16" />
        </a>
        @endif

        <div class="header__user" x-data="dropdown" x-on:click.outside="close">
            <button
                type="button"
                class="header__user-trigger"
                x-on:click="toggle"
                aria-haspopup="menu"
                :aria-expanded="ariaExpanded"
            >
                <img class="header__user-avatar" src="/vendor/scaffold/images/cover.png" alt="">
                <span class="header__user-name">{{ $userName }}</span>
                <x-scaffold::icon name="chevron-down" :size="14" />
            </button>

            <div class="header__user-menu" x-show="open" x-cloak role="menu">
                <span class="header__user-menu-title">{{ $userName }}</span>
                <hr class="header__user-menu-divider">
                {{-- POST 表单登出:防 GET CSRF 强制登出(form display:contents 让 button 直接当菜单项) --}}
                <form method="POST" action="{{ route('scaffold.logout') }}" class="header__user-menu-form">
                    @csrf
                    <button type="submit" class="header__user-menu-item" role="menuitem">
                        <x-scaffold::icon name="external-link" :size="14" />
                        退出登录
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>
