@php
    // 2026-06-20:数据库文档(只读)—— 3 栏 doc 视图。模块(aside)→ 表(middle)→ 表详情(right)。
    // 数据全来自 SchemaLoader(yaml 源,跟 designer 同一份);服务端渲染,?schema/?table 选中。
    // 2026-06-22:模块标签统一用英文(对齐数据字典/数据库设计),不再取中文 module.name。表级名称仍中文。
    // 用 module.folder(如 CRM)而非 listModules 的数组 key $current_schema —— 后者是文件名规整值(CRM.yaml→Crm),
    // 大小写会跟 folder 漂移;数据字典/designer 显示的都是 folder。
    $currentModuleName = $current_schema ? ($modules[$current_schema]['folder'] ?? $current_schema) : '';
    // 中文模块名:只在中栏头部当副标(英文为主 + 中文副);aside 紧凑列表仍纯英文,对齐数据字典
    $moduleCn = $current_schema ? (string) ($modules[$current_schema]['name'] ?? '') : '';
    $shellTitle = 'Scaffold - 数据库文档' . ($currentModuleName !== '' ? ' · ' . $currentModuleName : '');

    // 字段类型展示:type + (size)(size 可能是 'min,max' 紧凑写法,原样显示)
    $fmtType = function (array $f): string {
        $t = (string) ($f['type'] ?? '-');
        $size = $f['size'] ?? null;
        return ($size !== null && $size !== '') ? $t . '(' . $size . ')' : $t;
    };
@endphp

<x-scaffold::shell :title="$shellTitle" containerClass="is-dbdoc">

@if (empty($modules))
    <x-slot:right>
        <x-scaffold::empty title="还没有数据库设计"
            desc="scaffold/database 下还没有 schema。去「数据库设计」新建模块，或写好 yaml 后这里会自动出现。">
            <x-slot:icon><x-scaffold::icon name="database" :size="24" /></x-slot:icon>
        </x-scaffold::empty>
    </x-slot:right>
@else
    {{-- 左:模块列表(plan-53:按出身分块 — host 一块 + 各扩展包一块;仅 host 时不渲染块标题,视觉不变) --}}
    @php
        $originGroups = [];
        foreach ($modules as $sk => $m) {
            $originGroups[$m['origin'] ?? ''][$sk] = $m;
        }
        ksort($originGroups);   // '' (host) 排最前,包按 key 升序
    @endphp
    <x-slot:aside>
        <div class="p-dbdoc-modules">
            <div class="p-dbdoc-modules__hd">模块 · {{ count($modules) }}</div>
            @foreach ($originGroups as $gOrigin => $gModules)
                @if (count($originGroups) > 1 && $gOrigin !== '')
                    <div class="p-dbdoc-modules__group">
                        <x-scaffold::icon name="package" :size="12" />
                        {{ $gOrigin }}
                    </div>
                @endif
                @foreach ($gModules as $sk => $m)
                    <a href="{{ route('db.docs', ['schema' => $sk]) }}"
                       class="p-dbdoc-module {{ $sk === $current_schema ? 'is-active' : '' }}">
                        <span class="p-dbdoc-module__name">{{ $m['folder'] ?? $sk }}</span>
                        <span class="p-dbdoc-module__count">{{ $m['tables_count'] }}</span>
                    </a>
                @endforeach
            @endforeach

            {{-- 2026-06-22:数据字典(全模块枚举速查)入口 —— 它是「看」,从 designer 落地页移到这个只读 hub。
                            跟模块项同栏但加分隔线 + wordbook 图标,区分「这是跨模块工具」而非某个模块 --}}
            <a href="{{ route('dictionaries') }}"
               class="p-dbdoc-module p-dbdoc-module--dict"
               title="所有模块的枚举字段字典值索引 · 按模块分组">
                <span class="p-dbdoc-module__name">
                    <x-scaffold::icon name="wordbook" :size="14" />
                    数据字典
                </span>
                <x-scaffold::icon name="chevron-right" :size="14" />
            </a>
        </div>
    </x-slot:aside>

    {{-- 中:当前模块的表列表 --}}
    <x-slot:middle>
        <div class="p-dbdoc-tables">
            <div class="p-dbdoc-tables__hd">
                <h3 class="p-dbdoc-tables__title">{{ $currentModuleName }}@if ($moduleCn !== '' && $moduleCn !== $currentModuleName)<span class="p-dbdoc-tables__cn">{{ $moduleCn }}</span>@endif</h3>
                <span class="p-dbdoc-tables__meta">{{ count($tables) }} 张表</span>
            </div>
            @if (empty($tables))
                <x-scaffold::empty title="该模块暂无数据表" />
            @else
                <x-scaffold::table class="p-dbdoc-tables__table">
                    <thead>
                        <tr><th class="p-dbdoc-tables__idx">#</th><th>表名</th><th>名称</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($tables as $tk => $t)
                            <tr class="p-dbdoc-trow {{ $tk === $current_table ? 'is-active' : '' }}">
                                <td class="p-dbdoc-tables__idx">{{ $loop->iteration }}</td>
                                <td>
                                    <a href="{{ route('db.docs', ['schema' => $current_schema, 'table' => $tk]) }}"
                                       class="p-dbdoc-tlink"><code>{{ $tk }}</code></a>
                                </td>
                                <td>{{ $t['name'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-scaffold::table>
            @endif
        </div>
    </x-slot:middle>

    {{-- 右:表详情 doc --}}
    <x-slot:right>
        @if (! $detail)
            <x-scaffold::empty title="请选择数据表" desc="点左侧表名，这里展示它的字段、索引与枚举。">
                <x-slot:icon><x-scaffold::icon name="database" :size="24" /></x-slot:icon>
            </x-scaffold::empty>
        @else
            <div class="p-dbdoc-detail">
                <div class="p-dbdoc-detail__head">
                    <h2 class="p-dbdoc-detail__title">{{ $detail['name'] }}</h2>
                    <code class="p-dbdoc-detail__key">{{ $detail['key'] }}</code>
                    @if (! empty($detail['locked']))
                        <x-scaffold::badge tone="neutral" size="sm">已生成 migration</x-scaffold::badge>
                    @endif
                </div>

                @php
                    // remark 按 schema 约定是「多行备注」list(docs/schema_demo.yaml + FreshStorageGenerator 默认 []);取非空行
                    $remarkLines = array_values(array_filter(
                        array_map('trim', (array) ($detail['remark'] ?? [])),
                        fn ($l) => $l !== ''
                    ));
                @endphp
                @if (! empty($detail['desc']))
                    <p class="p-dbdoc-detail__desc">{{ $detail['desc'] }}</p>
                @endif
                @if (! empty($remarkLines))
                    <p class="p-dbdoc-detail__remark">{!! implode('<br>', array_map('e', $remarkLines)) !!}</p>
                @endif

                {{-- 索引 --}}
                @if (! empty($detail['index']))
                    <section class="p-dbdoc-section">
                        <h3 class="p-dbdoc-section__title"><x-scaffold::icon name="key" :size="14" /> 索引</h3>
                        <x-scaffold::table>
                            <thead>
                                <tr><th>索引名</th><th>字段</th><th>类型</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($detail['index'] as $idxName => $idx)
                                    <tr>
                                        <td><code>{{ $idxName }}</code></td>
                                        <td><code>{{ is_array($idx['fields'] ?? null) ? implode(', ', $idx['fields']) : ($idx['fields'] ?? '') }}</code></td>
                                        <td>{{ $idx['type'] ?? '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </x-scaffold::table>
                    </section>
                @endif

                {{-- 字段 --}}
                <section class="p-dbdoc-section">
                    <h3 class="p-dbdoc-section__title">
                        <x-scaffold::icon name="code" :size="14" /> 字段
                        <span class="p-dbdoc-section__count">{{ count($detail['fields']) }}</span>
                    </h3>
                    <x-scaffold::table class="p-dbdoc-fields">
                        <thead>
                            <tr><th>字段</th><th>名称</th><th>类型</th><th>允许空</th><th>默认值</th><th>说明</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($detail['fields'] as $f)
                                <tr>
                                    <td class="p-dbdoc-fld">
                                        @if (empty($f['nullable']))<em class="p-dbdoc-req" title="非空 / 必填">*</em>@endif<code>{{ $f['key'] }}</code>
                                    </td>
                                    <td>{{ $f['name'] ?? '' }}</td>
                                    <td class="p-dbdoc-type">
                                        <code>{{ $fmtType($f) }}</code>@if (! empty($f['unsigned']))<span class="p-dbdoc-tag">unsigned</span>@endif
                                    </td>
                                    <td>
                                        @if (! empty($f['nullable']))
                                            <span class="p-dbdoc-yes">yes</span>
                                        @else
                                            <span class="p-dbdoc-no">no</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($f['default'] !== null && $f['default'] !== '')
                                            <code>{{ $f['default'] }}</code>
                                        @else
                                            <span class="p-dbdoc-muted">—</span>
                                        @endif
                                    </td>
                                    <td>{{ $f['comment'] ?? '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-scaffold::table>
                </section>

                {{-- 枚举 --}}
                @if (! empty($detail['enums']))
                    <section class="p-dbdoc-section">
                        <h3 class="p-dbdoc-section__title"><x-scaffold::icon name="list" :size="14" /> 枚举</h3>
                        @foreach ($detail['enums'] as $field => $rows)
                            <div class="p-dbdoc-enum">
                                <div class="p-dbdoc-enum__field"><code>{{ $field }}</code></div>
                                <x-scaffold::table>
                                    <thead>
                                        <tr><th>key</th><th>值</th><th>英文</th><th>中文</th></tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($rows as $r)
                                            <tr>
                                                <td><code>{{ $r['key'] }}</code></td>
                                                <td><code>{{ $r['value'] }}</code></td>
                                                <td>{{ $r['label_en'] }}</td>
                                                <td>{{ $r['label_zh'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </x-scaffold::table>
                            </div>
                        @endforeach
                    </section>
                @endif

                {{-- meta --}}
                <div class="p-dbdoc-detail__meta">
                    @if (! empty($detail['model']['class']))<span>Model <code>{{ $detail['model']['class'] }}</code></span>@endif
                    @if (! empty($detail['created_by']))<span>创建 {{ $detail['created_by'] }} · {{ $detail['created_at'] }}</span>@endif
                    @if (! empty($detail['updated_by']))<span>改 {{ $detail['updated_by'] }} · {{ $detail['updated_at'] }}</span>@endif
                </div>
            </div>
        @endif
    </x-slot:right>
@endif

</x-scaffold::shell>
