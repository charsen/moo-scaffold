<x-scaffold::shell title="Scaffold - AI 配置" containerClass="is-route">
@php
    $editable = ! $readonly && ! $is_prod;
@endphp

<div class="p-config-page">
    @include('scaffold::config._sidebar', ['all_groups' => $all_groups, 'active' => '__ai'])
    <section class="p-config-shell" @if ($readonly || $is_prod) data-locked="true" @endif>
    <x-scaffold::hero icon="settings" title="AI 配置">
        <x-slot:badges>
            @if ($is_prod)
                <x-scaffold::badge tone="danger" solid>生产 · 只读</x-scaffold::badge>
            @elseif ($readonly)
                <x-scaffold::badge tone="warning">强制只读</x-scaffold::badge>
            @elseif ($settings['api_key_set'])
                <x-scaffold::badge tone="success">已配置</x-scaffold::badge>
            @else
                <x-scaffold::badge tone="neutral">未配置</x-scaffold::badge>
            @endif
        </x-slot:badges>
        <x-slot:desc>
            数据库设计器的中文 → snake_case 翻译走 DeepSeek（或 OpenAI 兼容上游）。<br>
            配置存 <code>{{ $yaml_path }}</code>（入 git 随仓同步，改完即时生效、无需重启）。
        </x-slot:desc>
    </x-scaffold::hero>

    {{-- 生产 / 只读锁条（跟 designer / config 一致，ship 清单 #11） --}}
    @if ($readonly || $is_prod)
        <div class="p-designer-locked-banner" role="status" aria-live="polite">
            <x-scaffold::icon name="warn" :size="14" />
            <strong>{{ $is_prod ? '生产环境' : '只读模式' }}</strong>
            <span>AI 配置只读 — 修改需在本地开发环境。</span>
            <span class="p-designer-locked-banner__hint">{{ $is_prod ? 'APP_ENV=production' : 'SCAFFOLD_CONFIG_READONLY=true' }}</span>
        </div>
    @endif

    {{-- 坏 yaml 提示:解析失败已回退默认值(git 冲突标记/手改坏形),页面保持可用 --}}
    @if (! empty($settings['yaml_broken']))
        <div class="p-designer-locked-banner" role="alert">
            <x-scaffold::icon name="warn" :size="14" />
            <strong>ai.yaml 解析失败</strong>
            <span>当前展示默认值。请修复 <code>{{ $yaml_path }}</code>（git 冲突标记是常见原因），或直接在下方保存一次以合法内容重写该文件。</span>
        </div>
    @endif

    @if (! empty($flash_message))
        <div class="p-config-flash p-config-flash--ok">{{ $flash_message }}</div>
    @endif
    @if (! empty($flash_error))
        <div class="p-config-flash p-config-flash--err">{{ $flash_error }}</div>
    @endif

    <div class="p-config-sections">
        <section class="p-config-section">
            <header class="p-config-section-header">
                <h3>DeepSeek 翻译</h3>
                <span class="p-config-section-desc">字段名 / 枚举键 / 表名简写的 AI 翻译上游</span>
            </header>

            <form method="POST" action="{{ route('scaffold.config.ai.update') }}" class="p-config-form">
                @csrf
                <x-scaffold::table compact striped class="p-config-table">
                    <thead>
                        <tr>
                            <th style="width:280px;">字段</th>
                            <th>{{ $editable ? '当前值 / 新值' : '当前值' }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <div class="p-config-field-label">API Base URL</div>
                                <div class="p-config-field-path"><code>base_url</code></div>
                                <div class="p-config-field-desc">OpenAI 兼容上游，默认 DeepSeek</div>
                            </td>
                            <td>
                                @if ($editable)
                                    <input type="text" name="base_url" value="{{ $settings['base_url'] }}"
                                        class="p-config-input" placeholder="{{ $settings['defaults']['base_url'] }}"
                                        aria-label="API Base URL">
                                @else
                                    <code>{{ $settings['base_url'] }}</code>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <div class="p-config-field-label">
                                    API Key <span class="p-config-mask-hint" title="敏感字段">🛡</span>
                                </div>
                                <div class="p-config-field-path"><code>api_key</code></div>
                                <div class="p-config-field-desc">DeepSeek API key（明文存入 git，确保本仓私有）；留空保持原值。要清空请删 yaml 文件。</div>
                            </td>
                            <td>
                                @if ($editable)
                                    <input type="password" name="api_key" value="" autocomplete="new-password"
                                        class="p-config-input"
                                        placeholder="{{ $settings['api_key_set'] ? '已配置，留空保持原值' : 'sk-...' }}"
                                        aria-label="API Key">
                                @elseif ($settings['api_key_set'])
                                    <code class="p-config-mask">****</code>
                                @else
                                    <em class="p-config-null">未配置</em>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <div class="p-config-field-label">模型</div>
                                <div class="p-config-field-path"><code>model</code></div>
                            </td>
                            <td>
                                @if ($editable)
                                    <input type="text" name="model" value="{{ $settings['model'] }}"
                                        class="p-config-input" placeholder="{{ $settings['defaults']['model'] }}"
                                        aria-label="模型">
                                @else
                                    <code>{{ $settings['model'] }}</code>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <div class="p-config-field-label">超时（秒）</div>
                                <div class="p-config-field-path"><code>timeout</code></div>
                                <div class="p-config-field-desc">单次请求总超时</div>
                            </td>
                            <td>
                                @if ($editable)
                                    <input type="number" name="timeout" value="{{ $settings['timeout'] }}"
                                        min="1" max="120" class="p-config-input p-config-input--narrow"
                                        aria-label="超时秒数">
                                @else
                                    <code>{{ $settings['timeout'] }}</code>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <div class="p-config-field-label">连接超时（秒）</div>
                                <div class="p-config-field-path"><code>connect_timeout</code></div>
                                <div class="p-config-field-desc">建立连接的超时，区别于总 timeout</div>
                            </td>
                            <td>
                                @if ($editable)
                                    <input type="number" name="connect_timeout" value="{{ $settings['connect_timeout'] }}"
                                        min="1" max="120" class="p-config-input p-config-input--narrow"
                                        aria-label="连接超时秒数">
                                @else
                                    <code>{{ $settings['connect_timeout'] }}</code>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <div class="p-config-field-label">最大 Token</div>
                                <div class="p-config-field-path"><code>max_tokens</code></div>
                                <div class="p-config-field-desc">单次生成上限；翻译输出短，留足即可</div>
                            </td>
                            <td>
                                @if ($editable)
                                    <input type="number" name="max_tokens" value="{{ $settings['max_tokens'] }}"
                                        min="1" max="65536" class="p-config-input p-config-input--narrow"
                                        aria-label="最大 token">
                                @else
                                    <code>{{ $settings['max_tokens'] }}</code>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <div class="p-config-field-label">采样温度</div>
                                <div class="p-config-field-path"><code>temperature</code></div>
                                <div class="p-config-field-desc">越低越确定（0–2）。中文 → snake_case 求稳，建议 0.2；不填时上游默认 1.0 偏高</div>
                            </td>
                            <td>
                                @if ($editable)
                                    <input type="number" name="temperature" value="{{ $settings['temperature'] }}"
                                        min="0" max="2" step="0.1" class="p-config-input p-config-input--narrow"
                                        aria-label="采样温度">
                                @else
                                    <code>{{ $settings['temperature'] }}</code>
                                @endif
                            </td>
                        </tr>
                    </tbody>
                </x-scaffold::table>

                @if ($editable)
                    <div class="p-config-form-actions">
                        <x-scaffold::btn type="submit" variant="primary" size="sm">保存 AI 配置</x-scaffold::btn>
                        <span class="p-config-form-hint">写入 <code>{{ $yaml_path }}</code>；api_key 留空保持原值。</span>
                    </div>
                @endif
            </form>
        </section>
    </div>
    </section>
</div>
</x-scaffold::shell>
