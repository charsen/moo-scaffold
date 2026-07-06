@props([
    'apps' => [],          // [key => label] 字典
    'current' => null,     // 当前激活的 app key(可为 null:未选状态)
    'route' => null,       // 切换 app 时跳的 route name(如 api.request / api.list / route.list)
])

{{-- 2026-05-21:header 下方 app 切换 sub-nav(填充 pill tabs,跟主菜单 active 风格协调) --}}
{{-- 单 app / 无 route 时不渲染容器,避免占位 --}}
@if (count($apps) > 1 && $route)
<nav class="scaffold-app-tabs" aria-label="切换调试应用">
    <span class="scaffold-app-tabs__label">应用</span>
    <div class="scaffold-app-tabs__list">
        @foreach ($apps as $key => $label)
            <a
                href="{{ route($route, ['app' => $key]) }}"
                class="scaffold-app-tabs__tab{{ $key === $current ? ' is-active' : '' }}"
                @if($key === $current) aria-current="page" @endif
            >{{ $label }}</a>
        @endforeach
    </div>
</nav>
@endif
