@props([
    'title' => null,
    'size' => 'md',          // sm | md | lg
    'role' => 'dialog',      // dialog | alertdialog
    'onClose' => null,       // Alpine 表达式:点 backdrop / 关闭按钮时触发
    'dismissible' => true,
    'tone' => null,          // 2026-05-22 plan-43 Batch D:'danger' = 红顶 + ⚠ 标题前缀(跟 designer danger popover 一脉相承)
])

{{-- 由调用方在外层 x-data 上控制 x-show / x-cloak;本组件只负责结构 + 视觉 --}}
<div {{ $attributes->class(['modal']) }} role="{{ $role }}" aria-modal="true">
    <div class="modal-backdrop" @if($dismissible && $onClose) @click="{{ $onClose }}" @endif></div>
    <div @class([
        'modal-panel',
        'modal-panel--sm' => $size === 'sm',
        'modal-panel--lg' => $size === 'lg',
        'modal-panel--danger' => $tone === 'danger',
    ]) x-transition>
        @if($title || isset($header))
            <header class="modal-header">
                @if(isset($header))
                    {{ $header }}
                @else
                    <h3>{{ $title }}</h3>
                    @if($dismissible && $onClose)
                        <button type="button" class="modal-close" @click="{{ $onClose }}" aria-label="关闭">
                            <x-scaffold::icon name="close" :size="18" />
                        </button>
                    @endif
                @endif
            </header>
        @endif

        <div class="modal-body">{{ $slot }}</div>

        @isset($footer)
            <footer class="modal-footer">{{ $footer }}</footer>
        @endisset
    </div>
</div>
