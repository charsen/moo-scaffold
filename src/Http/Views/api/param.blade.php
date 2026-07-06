@php
    $methodText = strtoupper($request[0]);
    $apiMeta = $api_meta ?? ['creator' => '', 'created_at' => '', 'updated_by' => '', 'updated_at' => '', 'deprecated_at' => '', 'deprecated_reason' => ''];
    $metaFallback = '未记录';
    $updatedMetaFallback = '-';
    $isDeprecated = ! empty($deprecated);
@endphp

<div class="debug-request-page">
    <div class="debug-request-head">
        <div class="debug-request-title-wrap">
            <a href="{{ route('api.list', ['app' => $current_app ?? 'admin', 'f' => $current_folder, 'c' => $current_controller, 'a' => $current_action]) }}" target="_blank" class="debug-request-doc">
                <x-scaffold::icon name="file" :size="16" />
            </a>
            <h2 class="debug-request-title">
                <x-scaffold::method-badge :method="$methodText" />
                <span class="debug-request-title-text{{ $isDeprecated ? ' is-deprecated' : '' }}">{{ $name }}</span>
            </h2>
        </div>
        @if (! empty($check_action))
            <div class="debug-request-title-main">
                <a
                    href="{{ route('acl.list', ['app' => $current_app ?? 'admin', 'keyword' => $check_action]) }}"
                    target="_blank"
                    class="debug-chip debug-title-chip debug-request-acl"
                    title="查看 ACL"
                >
                    <span class="debug-title-chip-label">ACL</span>
                    <span class="debug-title-chip-value">{{ $check_action }}</span>
                </a>
            </div>
        @endif
        @if ($isDeprecated)
            <div class="debug-request-title-main">
                <span class="debug-chip debug-title-chip debug-request-deprecated">已弃用</span>
            </div>
        @endif
        <div class="debug-request-meta">
            <div class="debug-request-meta-card">
                <span class="debug-request-meta-label">创建人</span>
                <strong class="debug-request-meta-value">{{ $apiMeta['creator'] !== '' ? $apiMeta['creator'] : $metaFallback }}</strong>
            </div>
            <div class="debug-request-meta-card">
                <span class="debug-request-meta-label">创建时间</span>
                <strong class="debug-request-meta-value">{{ $apiMeta['created_at'] !== '' ? $apiMeta['created_at'] : $metaFallback }}</strong>
            </div>
            <div class="debug-request-meta-card">
                <span class="debug-request-meta-label">修改人</span>
                <strong class="debug-request-meta-value">{{ $apiMeta['updated_by'] !== '' ? $apiMeta['updated_by'] : $updatedMetaFallback }}</strong>
            </div>
            <div class="debug-request-meta-card">
                <span class="debug-request-meta-label">修改时间</span>
                <strong class="debug-request-meta-value">{{ $apiMeta['updated_at'] !== '' ? $apiMeta['updated_at'] : $updatedMetaFallback }}</strong>
            </div>
        </div>
    </div>

    @if ($isDeprecated)
        <div class="api-debug-alert debug-request-alert">
            <h3>接口已弃用</h3>
            <p>该接口已从当前 routes 定义中移除，调试时可能返回 404 或不再符合现网行为。</p>
            @if (! empty($apiMeta['deprecated_at']))
                <p>弃用时间：{{ $apiMeta['deprecated_at'] }}</p>
            @endif
            @if (! empty($apiMeta['deprecated_reason']))
                <p>{{ $apiMeta['deprecated_reason'] }}</p>
            @endif
        </div>
    @endif

    <x-scaffold::panel class="debug-request-toolbar-panel">
        <div class="api-debug-send-box debug-request-toolbar" data-method="{{ strtoupper($request[0]) }}">
            <select id="send_method" class="debug-request-method" aria-label="请求方法">
                @foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $m)
                    <option value="{{ $m }}" {{ strtoupper($request[0]) === $m ? 'selected' : '' }}>{{ $m }}</option>
                @endforeach
            </select>
            <input type="hidden" id="cache_key_base" value="{{ $cache_key_base ?? $cache_key }}" />
            <input type="hidden" id="cache_key" value="{{ $cache_key }}" />
            <input class="txt debug-request-uri" id="uri" value="/{{ $request[1] }}" aria-label="请求 URL" />
            <button type="button" class="send-btn" id="send">发送</button>
        </div>
    </x-scaffold::panel>

    @if ( ! empty($desc))
        <div class="api-debug-alert debug-request-alert">
            <h3>说明</h3>
            @foreach ($desc as $v)
                <p>{{ $v }}</p>
            @endforeach
        </div>
    @endif

    <x-scaffold::panel class="debug-param-panel">
        <x-slot:hd>
            <div class="debug-panel-heading">
                <h3>Header</h3>
            </div>
            <button type="button" class="api-debug-json-paste" title="读取剪贴板 JSON 一键填入（需 HTTPS / localhost）"><x-scaffold::icon name="copy" :size="12" /><span>粘贴</span></button><button type="button" class="api-debug-toggle" title="切换 JSON 编辑视图" aria-label="切换 Header 为 JSON 编辑视图"><span class="api-debug-toggle__label">JSON</span><x-scaffold::icon name="code" :size="14" /></button>
        </x-slot:hd>
        <div class="api-debug-tab-bd active">
            <div class="table debug-param-table" id="request_header">
                <table>
                    <tr>
                        <th width="30"><input type="checkbox" class="checkbox-all" aria-label="全选 Header"></th>
                        <th width="80">名称</th>
                        <th>key</th>
                        <th>value</th>
                    </tr>
                    <tr>
                        <td><input type="checkbox" class="checkbox" checked aria-label="启用 Accept Header" /></td>
                        <td><input type="text" class="txt debug-param-plain" value="Accept" aria-label="Header 名称"></td>
                        <td><input type="text" class="txt key debug-param-plain" value="Accept" aria-label="Header key"></td>
                        <td><input type="text" class="txt value" value="application/json" aria-label="Accept Header 值"></td>
                    </tr>

                    @if (isset($header_params['token']))
                    <tr>
                        <td><input type="checkbox" class="checkbox" checked aria-label="启用 Authorization Token Header" /></td>
                        <td><input type="text" class="txt debug-param-plain" value="Token" aria-label="Header 名称"></td>
                        <td><input type="text" class="txt key debug-param-plain" value="Authorization" aria-label="Header key"></td>
                        <td><input type="text" class="txt value" id="auth_token" value="Bearer {{ $header_params['token'] }}" aria-label="Authorization Token 值"></td>
                    </tr>
                    @endif
                </table>
            </div>
            <button type="button" class="api-debug-add-row" data-target="#request_header" data-cols="4">+ 新增 Header</button>
        </div>
        <div class="api-debug-tab-bd">
            <div class="api-debug-edit-param">
                <textarea class="api-debug-json-editor" rows="14" spellcheck="false"
                    aria-label="JSON 直接编辑参数（粘贴自动回填表格）"
                    placeholder="粘贴整段 JSON 到此处，会自动回填到上面的表格（无需点按钮）。
示例：
{
  &quot;memo_title&quot;: &quot;...&quot;,
  &quot;up_personnel_id&quot;: [&quot;a&quot;, &quot;b&quot;]
}"></textarea>
                <div class="api-debug-json-status" aria-live="polite"></div>
            </div>
        </div>
    </x-scaffold::panel>

    @if ( ! empty($url_params))
    <x-scaffold::panel class="debug-param-panel">
        <x-slot:hd>
            <div class="debug-panel-heading">
                <h3>Url Params</h3>
                <span>{{ count($url_params) }} 项</span>
            </div>
            <button type="button" class="api-debug-json-paste" title="读取剪贴板 JSON 一键填入（需 HTTPS / localhost）"><x-scaffold::icon name="copy" :size="12" /><span>粘贴</span></button><button type="button" class="api-debug-toggle" title="切换 JSON 编辑视图" aria-label="切换 Url Params 为 JSON 编辑视图"><span class="api-debug-toggle__label">JSON</span><x-scaffold::icon name="code" :size="14" /></button>
        </x-slot:hd>
        <div class="api-debug-tab-bd active">
            <div class="table debug-param-table" id="request_params">
                <table>
                    <tr>
                        <th width="30"><input type="checkbox" class="checkbox-all" aria-label="全选 Url Params"></th>
                        <th width="80">名称</th>
                        <th>key</th>
                        <th>value</th>
                    </tr>
                    @foreach ($url_params as $key => $v)
                    @php
                        $displayKey = $v['display_key'] ?? $key;
                        $sendKey = $v['send_key'] ?? $displayKey;
                        $sendable = $v['sendable'] ?? true;
                        // 2026-06-20:说明列已删 —— 填写提示(desc)+ 验证约束(rules)合并进 VALUE hover
                        $hoverHint = trim(implode('；', array_filter([$v['desc'] ?? '', $v['rules'] ?? ''])));
                    @endphp
                    <tr>
                        <td>
                            <input type="checkbox" class="checkbox" {{ ($v['require'] && $sendable ? 'checked' : '') }} {{ $sendable ? '' : 'disabled' }} aria-label="启用 Url 参数 {{ $v['name'] }}({{ $displayKey }})">
                            <input type="hidden" class="cache-key" value="{{ $key }}">
                            <input type="hidden" class="send-key" value="{{ $sendKey }}">
                        </td>
                        <td><span class="txt debug-param-plain" title="{{ $v['name'] }}">{{ $v['name'] }}</span></td>
                        <td><span class="txt key debug-param-plain" title="{{ $displayKey }}">{{ $displayKey }}</span></td>
                        <td>
                            <div class="debug-value-cell">
                                @if ($sendable && isset($v['type']) && $v['type'] == 'radio')
                                    <select class="select value" aria-label="{{ $v['name'] }} 值（选项）">
                                    @foreach ($v['options'] as $sk => $sv)
                                        <option value="{{ $sk }}" {{ $sk == $v['value'] ? 'selected' : ''}}>{{ $sk }}: {{ $sv }}</option>
                                    @endforeach
                                    </select>
                                @else
                                    <input type="text" value="{{ $v['value'] }}" class="txt value" {{ $sendable ? '' : 'readonly' }} aria-label="{{ $v['name'] }} 值">
                                @endif
                                @if ($hoverHint !== '')
                                    <button type="button" class="api-debug-hint-icon" data-hint="{{ $hoverHint }}" aria-label="{{ $v['name'] }} 说明"><x-scaffold::icon name="info" :size="16" /></button>
                                @endif
                            </div>
                        </td>                    </tr>
                    @endforeach
                </table>
            </div>
        </div>
        <div class="api-debug-tab-bd">
            <div class="api-debug-edit-param">
                <textarea class="api-debug-json-editor" rows="14" spellcheck="false"
                    aria-label="JSON 直接编辑参数（粘贴自动回填表格）"
                    placeholder="粘贴整段 JSON 到此处，会自动回填到上面的表格（无需点按钮）。
示例：
{
  &quot;memo_title&quot;: &quot;...&quot;,
  &quot;up_personnel_id&quot;: [&quot;a&quot;, &quot;b&quot;]
}"></textarea>
                <div class="api-debug-json-status" aria-live="polite"></div>
            </div>
        </div>
    </x-scaffold::panel>
    @endif

    @if ( ! empty($body_params))
    <x-scaffold::panel class="debug-param-panel">
        <x-slot:hd>
            <div class="debug-panel-heading">
                <h3>Body Params</h3>
            </div>
            <button type="button" class="api-debug-json-paste" title="读取剪贴板 JSON 一键填入（需 HTTPS / localhost）"><x-scaffold::icon name="copy" :size="12" /><span>粘贴</span></button><button type="button" class="api-debug-toggle" title="切换 JSON 编辑视图" aria-label="切换 Body Params 为 JSON 编辑视图"><span class="api-debug-toggle__label">JSON</span><x-scaffold::icon name="code" :size="14" /></button>
        </x-slot:hd>
        <div class="api-debug-tab-bd active">
            <div class="table debug-param-table" id="request_body_params">
                <table>
                    <tr>
                        <th width="30"><input type="checkbox" class="checkbox-all" aria-label="全选 Body Params"></th>
                        <th width="80">名称</th>
                        <th>key</th>
                        <th>value</th>
                    </tr>
                    @foreach ($body_params as $key => $v)
                    @php
                        $displayKey = $v['display_key'] ?? $key;
                        $sendKey = $v['send_key'] ?? $displayKey;
                        $sendable = $v['sendable'] ?? true;
                        // 2026-06-20:说明列已删 —— 填写提示(desc)+ 验证约束(rules)合并进 VALUE hover
                        $hoverHint = trim(implode('；', array_filter([$v['desc'] ?? '', $v['rules'] ?? ''])));
                    @endphp
                    <tr>
                        <td>
                            <input type="checkbox" class="checkbox" {{ ($v['require'] && $sendable ? 'checked' : '') }} {{ $sendable ? '' : 'disabled' }} aria-label="启用 Body 参数 {{ $v['name'] }}({{ $displayKey }})">
                            <input type="hidden" class="cache-key" value="{{ $key }}">
                            <input type="hidden" class="send-key" value="{{ $sendKey }}">
                        </td>
                        <td><span class="txt debug-param-plain" title="{{ $v['name'] }}">{{ $v['name'] }}</span></td>
                        <td><span class="txt key debug-param-plain" title="{{ $displayKey }}">{{ $displayKey }}</span></td>
                        <td>
                            <div class="debug-value-cell">
                                @if ($sendable && isset($v['type']) && $v['type'] == 'radio')
                                    <select class="select value" aria-label="{{ $v['name'] }} 值（选项）">
                                    @foreach ($v['options'] as $sk => $sv)
                                        <option value="{{ $sk }}" {{ $sk == $v['value'] ? 'selected' : ''}}>{{ $sk }}: {{ $sv }}</option>
                                    @endforeach
                                    </select>
                                @else
                                    <input type="text" value="{{ $v['value'] }}" class="txt value" {{ $sendable ? '' : 'readonly' }} aria-label="{{ $v['name'] }} 值">
                                @endif
                                @if ($hoverHint !== '')
                                    <button type="button" class="api-debug-hint-icon" data-hint="{{ $hoverHint }}" aria-label="{{ $v['name'] }} 说明"><x-scaffold::icon name="info" :size="16" /></button>
                                @endif
                            </div>
                        </td>                    </tr>
                    @endforeach
                </table>
            </div>
        </div>
        <div class="api-debug-tab-bd">
            <div class="api-debug-edit-param">
                <textarea class="api-debug-json-editor" rows="14" spellcheck="false"
                    aria-label="JSON 直接编辑参数（粘贴自动回填表格）"
                    placeholder="粘贴整段 JSON 到此处，会自动回填到上面的表格（无需点按钮）。
示例：
{
  &quot;memo_title&quot;: &quot;...&quot;,
  &quot;up_personnel_id&quot;: [&quot;a&quot;, &quot;b&quot;]
}"></textarea>
                <div class="api-debug-json-status" aria-live="polite"></div>
            </div>
        </div>
    </x-scaffold::panel>
    @endif
</div>
