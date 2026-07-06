@props([
    'compact' => false,
    'striped' => false,
    'borderless' => false,
])

<table {{ $attributes->class([
    'table',
    'table--compact' => $compact,
    'table--striped' => $striped,
    'table--borderless' => $borderless,
]) }}>
    {{ $slot }}
</table>
