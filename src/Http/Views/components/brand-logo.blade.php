@props([
    'label' => 'Scaffold',
    'decorative' => false,
])

<span
    @if ($decorative)
        aria-hidden="true"
    @else
        role="img"
        aria-label="{{ $label }}"
    @endif
    {{ $attributes->class(['brand-logo']) }}
></span>
