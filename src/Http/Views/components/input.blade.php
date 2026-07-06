@props([
    'type' => 'text',
    'size' => 'md',
    'autofocus' => false,
])

<input
    type="{{ $type }}"
    @if($autofocus) autofocus @endif
    {{ $attributes->class([
        'input',
        'input--sm' => $size === 'sm',
        'input--lg' => $size === 'lg',
        'input--xl' => $size === 'xl',
    ]) }}
/>
