@props([
    'name' => null,
    'desc' => null,
    'href' => null,
    'active' => false,
])

@php
$tag = $href ? 'a' : 'div';
@endphp

@if($tag === 'a')
<a
    href="{{ $href }}"
    {{ $attributes->class([
        'app-card',
        'is-active' => $active,
    ]) }}
>
@else
<div
    {{ $attributes->class([
        'app-card',
        'is-active' => $active,
    ]) }}
>
@endif

    @isset($icon)
        <div class="app-card__icon">{{ $icon }}</div>
    @endisset

    <div class="app-card__body">
        @if(filled($name))
            <div class="app-card__name">{{ $name }}</div>
        @endif
        @if(filled($desc))
            <div class="app-card__desc">{{ $desc }}</div>
        @endif
        {{ $slot }}
    </div>

    @isset($badge)
        <div class="app-card__badge">{{ $badge }}</div>
    @endisset

@if($tag === 'a')
</a>
@else
</div>
@endif
