@props([
    'variant' => 'secondary',
    'size' => 'md',
    'block' => false,
    'href' => null,
    'type' => 'button',
    'disabled' => false,
    'loading' => false,
])

@php
$isInactive = $disabled || $loading;
$classes = $attributes->class([
    'btn',
    'btn--primary' => $variant === 'primary',
    'btn--secondary' => $variant === 'secondary',
    'btn--ghost' => $variant === 'ghost',
    'btn--success' => $variant === 'success',
    'btn--danger' => $variant === 'danger',
    'btn--sm' => $size === 'sm',
    'btn--lg' => $size === 'lg',
    'btn--xl' => $size === 'xl',
    'btn--block' => $block,
    'is-loading' => $loading,
    'is-disabled' => $disabled && $href,
]);
@endphp

@if($href)
    <a href="{{ $href }}" @if($isInactive) aria-disabled="true" tabindex="-1" @endif {{ $classes }}>@if($loading)<span class="btn__spinner" aria-hidden="true"></span>@endif{{ $slot }}</a>
@else
    <button type="{{ $type }}" @if($isInactive) disabled @endif {{ $classes }}>@if($loading)<span class="btn__spinner" aria-hidden="true"></span>@endif{{ $slot }}</button>
@endif
