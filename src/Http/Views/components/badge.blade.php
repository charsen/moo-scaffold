@props([
    'tone' => 'neutral',
    'size' => 'md',
    'solid' => false,
])

<span {{ $attributes->class([
    'badge',
    'badge--' . $tone => in_array($tone, ['neutral', 'info', 'success', 'warning', 'danger', 'accent'], true),
    'badge--sm' => $size === 'sm',
    'badge--solid' => $solid,
]) }}>{{ $slot }}</span>
