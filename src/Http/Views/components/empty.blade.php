@props([
    'title' => null,
    'desc' => null,
    'compact' => false,
])

{{-- 命名 slot：icon（SVG / HTML）、actions（操作按钮组） --}}
<div {{ $attributes->class([
    'empty',
    'empty--compact' => $compact,
]) }} role="status">
    @isset($icon)
        <div class="empty__icon" aria-hidden="true">{{ $icon }}</div>
    @endisset

    @if(filled($title))
        <h4 class="empty__title">{{ $title }}</h4>
    @endif

    @if(filled($desc))
        <p class="empty__desc">{{ $desc }}</p>
    @endif

    {{ $slot }}

    @isset($actions)
        <div class="empty__actions">{{ $actions }}</div>
    @endisset
</div>
