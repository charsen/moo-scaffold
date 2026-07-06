@props([
    'groups' => [],         // [['key' => '...', 'label' => '...', 'count' => ?, 'icon' => ?, 'items' => [...], 'sub_groups' => [...]], ...]
    'current' => null,      // 当前选中的 item key
    'searchable' => true,
    'collapsedByDefault' => false,
    'header' => null,       // 2026-06-22:可选小节标题(如「模块 · 14」),传值才渲染;对齐 .p-dbdoc-modules__hd
])

{{-- plan-26 T0:CSP-safe(零参 method + data-* attr + imperative DOM update)
     plan-27 T1+T2:加 sub_groups 嵌套(3 层) + 内置 status / deprecated / method / data attr
     plan-32:加 group.icon(渲染在 caret 后) + item.count(渲染在 label 后) — 接口路由 / db / dict 迁过来用 --}}

@php
    // data attr 字符串化(只允许 scalar 值,过滤掉 array/object 防止 XSS)
    $renderDataAttrs = function (array $data) {
        $parts = [];
        foreach ($data as $k => $v) {
            if (is_scalar($v)) {
                $parts[] = 'data-' . e((string) $k) . '="' . e((string) $v) . '"';
            }
        }
        return implode(' ', $parts);
    };
@endphp

<aside
    {{ $attributes->class(['side-tree']) }}
    x-data="sideTree"
    data-collapsed-by-default="{{ $collapsedByDefault ? '1' : '0' }}"
>
    @if($searchable)
        <div class="side-tree__search">
            {{-- 2026-05-23 user 反馈 bug:x-model.debounce 在 scaffold CSP build 下绑不上 input,
                 Alpine.query 一直空。改用 scaffold 通行的 imperative pattern
                 (x-on:input + setter),跟 accounts modal / addField 等保持一致。 --}}
            <input
                class="input input--sm"
                type="search"
                placeholder="搜索..."
                x-on:input.debounce.150ms="setQuery"
                aria-label="过滤"
            >
            {{-- 2026-05-21 A 方案:跟搜索同行 icon-only 折叠 / 展开按钮(节省纵向空间) --}}
            <button type="button" class="side-tree__toolbar-btn" x-on:click="collapseAll" title="全部折叠" aria-label="全部折叠">
                <x-scaffold::icon name="chevron-up" :size="14" />
            </button>
            <button type="button" class="side-tree__toolbar-btn" x-on:click="expandAll" title="全部展开" aria-label="全部展开">
                <x-scaffold::icon name="chevron-down" :size="14" />
            </button>
        </div>
    @endif

    @if($header)
        <div class="side-tree__hd">{{ $header }}</div>
    @endif

    {{-- 递归组渲染:2 层 = group + items;3 层 = group + sub_groups[].items[] --}}
    <ul class="side-tree__list">
        @foreach($groups as $g)
            @php
                $gKey = $g['key'] ?? '';
                $gLabel = $g['label'] ?? '';
                $gCount = $g['count'] ?? null;
                $gIcon = $g['icon'] ?? null;
                $items = $g['items'] ?? [];
                $subGroups = $g['sub_groups'] ?? [];
            @endphp
            <li
                class="side-tree__group"
                data-group-key="{{ $gKey }}"
                data-group-label="{{ mb_strtolower((string) $gLabel) }}"
            >
                <button
                    type="button"
                    class="side-tree__group-head"
                    x-on:click="toggleGroup"
                    aria-expanded="true"
                >
                    <span class="side-tree__caret">
                        <x-scaffold::icon name="chevron-down" :size="12" />
                    </span>
                    @if(filled($gIcon))
                        <span class="side-tree__group-icon" aria-hidden="true">
                            <x-scaffold::icon :name="$gIcon" :size="14" />
                        </span>
                    @endif
                    <span class="side-tree__group-name">{{ $gLabel }}</span>
                    @if($gCount !== null)
                        <span class="side-tree__count">{{ $gCount }}</span>
                    @endif
                </button>

                {{-- 3 层:sub_groups 模式(folder → controller → action) --}}
                @if(! empty($subGroups))
                    <ul class="side-tree__items side-tree__sub-groups">
                        @foreach($subGroups as $sg)
                            @php
                                $sgKey = $sg['key'] ?? '';
                                $sgLabel = $sg['label'] ?? '';
                                $sgCount = $sg['count'] ?? null;
                                $sgItems = $sg['items'] ?? [];
                            @endphp
                            {{-- 2026-05-21 bug fix:sub key 必须加 parent namespace prefix,
                                 否则 parent + sub 同名(Memo 模块 + Memo controller 都叫 Memo)
                                 时 _renderCollapse 把所有 [data-group-key=Memo] 一起 toggle → 点 sub
                                 收的是整模块。 --}}
                            <li
                                class="side-tree__group side-tree__group--sub"
                                data-group-key="{{ $gKey }}::{{ $sgKey }}"
                                data-group-label="{{ mb_strtolower((string) $sgLabel) }}"
                            >
                                <button
                                    type="button"
                                    class="side-tree__group-head"
                                    x-on:click="toggleGroup"
                                    aria-expanded="true"
                                >
                                    <span class="side-tree__caret">
                                        <x-scaffold::icon name="chevron-down" :size="12" />
                                    </span>
                                    <span class="side-tree__group-name">{{ $sgLabel }}</span>
                                    @if($sgCount !== null)
                                        <span class="side-tree__count">{{ $sgCount }}</span>
                                    @endif
                                </button>
                                <ul class="side-tree__items">
                                    @foreach($sgItems as $it)
                                        @php
                                            $itKey = $it['key'] ?? '';
                                            $itLabel = $it['label'] ?? '';
                                            $itHref = $it['href'] ?? '#';
                                            $itMethod = $it['method'] ?? null;
                                            $itCount = $it['count'] ?? null;
                                            $itDeprecated = ! empty($it['deprecated']);
                                            $itData = $it['data'] ?? [];
                                            $itSearch = $it['search'] ?? '';
                                            $itActive = ! empty($it['is_active']) || ($current !== null && $itKey === $current);
                                        @endphp
                                        <li
                                            class="side-tree__item {{ $itActive ? 'is-active' : '' }} {{ $itDeprecated ? 'is-deprecated' : '' }}"
                                            data-item-label="{{ mb_strtolower((string) $itLabel) }}"
                                            @if($itSearch !== '') data-item-search="{{ mb_strtolower((string) $itSearch) }}" @endif
                                        >
                                            <a href="{{ $itHref }}" class="side-tree__item-link" title="{{ $itLabel }}"
                                                {!! is_array($itData) && ! empty($itData) ? $renderDataAttrs($itData) : '' !!}>
                                                @if(($it['index'] ?? null) !== null)
                                                    <span class="side-tree__item-index">{{ $it['index'] }}</span>
                                                @endif
                                                @if(filled($itMethod))
                                                    <span class="side-tree__item-method">
                                                        <x-scaffold::method-badge :method="$itMethod" size="sm" />
                                                    </span>
                                                @endif
                                                <span class="side-tree__item-label">{{ $itLabel }}</span>
                                                @if($itDeprecated)
                                                    <x-scaffold::badge tone="warning" size="sm">弃用</x-scaffold::badge>
                                                @endif
                                                @if($itCount !== null)
                                                    <span class="side-tree__count">{{ $itCount }}</span>
                                                @endif
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </li>
                        @endforeach
                    </ul>
                @else
                    {{-- 2 层:items 模式(acl/index 用) --}}
                    <ul class="side-tree__items">
                        @foreach($items as $it)
                            @php
                                $itKey = $it['key'] ?? '';
                                $itLabel = $it['label'] ?? '';
                                $itHref = $it['href'] ?? '#';
                                $itMethod = $it['method'] ?? null;
                                $itCount = $it['count'] ?? null;
                                $itDeprecated = ! empty($it['deprecated']);
                                $itData = $it['data'] ?? [];
                                $itSearch = $it['search'] ?? '';
                                $itActive = ! empty($it['is_active']) || ($current !== null && $itKey === $current);
                            @endphp
                            <li
                                class="side-tree__item {{ $itActive ? 'is-active' : '' }} {{ $itDeprecated ? 'is-deprecated' : '' }}"
                                data-item-label="{{ mb_strtolower((string) $itLabel) }}"
                                @if($itSearch !== '') data-item-search="{{ mb_strtolower((string) $itSearch) }}" @endif
                            >
                                <a href="{{ $itHref }}" class="side-tree__item-link" title="{{ $itLabel }}"
                                    {!! is_array($itData) && ! empty($itData) ? $renderDataAttrs($itData) : '' !!}>
                                    @if(($it['index'] ?? null) !== null)
                                        <span class="side-tree__item-index">{{ $it['index'] }}</span>
                                    @endif
                                    @if(filled($itMethod))
                                        <span class="side-tree__item-method">
                                            <x-scaffold::method-badge :method="$itMethod" size="sm" />
                                        </span>
                                    @endif
                                    <span class="side-tree__item-label">{{ $itLabel }}</span>
                                    @if($itDeprecated)
                                        <x-scaffold::badge tone="warning" size="sm">弃用</x-scaffold::badge>
                                    @endif
                                    @if($itCount !== null)
                                        <span class="side-tree__count">{{ $itCount }}</span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </li>
        @endforeach
    </ul>

    <div class="side-tree__empty" hidden>无匹配结果</div>
</aside>
