@props([
    'label' => null,
    'value' => null,
    'hint' => null,
    'tone' => 'neutral',
])

<div {{ $attributes->class([
    'stat-card',
    'stat-card--' . $tone => in_array($tone, ['info', 'success', 'warning', 'danger', 'accent'], true),
]) }}>
    @isset($icon)
        <div class="stat-card__icon">{{ $icon }}</div>
    @endisset

    <div class="stat-card__body">
        @if(filled($value))
            <div class="stat-card__value">{{ $value }}</div>
        @endif

        @if(filled($label))
            <div class="stat-card__label">{{ $label }}</div>
        @endif

        @if(filled($hint))
            <div class="stat-card__hint">{{ $hint }}</div>
        @endif

        {{ $slot }}
    </div>
</div>
