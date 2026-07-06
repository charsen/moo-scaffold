{{--
    Scaffold 通用页面 hero 区(plan-22 P1-S1 收口 6 套自闭 hero)

    用法:
        <x-scaffold::hero icon="file" title="接口文档">
            <x-slot:prefix><a class="scaffold-hero__back">← 返回</a></x-slot:prefix>
            <x-slot:desc>读 scaffold/api/*.yaml 渲染的分层接口文档...</x-slot:desc>
            <x-slot:badges><x-scaffold::badge tone="info">admin</x-scaffold::badge></x-slot:badges>
            <x-slot:meta>
                <span>模块 <strong>14</strong></span>
                <span>接口 <strong>145</strong></span>
            </x-slot:meta>
            <x-slot:actions>
                <x-scaffold::btn variant="primary" size="sm">操作</x-scaffold::btn>
            </x-slot:actions>
        </x-scaffold::hero>

    所有 slot 都是可选,只传 icon + title 也能用。
    prefix slot:用于 title 前插入返回 link / 面包屑等(2026-05-28 加,dictionaries 之前绕组件 inline 自闭)。
--}}
@props([
    'icon' => null,
    'title' => '',
    'compact' => false,        // designer 首屏 / 详情页缩 hero 用(plan-19 v9 F5 意图):减 padding + 无 desc 时一行排
    'card' => false,           // db/index, db/dictionaries 这类卡片化 hero:加 border + bg-gradient + shadow
])

<div {{ $attributes->class(['scaffold-hero', 'scaffold-hero--compact' => $compact, 'scaffold-hero--card' => $card]) }}>
    <div class="scaffold-hero__main">
        @if ($title || isset($badges) || isset($prefix))
            <h2 class="section-title-with-icon">
                {{ $prefix ?? '' }}
                @if ($icon)
                    <span class="section-icon-box" aria-hidden="true">
                        <x-scaffold::icon :name="$icon" :size="16" />
                    </span>
                @endif
                {{ $title }}
                {{ $badges ?? '' }}
            </h2>
        @endif
        @isset($desc)
            <p class="section-desc">{{ $desc }}</p>
        @endisset
    </div>
    @isset($meta)
        <div class="section-meta-inline">{{ $meta }}</div>
    @endisset
    @isset($actions)
        <div class="scaffold-hero__actions">{{ $actions }}</div>
    @endisset
</div>
