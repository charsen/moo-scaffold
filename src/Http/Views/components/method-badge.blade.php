@props([
    'method' => null,
    'size' => 'md',
])

@php
$key = strtolower(trim($method ?? ''));
$known = ['get', 'post', 'put', 'patch', 'delete', 'any'];
$variant = in_array($key, $known, true) ? $key : null;
@endphp

<span {{ $attributes->class([
    'method-badge',
    'method-badge--' . $variant => $variant !== null,
    'method-badge--sm' => $size === 'sm',
]) }}>{{ strtoupper($method ?? '') }}</span>
