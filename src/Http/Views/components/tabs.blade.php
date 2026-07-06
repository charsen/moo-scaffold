@props([
    'tabs' => [],            // [['key' => '...', 'label' => '...'], ...]
    'default' => null,       // 默认激活的 key；不传则取第一个
    'variant' => 'underline', // underline | pills
])

@php
$first = $tabs[0]['key'] ?? null;
$defaultKey = $default ?? $first;
@endphp

<div
    {{ $attributes->class([
        'tabs',
        'tabs--pills' => $variant === 'pills',
    ]) }}
    x-data="tabs"
    data-default-key="{{ $defaultKey }}"
>
    <div class="tabs__list" role="tablist">
        @foreach ($tabs as $tab)
            @php
                $key = $tab['key'] ?? null;
                $label = $tab['label'] ?? $key;
            @endphp
            @if($key)
                <button
                    type="button"
                    role="tab"
                    class="tabs__tab"
                    data-tab-key="{{ $key }}"
                    aria-selected="false"
                    @click="setActiveFromBtn"
                >{{ $label }}</button>
            @endif
        @endforeach
    </div>

    <div class="tabs__panels">
        {{ $slot }}
    </div>
</div>
