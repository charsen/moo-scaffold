{{-- plan-22 P1-S3:layouts.two_columns 兼容层删,直接用 <x-scaffold::shell>
     复用 route 同款"sticky sidebar + 长滚动主区"布局:.p-route-shell + .container.pl0.is-route --}}
<x-scaffold::shell title="Scaffold - 数据字典" containerClass="is-route">

{{-- 2026-05-24:sub-nav 整条砍 — 返回 link 进 hero 卡片左侧跟标题同行,stats 留 hero 右侧 --}}

<div class="p-route-shell">
    @if (! empty($module_summaries))
        @php
            // plan-32:dict sidebar 迁 side-tree(单层 module 锚点,同 db/index 模式)
            // plan-53:扩展包模块带 📦 icon 区分出身
            $dictGroups = [];
            foreach ($module_summaries as $moduleKey => $summary) {
                $dictGroups[] = [
                    'key' => $moduleKey,
                    'label' => $summary['name'],
                    'icon' => ($summary['origin'] ?? null) !== null ? 'package' : null,
                    'count' => $summary['table_count'],
                    'items' => [],
                ];
            }
        @endphp
        <x-scaffold::side-tree
            id="dict_sidebar"
            :groups="$dictGroups"
            :header="'模块 · ' . count($dictGroups)"
            :searchable="false"
            :collapsedByDefault="false"
        />
        {{-- 2026-07-11:数据字典 sidebar 拖拽把手（JS 贴 #dict_sidebar 右沿；共用 --scaffold-nav-width，与各导航栏一起变）--}}
        <div class="side-resizer" role="separator" aria-orientation="vertical"
             title="拖动调整侧栏宽度（双击复位）"
             data-resize-target="dict_sidebar"
             data-resize-var="--scaffold-nav-width"
             data-resize-key="scaffold_nav_width"
             data-resize-min="220" data-resize-max="520" data-resize-default="260"></div>
    @endif

    <div class="route-main">
        <div class="p-dict">
            {{-- 2026-05-28 phase C-6:hero 组件加 prefix slot 后,本页回归组件调用 --}}
            <x-scaffold::hero icon="wordbook" title="数据字典" card>
                <x-slot:prefix>
                    <a href="{{ route('db.docs') }}"
                       class="scaffold-hero__back"
                       title="返回数据库文档">
                        <x-scaffold::icon name="chevron-left" :size="14" />
                        <span>返回</span>
                    </a>
                </x-slot:prefix>
                <x-slot:desc>所有模块的枚举值说明。左侧点击模块快速定位。</x-slot:desc>
                <x-slot:meta>
                    <span><strong>{{ number_format($stats['table_count'] ?? 0) }}</strong> 数据表</span>
                    <span><strong>{{ number_format($stats['field_count'] ?? 0) }}</strong> 字典字段</span>
                    <span><strong>{{ number_format($stats['value_count'] ?? 0) }}</strong> 字典值</span>
                </x-slot:meta>
            </x-scaffold::hero>

            @if (empty($data))
                <div class="p-dict__empty">
                    <x-scaffold::empty
                        title="没有数据字典"
                        desc="当前应用下还没有枚举字段，或者字典还没有生成。"
                    >
                        <x-slot:icon><x-scaffold::icon name="wordbook" :size="24" /></x-slot:icon>
                    </x-scaffold::empty>
                </div>
            @else
                @foreach ($data as $moduleKey => $tables)
                    <section class="p-dict__module" id="dict-module-{{ $moduleKey }}" data-module="{{ $moduleKey }}">
                        <div class="p-dict__module-head">
                            <h3>
                                <span class="p-dict__module-icon" aria-hidden="true">
                                    <x-scaffold::icon name="database" :size="16" />
                                </span>
                                {{ $module_summaries[$moduleKey]['name'] }}
                                @if ($module_summaries[$moduleKey]['origin'] ?? null)
                                    <x-scaffold::badge tone="info" size="sm" title="扩展包模块,schema 在包仓">📦 {{ $module_summaries[$moduleKey]['origin'] }}</x-scaffold::badge>
                                @endif
                            </h3>
                            <div class="p-dict__module-meta">
                                {{ $module_summaries[$moduleKey]['table_count'] }} 表 · {{ $module_summaries[$moduleKey]['field_count'] }} 字段 · {{ $module_summaries[$moduleKey]['value_count'] }} 值
                            </div>
                        </div>

                        <x-scaffold::table compact class="p-dict__table">
                            <thead>
                                <tr>
                                    <th class="p-dict__col-field">字段</th>
                                    <th class="p-dict__col-value">值</th>
                                    <th class="p-dict__col-en">英文</th>
                                    <th class="p-dict__col-zh">中文</th>
                                    <th class="p-dict__col-desc">说明</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($tables as $table => $dictionaries)
                                    {{-- 每张表前插一行分组标题（参考 route-group-row 样式） --}}
                                    <tr class="p-dict__group-row">
                                        <td colspan="5">
                                            <strong>{{ $menus[$moduleKey]['tables'][$table]['name'] ?? $table }}</strong>
                                            <span class="p-dict__group-key">{{ $table }}</span>
                                        </td>
                                    </tr>
                                    @foreach ($dictionaries as $field => $rows)
                                        @foreach ($rows as $v)
                                            <tr>
                                                @if ($loop->first)
                                                    <td rowspan="{{ count($rows) }}" class="p-dict__cell-field">{{ $field }}</td>
                                                @endif
                                                <td class="p-dict__cell-value">{{ $v[0] }}</td>
                                                <td class="p-dict__cell-en">{{ $v[1] }}</td>
                                                <td class="p-dict__cell-zh">{{ $v[2] }}</td>
                                                <td class="p-dict__cell-desc">{{ $v[3] ?? '' }}</td>
                                            </tr>
                                        @endforeach
                                    @endforeach
                                @endforeach
                            </tbody>
                        </x-scaffold::table>
                    </section>
                @endforeach
            @endif
        </div>
    </div>
</div>

<x-slot:scripts>
<script nonce="{{ $cspNonce ?? "" }}">
$(function () {
    var $sidebar = $('#dict_sidebar');
    if (!$sidebar.length) return;

    var $scrollEl = $('html, body');
    var headerOffset = (parseInt(getComputedStyle(document.documentElement).getPropertyValue('--shell-header-height')) || 64)
                    + (parseInt(getComputedStyle(document.documentElement).getPropertyValue('--shell-content-top')) || 24);

    // plan-32:点 group head → scroll(side-tree items 为空,toggle 无效果)
    $sidebar.on('click', '.side-tree__group-head', function (e) {
        var $group = $(this).closest('.side-tree__group');
        var moduleKey = $group.attr('data-group-key');
        if (!moduleKey) return;
        e.preventDefault();
        var $target = $('#dict-module-' + moduleKey);
        if ($target.length) {
            var top = $target.offset().top - headerOffset - 10;
            $scrollEl.animate({ scrollTop: top }, 300);
        }
    });

    // plan-22 T7 / plan-32:scroll spy 加 rAF 节流 + offset 缓存,染 side-tree group
    var $groups = $sidebar.find('.side-tree__group');
    var $modules = $('.p-dict__module');
    if ($groups.length && $modules.length) {
        var cachedOffsets = null;
        var ticking = false;
        var lastActive = null;

        function buildOffsets() {
            cachedOffsets = [];
            $modules.each(function () {
                cachedOffsets.push({ key: $(this).data('module'), top: $(this).offset().top });
            });
        }

        function updateActive() {
            ticking = false;
            if (!cachedOffsets) buildOffsets();
            var scrollTop = $(window).scrollTop();
            var current = null;
            for (var i = 0; i < cachedOffsets.length; i++) {
                if (scrollTop >= cachedOffsets[i].top - headerOffset - 20) {
                    current = cachedOffsets[i].key;
                } else {
                    break;   // modules 已按 DOM 顺序排,后面的 top 更大,提前 break
                }
            }
            if (current && current !== lastActive) {
                $groups.removeClass('is-active');
                $groups.filter('[data-group-key="' + current + '"]').addClass('is-active');
                lastActive = current;
            }
        }

        $(window).on('scroll', function () {
            if (!ticking) {
                ticking = true;
                window.requestAnimationFrame(updateActive);
            }
        });

        // resize / 字体加载完 → 缓存失效
        $(window).on('resize load', function () { cachedOffsets = null; });
    }
});
</script>
</x-slot:scripts>
</x-scaffold::shell>
