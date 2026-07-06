@php
    $method = strtoupper($request[0] ?? 'GET');
    $headerCount = 1 + (isset($header_params['token']) ? 1 : 0);
    $apiMeta = $api_meta ?? ['deprecated_at' => '', 'deprecated_reason' => ''];
    $isDeprecated = ! empty($deprecated);
@endphp

<div class="p-api-doc">
    <div class="p-api-doc__header">
        <div class="p-api-doc__title-wrap">
            <x-scaffold::method-badge :method="$method" />
            <h2 class="p-api-doc__title{{ $isDeprecated ? ' is-deprecated' : '' }}">{{ $name }}</h2>
            @if ($isDeprecated)
                <x-scaffold::badge tone="warning">已弃用</x-scaffold::badge>
            @endif
            @if (! empty($check_action))
                <x-scaffold::badge tone="accent">ACL <code class="p-api-doc__acl-code">{{ $check_action }}</code></x-scaffold::badge>
            @endif
        </div>
        <x-scaffold::btn
            variant="primary"
            size="sm"
            :href="route('api.request', ['app' => $current_app ?? 'admin', 'f' => $current_folder, 'c' => $current_controller, 'a' => $current_action])"
            target="_blank"
        >
            <x-scaffold::icon name="send" :size="14" />
            调试接口
        </x-scaffold::btn>
    </div>

    <div class="p-api-doc__route">{{ $request[1] }}</div>

    @if ($isDeprecated)
    <div class="p-api-doc__alert p-api-doc__alert--deprecated">
        <h3>接口已弃用</h3>
        <p>该接口已从当前 routes 定义中移除，当前文档仅作为历史记录保留。</p>
        @if (! empty($apiMeta['deprecated_at']))
            <p>弃用时间：{{ $apiMeta['deprecated_at'] }}</p>
        @endif
        @if (! empty($apiMeta['deprecated_reason']))
            <p>{{ $apiMeta['deprecated_reason'] }}</p>
        @endif
    </div>
    @endif

    <div class="p-api-doc__summary">
        <x-scaffold::stat-card label="Header Params" :value="$headerCount" tone="info">
            <x-slot:icon><x-scaffold::icon name="key" :size="18" /></x-slot:icon>
        </x-scaffold::stat-card>
        <x-scaffold::stat-card label="Url Params" :value="count($url_params ?? [])" tone="success">
            <x-slot:icon><x-scaffold::icon name="code" :size="18" /></x-slot:icon>
        </x-scaffold::stat-card>
        <x-scaffold::stat-card label="Body Params" :value="count($body_params ?? [])" tone="accent">
            <x-slot:icon><x-scaffold::icon name="send" :size="18" /></x-slot:icon>
        </x-scaffold::stat-card>
    </div>

    @if (! empty($desc))
    <div class="p-api-doc__alert">
        <h3>详情描述</h3>
        @foreach ($desc as $v)
            <p>{{ $v }}</p>
        @endforeach
    </div>
    @endif

    <section class="p-api-doc__section">
        <div class="p-api-doc__section-head">
            <h3>Header Params</h3>
            <span class="p-api-doc__section-count">{{ $headerCount }} 项</span>
        </div>
        <x-scaffold::table id="request_header" class="p-api-doc__param-table">
            <thead>
                <tr>
                    <th>参数</th>
                    <th>名称</th>
                    <th>值</th>
                    <th>说明</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><em class="p-api-doc__required">*</em>Accept</td>
                    <td></td>
                    <td><code>application/json</code></td>
                    <td></td>
                </tr>
                @if (isset($header_params['token']))
                <tr>
                    <td><em class="p-api-doc__required">*</em>Authorization</td>
                    <td></td>
                    <td><code>Bearer {Token}</code></td>
                    <td></td>
                </tr>
                @endif
            </tbody>
        </x-scaffold::table>
    </section>

    @if (! empty($url_params))
    <section class="p-api-doc__section">
        <div class="p-api-doc__section-head">
            <h3>Url Params</h3>
            <span class="p-api-doc__section-count">{{ count($url_params) }} 项</span>
        </div>
        <x-scaffold::table id="url_params" class="p-api-doc__param-table">
            <thead>
                <tr>
                    <th>参数</th>
                    <th>名称</th>
                    <th>值</th>
                    <th>说明</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($url_params as $key => $v)
                    @php($displayKey = $v['display_key'] ?? $key)
                    <tr>
                        <td>
                            @if ($v['require'])<em class="p-api-doc__required">*</em>@endif
                            {{ $displayKey }}
                        </td>
                        <td>{{ $v['name'] }}</td>
                        <td><code>{{ $v['value'] }}</code></td>
                        <td>{{ trim(implode('；', array_filter([$v['rules'] ?? '', $v['desc'] ?? '']))) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </x-scaffold::table>
    </section>
    @endif

    @if (! empty($body_params))
    <section class="p-api-doc__section">
        <div class="p-api-doc__section-head">
            <h3>Body Params</h3>
            <span class="p-api-doc__section-count">{{ count($body_params) }} 项</span>
        </div>
        <x-scaffold::table id="body_params" class="p-api-doc__param-table">
            <thead>
                <tr>
                    <th>参数</th>
                    <th>名称</th>
                    <th>值</th>
                    <th>说明</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($body_params as $key => $v)
                    @php($displayKey = $v['display_key'] ?? $key)
                    <tr>
                        <td>
                            @if ($v['require'])<em class="p-api-doc__required">*</em>@endif
                            {{ $displayKey }}
                        </td>
                        <td>{{ $v['name'] }}</td>
                        <td><code>{{ $v['value'] }}</code></td>
                        <td>{{ trim(implode('；', array_filter([$v['rules'] ?? '', $v['desc'] ?? '']))) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </x-scaffold::table>
    </section>
    @endif
</div>
