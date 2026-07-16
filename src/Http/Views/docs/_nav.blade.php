{{-- plan-52:文档侧导航(阅读页 + 编辑器共用)。$tree(navTree 全源聚合) / $current_key / $locked
     plan-53:一棵树同屏分块 —— host 分组原层级,包源各占一个 📦 顶层组(sub_groups);无切换器 --}}
<div class="p-docs-nav">
    <div class="p-docs-nav__hd">
        {{-- 标题即目录主页入口(裸 /docs):全源总览 + 拖拽排序 --}}
        <a href="{{ route('docs.index') }}" class="p-docs-nav__hd-title" title="文档目录 · 拖拽排序">文档 · {{ array_sum(array_column($tree, 'count')) }}</a>
        @unless($locked ?? false)
            <a href="{{ route('docs.edit', ($src ?? null) ? ['src' => $src] : []) }}" class="p-docs-nav__new" title="新建文档">
                <x-scaffold::icon name="plus" :size="14" /> 新建
            </a>
        @endunless
    </div>

    @if (empty($tree))
        <p class="p-docs-nav__empty">还没有文档</p>
    @else
        <x-scaffold::side-tree :groups="$tree" :current="$current_key ?? null" :searchable="true" />
    @endif
</div>
