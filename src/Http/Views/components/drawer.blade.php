@props([
    'name' => null,
    'title' => null,
    'width' => '420px',
    'side' => 'right',
])

@php
$drawerName = $name ?? 'drawer_' . substr(md5((string) microtime(true)), 0, 8);
@endphp

<div
    class="drawer"
    x-data="drawer"
    data-drawer-name="{{ $drawerName }}"
    x-show="open"
    x-cloak
    @open-drawer.window="handleOpenEvent"
    @close-drawer.window="handleCloseEvent"
    @keydown.escape.window="handleEscape"
    role="dialog"
    aria-modal="true"
    aria-label="{{ $title ?? '抽屉' }}"
>
    <div
        class="drawer__backdrop"
        @click="closeDrawer"
        x-transition:enter="drawer-bd-enter"
        x-transition:enter-start="drawer-bd-enter-start"
        x-transition:enter-end="drawer-bd-enter-end"
        x-transition:leave="drawer-bd-leave"
        x-transition:leave-start="drawer-bd-leave-start"
        x-transition:leave-end="drawer-bd-leave-end"
    ></div>

    <aside
        class="drawer__panel drawer__panel--{{ $side }}"
        style="--drawer-width: {{ $width }}"
        x-transition:enter="drawer-pn-enter"
        x-transition:enter-start="drawer-pn-enter-start--{{ $side }}"
        x-transition:enter-end="drawer-pn-enter-end"
        x-transition:leave="drawer-pn-leave"
        x-transition:leave-start="drawer-pn-leave-start"
        x-transition:leave-end="drawer-pn-leave-end--{{ $side }}"
    >
        @if(filled($title) || isset($header))
            <header class="drawer__header">
                @isset($header)
                    {{ $header }}
                @else
                    <h3 class="drawer__title">{{ $title }}</h3>
                @endisset
                <button type="button" class="drawer__close" @click="closeDrawer" aria-label="关闭">
                    <x-scaffold::icon name="close" :size="18" />
                </button>
            </header>
        @endif

        <div class="drawer__body">
            {{ $slot }}
        </div>

        @isset($footer)
            <footer class="drawer__footer">{{ $footer }}</footer>
        @endisset
    </aside>
</div>
