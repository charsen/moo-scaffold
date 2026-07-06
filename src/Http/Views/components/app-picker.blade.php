@props([
    'columns' => 4,
])

<div {{ $attributes->class([
    'app-picker',
    'app-picker--cols-' . (int) $columns => $columns !== 4,
]) }}>
    {{ $slot }}
</div>
