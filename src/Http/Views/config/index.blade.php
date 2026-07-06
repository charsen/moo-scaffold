{{-- plan-22 P1-S3:layouts.two_columns 兼容层删,直接用 <x-scaffold::shell> --}}
<x-scaffold::shell title="Scaffold - 配置" containerClass="is-route">
@php
    $sourceTone = [
        'env'  => 'info',
        'file' => 'neutral',
    ];

    /** 只读标量值渲染 */
    $renderValue = static function ($value, string $type, bool $sensitive) {
        if ($value === null) return '<em class="p-config-null">null</em>';
        if ($type === 'bool') return $value ? '<code class="p-config-true">true</code>' : '<code class="p-config-false">false</code>';
        if ($type === 'list' && is_array($value)) {
            if ($value === []) return '<em class="p-config-null">[]</em>';
            return '<code>'.e(implode(', ', array_map('strval', $value))).'</code>';
        }
        if ($type === 'map' && is_array($value)) {
            if ($value === []) return '<em class="p-config-null">{}</em>';
            $rows = [];
            foreach ($value as $k => $v) $rows[] = '<code>'.e((string) $k).'</code> = <code>'.e((string) $v).'</code>';
            return '<div class="p-config-map">'.implode('<br>', $rows).'</div>';
        }
        if (is_array($value)) return '<code>'.e(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)).'</code>';
        return '<code>'.e((string) $value).'</code>';
    };

    $editable = ! $readonly && ! $is_prod;
    $totalEnv = array_sum(array_column($summary, 'env_count'));
    $totalFields = array_sum(array_column($summary, 'field_count'));
@endphp

{{-- C 方案(2026-05-18):layouts.app → two_columns,sidebar+right 合到单 content,
     根除右栏 panel 嵌套(同 runtime/route 套路) --}}
<div class="p-config-page">
    @include('scaffold::config._sidebar', ['all_groups' => $summary, 'active' => $active])
    <section class="p-config-shell" @if ($readonly || $is_prod) data-locked="true" @endif>
    {{-- plan-22 P1-S1: 改用统一 <x-scaffold::hero> 组件 --}}
    <x-scaffold::hero icon="settings" title="Scaffold 配置">
        <x-slot:badges>
            @if ($is_prod)
                <x-scaffold::badge tone="danger" solid>生产 · 只读</x-scaffold::badge>
            @elseif ($readonly)
                <x-scaffold::badge tone="warning">强制只读</x-scaffold::badge>
            @else
                <x-scaffold::badge tone="success">可编辑</x-scaffold::badge>
            @endif
        </x-slot:badges>
        <x-slot:desc>
            可视化编辑 <code>engine/config/scaffold.php</code> 与 <code>.env</code>。<br>
            file 字段直接改源文件；env 字段改 <code>.env</code>（写完自动清 config cache）。
        </x-slot:desc>
        <x-slot:meta>
            <span>分组 <strong>{{ count($summary) }}</strong></span>
            <span>字段 <strong>{{ $totalFields }}</strong></span>
            <span>来自 env <strong>{{ $totalEnv }}</strong></span>
        </x-slot:meta>
    </x-scaffold::hero>

    {{-- 2026-05-28 phase C-1:sticky lock banner 改用 designer 同款,砍橙底 .p-config-warning(ship 清单 #11 收口) --}}
    @if ($readonly || $is_prod)
        <div class="p-designer-locked-banner" role="status" aria-live="polite">
            <x-scaffold::icon name="warn" :size="14" />
            <strong>{{ $is_prod ? '生产环境' : '只读模式' }}</strong>
            <span>所有配置只读 — 任何修改需 SSH 编辑 <code>engine/config/scaffold.php</code> / <code>.env</code> 后重启。</span>
            <span class="p-designer-locked-banner__hint">{{ $is_prod ? 'APP_ENV=production' : 'SCAFFOLD_CONFIG_READONLY=true' }}</span>
        </div>
    @endif

    <div class="p-config-sections"
         x-data="configToc"
         data-flash-group="{{ $flash_group ?? '' }}">

        @foreach ($groups as $key => $group)
            <section class="p-config-section" data-group-section="{{ $key }}" id="group-{{ $key }}">
                <header class="p-config-section-header">
                    <h3>{{ $group['label'] }}</h3>
                    <span class="p-config-section-desc">{{ $group['desc'] }}</span>
                </header>

                @if (! empty($flash_group) && $flash_group === $key)
                    @if (! empty($flash_message))
                        <div class="p-config-flash p-config-flash--ok">{{ $flash_message }}</div>
                    @endif
                    @if (! empty($flash_error))
                        <div class="p-config-flash p-config-flash--err">{{ $flash_error }}</div>
                    @endif
                    @if (! empty($flash_diff))
                        <div class="p-config-flash p-config-flash--diff">
                            <strong>变更明细：</strong>
                            <ul>
                                @foreach ($flash_diff as $p => $pair)
                                    <li><code>{{ $p }}</code>: <code>{{ is_bool($pair[0]) ? ($pair[0] ? 'true' : 'false') : (is_array($pair[0]) ? json_encode($pair[0], JSON_UNESCAPED_UNICODE) : (string) $pair[0]) }}</code> → <code>{{ is_bool($pair[1]) ? ($pair[1] ? 'true' : 'false') : (is_array($pair[1]) ? json_encode($pair[1], JSON_UNESCAPED_UNICODE) : (string) $pair[1]) }}</code></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    @if (! empty($flash_skipped))
                        <div class="p-config-flash p-config-flash--warn">
                            <strong>跳过：</strong>
                            <ul>
                                @foreach ($flash_skipped as $p => $reason)
                                    <li><code>{{ $p }}</code> — {{ $reason }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @endif

                <form method="POST" action="{{ route('scaffold.config.update', ['group' => $key]) }}" class="p-config-form">
                    @csrf

                    <x-scaffold::table compact striped class="p-config-table">
                        <thead>
                            <tr>
                                <th style="width:280px;">字段</th>
                                <th>{{ $editable ? '当前值 / 新值' : '当前值' }}</th>
                                <th style="width:110px;">来源</th>
                                <th style="width:140px;">默认</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach ($group['fields'] as $f)
                            @php
                                $fieldEditable = $editable && in_array($f['type'], ['string', 'int', 'bool', 'text', 'list', 'map'], true);
                                $inputName = 'fields['.$f['path'].']';
                            @endphp
                            <tr>
                                <td>
                                    <div class="p-config-field-label">
                                        {{ $f['label'] }}
                                        @if ($f['sensitive']) <span class="p-config-mask-hint" title="敏感字段">🛡</span> @endif
                                    </div>
                                    <div class="p-config-field-path"><code>{{ $f['path'] }}</code></div>
                                    @if (! empty($f['desc']))
                                        <div class="p-config-field-desc">{{ $f['desc'] }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if (! $fieldEditable)
                                        {!! $renderValue($f['value'], $f['type'], $f['sensitive']) !!}
                                    @else
                                        @switch($f['type'])
                                            @case('bool')
                                                <label class="p-config-bool">
                                                    {{-- hidden 兜底：未勾选时 checkbox 不发字段，靠 hidden 送 0 --}}
                                                    <input type="hidden" name="{{ $inputName }}" value="0">
                                                    <input type="checkbox" name="{{ $inputName }}" value="1" {{ $f['raw_value'] ? 'checked' : '' }}
                                                        aria-label="{{ $f['label'] }}({{ $f['path'] }})">
                                                    <span>启用</span>
                                                </label>
                                                @break
                                            @case('int')
                                                <input type="number" name="{{ $inputName }}" value="{{ $f['raw_value'] }}" class="p-config-input p-config-input--narrow"
                                                    aria-label="{{ $f['label'] }}({{ $f['path'] }})">
                                                @break
                                            @case('list')
                                                <input type="text" name="{{ $inputName }}" value="{{ is_array($f['raw_value']) ? implode(',', $f['raw_value']) : $f['raw_value'] }}" class="p-config-input" placeholder="逗号分隔"
                                                    aria-label="{{ $f['label'] }}({{ $f['path'] }},逗号分隔)">
                                                @break
                                            @case('text')
                                                <textarea name="{{ $inputName }}" rows="3" class="p-config-input"
                                                    aria-label="{{ $f['label'] }}({{ $f['path'] }})">{{ $f['raw_value'] }}</textarea>
                                                @break
                                            @case('map')
                                                {{-- 服务端直接渲染初始行；Alpine 仅用于 add/remove 按钮。
                                                     CSP build 对 <template x-for> 内动态 :name 绑定不稳定，所以走"纯 DOM 操作"路线 --}}
                                                <div class="p-config-map-editor"
                                                     data-scaffold-map
                                                     data-map-path="{{ $f['path'] }}"
                                                     data-map-seq="{{ is_array($f['raw_value']) ? count($f['raw_value']) : 0 }}"
                                                     data-map-validator="{{ $f['value_validator'] ?? '' }}"
                                                     data-map-field-label="{{ $f['label'] }}">
                                                    <div class="p-config-map-rows" data-map-rows>
                                                        @if (is_array($f['raw_value']))
                                                            @foreach ($f['raw_value'] as $mk => $mv)
                                                                @php $rid = 'r'.$loop->index; @endphp
                                                                @php $isUrl = ($f['value_validator'] ?? null) === 'url'; @endphp
                                                                <div class="p-config-map-row" data-map-row>
                                                                    <input type="text" class="p-config-input p-config-input--map-k"
                                                                           name="fields[{{ $f['path'] }}][{{ $rid }}][k]"
                                                                           value="{{ $mk }}" placeholder="名称"
                                                                           aria-label="{{ $f['label'] }} 第 {{ $loop->iteration }} 行 名称">
                                                                    <input type="{{ $isUrl ? 'url' : 'text' }}" class="p-config-input p-config-input--map-v"
                                                                           name="fields[{{ $f['path'] }}][{{ $rid }}][v]"
                                                                           value="{{ $mv }}" placeholder="{{ $isUrl ? 'http(s)://...' : 'URL / 值' }}"
                                                                           aria-label="{{ $f['label'] }} 第 {{ $loop->iteration }} 行 值">
                                                                    <x-scaffold::btn variant="ghost" size="sm" data-map-remove aria-label="删除第 {{ $loop->iteration }} 行">删</x-scaffold::btn>
                                                                </div>
                                                            @endforeach
                                                        @endif
                                                    </div>
                                                    <x-scaffold::btn variant="ghost" size="sm" class="p-config-map-add" data-map-add>+ 添加</x-scaffold::btn>
                                                    {{-- 空 map 兜底：行全删光时让服务端知道"用户操作过但留空"--}}
                                                    <input type="hidden" name="fields[{{ $f['path'] }}][__present]" value="1">
                                                </div>
                                                @break
                                            @default
                                                <input type="text" name="{{ $inputName }}" value="{{ $f['raw_value'] }}" class="p-config-input"
                                                    aria-label="{{ $f['label'] }}({{ $f['path'] }})">
                                        @endswitch
                                    @endif
                                </td>
                                <td>
                                    <x-scaffold::badge :tone="$sourceTone[$f['source']]" size="sm">{{ $f['source'] }}</x-scaffold::badge>
                                    @if ($f['source'] === 'env' && $f['env_key'])
                                        <div class="p-config-env-key"><code>{{ $f['env_key'] }}</code></div>
                                    @endif
                                </td>
                                <td class="small text-muted">
                                    @if ($f['default'] === null)
                                        <em>—</em>
                                    @elseif (is_bool($f['default']))
                                        <code>{{ $f['default'] ? 'true' : 'false' }}</code>
                                    @elseif (is_array($f['default']))
                                        <code>{{ json_encode($f['default'], JSON_UNESCAPED_UNICODE) }}</code>
                                    @else
                                        <code>{{ (string) $f['default'] }}</code>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </x-scaffold::table>

                    @if ($editable)
                        <div class="p-config-form-actions">
                            <x-scaffold::btn type="submit" variant="primary" size="sm">保存 {{ $group['label'] }}</x-scaffold::btn>
                            <span class="p-config-form-hint">file 字段直接写 <code>config/scaffold.php</code>；env 字段写 <code>.env</code>。</span>
                        </div>
                    @endif
                </form>
            </section>
        @endforeach
    </div>
    </section>
</div>
</x-scaffold::shell>

{{-- plan-22 T8: inline <style> 已外迁到 public/sass/7-pages/_config.scss --}}
