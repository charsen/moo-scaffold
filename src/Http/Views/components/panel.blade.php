@props([
    'class' => '',          // 追加修饰类（dashboard-commands / dashboard-hero 等）
    'hdClass' => '',        // 追加到 .hd 上的类（如 api-debug-response-tabs 这种和 .hd 紧耦合的样式）
    'wrapBody' => true,     // 默认把 slot 包进单个 .bd；多 .bd 兄弟场景设 false，调用方自己写 <div class="bd">
])

{{-- Panel: 结构性容器（.panel > .hd? + .bd*）
     样式见 6-components/_panel.scss
     hd slot 完全替代原 <div class="hd">...</div>，调用方可放 h2/h3/actions/icon
     wrapBody=false 用于多 .bd 兄弟场景（如 tab pane：body / headers 各占一个 .bd）
--}}
<div {{ $attributes->merge(['class' => trim('panel ' . $class)]) }}>
    @isset($hd)
        <div class="{{ trim('hd ' . $hdClass) }}">{{ $hd }}</div>
    @endisset
    @if ($wrapBody)
        <div class="bd">{{ $slot }}</div>
    @else
        {{ $slot }}
    @endif
</div>
