{{-- plan-22 P1-S3:layouts.two_columns 兼容层删,直接用 <x-scaffold::shell> --}}
<x-scaffold::shell title="Scaffold - 配置 - .env 镜像" containerClass="is-route">
<div class="p-config-page">
    @include('scaffold::config._sidebar', ['all_groups' => $all_groups, 'active' => '__env'])
    <section class="p-config-shell">
    {{-- plan-22 P1-S1: 改用统一 <x-scaffold::hero> 组件 --}}
    <x-scaffold::hero icon="setting" title=".env 镜像">
        <x-slot:badges>
            <x-scaffold::badge tone="warning">永远只读</x-scaffold::badge>
        </x-slot:badges>
        <x-slot:desc>
            展示当前 <code>.env</code> 全量内容（敏感字段自动掩码）。<br>
            修改 env 必须通过 SSH 人工编辑后重启进程，UI 不写入。
        </x-slot:desc>
        <x-slot:meta>
            <span>条目 <strong>{{ count($rows) }}</strong></span>
            <span>敏感字段（掩码） <strong>{{ collect($rows)->where('sensitive', true)->count() }}</strong></span>
            <a href="{{ route('scaffold.config') }}" class="p-config-back">← 返回概览</a>
        </x-slot:meta>
    </x-scaffold::hero>

    @if (empty($rows))
        <x-scaffold::empty title=".env 文件不存在或为空" desc="检查 .env 是否就位。" />
    @else
        <x-scaffold::table compact striped class="p-config-table">
            <thead>
                <tr>
                    <th style="width:280px;">Key</th>
                    <th>Value</th>
                    <th style="width:90px;">类型</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td><code>{{ $row['key'] }}</code></td>
                    <td>
                        @if ($row['value'] === '')
                            <em class="p-config-null">（空）</em>
                        @else
                            <code class="{{ $row['sensitive'] ? 'p-config-mask' : '' }}">{{ $row['value'] }}</code>
                        @endif
                    </td>
                    <td>
                        @if ($row['sensitive'])
                            <x-scaffold::badge tone="warning" size="sm">敏感</x-scaffold::badge>
                        @else
                            <span class="small text-muted">—</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </x-scaffold::table>
    @endif
    </section>
</div>
</x-scaffold::shell>

{{-- plan-22 T8: inline <style> 已外迁到 public/sass/7-pages/_config.scss --}}
