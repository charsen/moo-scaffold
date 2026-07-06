@props([
    'label' => null,
    'hint' => null,
    'error' => null,
    'for' => null,
])

<div {{ $attributes->class([
    'field',
    'field--error' => filled($error),
]) }}>
    @if($label)
        <label class="field__label" @if($for) for="{{ $for }}" @endif>{{ $label }}</label>
    @endif

    {{ $slot }}

    @if(filled($error))
        <p class="field__hint">{{ $error }}</p>
    @elseif(filled($hint))
        <p class="field__hint">{{ $hint }}</p>
    @endif
</div>
