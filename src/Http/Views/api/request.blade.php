@php
    $shellTitle = empty($current_app) ? 'Scaffold - 接口调试' : 'Scaffold - 接口调试 - ' . ($apps[$current_app] ?? $current_app);

    // plan-27:api/request 同 api/index pattern,多一个 method attr 显示 GET/POST badge
    $apiGroups = ! empty($current_app)
        ? collect($menus)->map(function ($controllers, $folder_name) use ($menus_transform, $apis, $current_app, $current_folder, $current_controller, $current_action) {
            return [
                'key' => $folder_name,
                'label' => $menus_transform[$folder_name]['name'] ?? $folder_name,
                'count' => count($controllers),
                'sub_groups' => collect($controllers)->map(function ($attr, $controller_class) use ($folder_name, $apis, $current_app, $current_folder, $current_controller, $current_action) {
                    return [
                        'key' => $controller_class,
                        'label' => $attr['name'] ?? class_basename($controller_class),
                        'count' => $attr['api_count'] ?? null,
                        'items' => collect($apis[$folder_name][$controller_class] ?? [])->map(function ($api, $action) use ($folder_name, $controller_class, $current_app, $attr, $current_folder, $current_controller, $current_action) {
                            return [
                                'key' => $action,
                                'label' => $api['name'] ?? $action,
                                // 可搜文本:方法 + 真实接口路径 + action key,让按 URL / 方法也能搜到
                                'search' => trim(($api['method'] ?? '') . ' ' . ($api['url'] ?? '') . ' ' . $action),
                                'href' => 'javascript:;',
                                'method' => $api['method'] ?? null,
                                'deprecated' => ! empty($api['deprecated']),
                                // 2026-05-24:用 f+c+a 三元组精确算 is_active,避免 side-tree 仅按 action key
                                // 字符串匹配导致跨 controller 同名 action(如多个 create_get)全部高亮
                                'is_active' => $folder_name === $current_folder
                                    && $controller_class === $current_controller
                                    && $action === $current_action,
                                'data' => [
                                    'module' => $attr['name'] ?? '',
                                    'api-name' => $api['name'] ?? '',
                                    'f' => $folder_name,
                                    'c' => $controller_class,
                                    'a' => $action,
                                    'm' => $api['method'] ?? '',
                                    'url' => route('api.request', ['app' => $current_app, 'f' => $folder_name, 'c' => $controller_class, 'a' => $action]),
                                ],
                            ];
                        })->values()->all(),
                    ];
                })->values()->all(),
            ];
        })->values()->all()
        : [];

    $debugEmptyTitle = ! empty($current_app) && (empty($current_controller) && empty($current_action)) ? '从左侧选择一个接口开始调试' : '正在载入接口参数';
    $debugEmptyDesc  = ! empty($current_app) && (empty($current_controller) && empty($current_action)) ? '支持按环境切换、自动缓存 Token、参数回填和结果缓存，适合连续联调。' : '表单准备完成后会自动展示当前接口的请求头、参数和发送入口。';
@endphp

{{-- plan-22 P1-S3:layouts.app 兼容层删,直接用 <x-scaffold::shell>
     2026-05-23 user 反馈:接口调试页面要求外框 fit 视口高度,中间栏 + 右栏各自内置滚动条,
     不用浏览器原生滚动条(避免 sticky header 跟长 form/param table 滚动割裂)。
     containerClass="is-api-request" 触发 _shell.scss 里专用 height calc + overflow:hidden --}}
<x-scaffold::shell :title="$shellTitle" containerClass="is-api-request">

{{-- 2026-05-23 plan-50 后续:接口调试页 app 切换从 subnav tabs 改成 aside 顶部 select dropdown(节省一行垂直空间 + 把 app 切换 + 接口树视觉上聚合到 sidebar) --}}

@if (! empty($current_app))
<x-slot:aside>
    <x-scaffold::side-tree :groups="$apiGroups" :searchable="true" :collapsedByDefault="true" />
</x-slot:aside>
@endif

<x-slot:middle>
    {{-- 2026-06-20:取消"选应用"落地页(与 app 切换器冗余)—— controller 无 app 时直接重定向到默认应用。
         下面 no-app 分支只在"一个 app 都没配"的极端情况渲染。 --}}
    @if (empty($current_app))
    <x-scaffold::empty title="没有可用应用" desc="未发现任何接口应用。在 routes/ 下配置应用后，这里会自动进入。">
        <x-slot:icon><x-scaffold::icon name="send" :size="24" /></x-slot:icon>
    </x-scaffold::empty>
    @else
    <x-scaffold::empty :title="$debugEmptyTitle" :desc="$debugEmptyDesc">
        <x-slot:icon><x-scaffold::icon name="send" :size="24" /></x-slot:icon>
    </x-scaffold::empty>
    @endif
</x-slot:middle>

@if (! empty($current_app))
{{-- plan-50 后续 fix v2:top-bar 跨整个 viewport,左部 app selector(对齐 aside 宽),
     右部 tabs(对齐 middle+right)— ScaffoldDebugTabs.js 仍用 #debug_tabs_bar selector --}}
<x-slot:topBar>
    {{-- 左:app selector(对齐 aside 宽 260px) --}}
    <div class="api-debug-top-bar__left">
        @if (count($apps ?? []) > 1)
            <div class="api-debug-app-switcher">
                <label for="api_debug_app_select">应用</label>
                <select id="api_debug_app_select" aria-label="切换调试应用" data-route-tpl="{{ route('api.request', ['app' => '__APPKEY__']) }}">
                    @foreach ($apps as $key => $label)
                        <option value="{{ $key }}" {{ $key === $current_app ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        @else
            <span class="api-debug-app-switcher__current">{{ $apps[$current_app] ?? $current_app }}</span>
        @endif
    </div>
    {{-- 右:tabs(跨 middle+right) --}}
    <div class="api-debug-tabs" id="debug_tabs_bar" role="tablist" aria-label="已打开的调试 tabs">
        <span class="api-debug-tabs__empty" id="debug_tabs_empty" hidden>从左侧选择一个接口</span>
    </div>
</x-slot:topBar>
@endif

<x-slot:right>
    {{-- 2026-06-20:no-app(零应用)右栏不再渲染矛盾空态 —— 左侧应用选择器此态根本不渲染,
         且中栏「没有可用应用」已说明;右栏留空即可。 --}}
    @if (! empty($current_app))
    <div class="host-bar api-debug-hostbar">
        <div class="api-debug-host-main">
            <label for="host">环境</label>
            @if (! empty($hosts))
                <select id="host" aria-label="选择调试环境">
                    @foreach ($hosts as $label => $url)
                        <option value="{{ $url }}">{{ $label }} · {{ $url }}</option>
                    @endforeach
                </select>
            @else
                <input type="hidden" id="host" value="{{ $request_url }}" />
                <span class="host-txt">{{ $request_url }}</span>
            @endif
        </div>
        <button
            type="button"
            class="api-debug-history-btn"
            id="recent_records_trigger"
            aria-label="打开最近调试记录抽屉"
        >
            <x-scaffold::icon name="list" :size="14" />
            <span>最近记录</span>
        </button>
    </div>

    <x-scaffold::drawer name="recent-records" title="最近调试记录" width="420px">
        <div class="api-debug-recent" id="recent_records_panel">
            <div class="api-debug-recent__top">
                <p class="api-debug-recent__hint">
                    当前应用最近 100 条调试请求（仅本机），点击行可回填到当前表单。
                </p>
                <button type="button" class="api-debug-recent__clear" id="recent_records_clear" hidden>清空</button>
            </div>
            <ul class="api-debug-recent__list" id="recent_records_list" role="list"></ul>
            <div class="api-debug-recent__empty" id="recent_records_empty" hidden>
                还没有发送过请求。点击「发送」按钮后，调试记录会出现在这里。
            </div>
            <div class="api-debug-recent__pager" id="recent_records_pager" hidden>
                <button type="button" class="api-debug-recent__pager-btn" id="recent_records_prev" aria-label="上一页">上一页</button>
                <span class="api-debug-recent__pager-info" id="recent_records_pageinfo" aria-live="polite">1 / 1</span>
                <button type="button" class="api-debug-recent__pager-btn" id="recent_records_next" aria-label="下一页">下一页</button>
            </div>
        </div>
    </x-scaffold::drawer>
    <x-scaffold::panel class="api-debug-console">
        <div class="api-debug-send-box api-debug-result-bar">
            <p class="status" id="result_status">WAIT</p>
            <p class="method-type" id="result_method">...</p>
            <p class="result-txt" id="result_uri">发送请求后将在这里展示实际地址</p>
            <div class="api-debug-result-meta" id="result_meta" hidden>
                <span class="api-debug-result-meta-item"><label>耗时</label><b id="result_elapsed">—</b></span>
                <span class="api-debug-result-meta-item"><label>体积</label><b id="result_size">—</b></span>
                <span class="api-debug-result-meta-item"><label>时间</label><b id="result_time">—</b></span>
            </div>
        </div>
    </x-scaffold::panel>
    <x-scaffold::panel class="api-debug-console api-debug-response-panel" hdClass="api-debug-response-tabs" :wrapBody="false">
        <x-slot:hd>
            <div class="api-debug-response-tabs-main">
                <button type="button" class="api-debug-response-tab is-active" data-pane="body">Response Body</button>
                {{-- 2026-05-22:form_widgets 表单预览 tab — JS detect 命中才显示;贴 Body 旁边便于快切 --}}
                <button type="button" class="api-debug-response-tab api-debug-response-tab--form-preview" data-pane="form-preview" id="form_preview_tab" hidden>🧩 表单预览</button>
                <button type="button" class="api-debug-response-tab" data-pane="headers">Response Headers</button>
            </div>
            <div class="api-debug-response-actions">
                <button type="button" class="api-debug-copy-btn" id="response_copy_btn" title="复制当前响应内容到剪贴板">复制</button>
            </div>
        </x-slot:hd>
        <div class="bd api-debug-response-pane is-active" data-pane="body">
            <div class="json-block api-debug-response-body" id="json_format">发送请求后将在这里展示响应结果...</div>
        </div>
        <div class="bd api-debug-response-pane" data-pane="headers">
            <div class="json-block api-debug-response-headers" id="header">
                等待请求返回...
            </div>
        </div>
        <div class="bd api-debug-response-pane api-debug-form-preview" data-pane="form-preview">
            <div class="api-debug-form-preview__main" id="form_preview_main"></div>
            <aside class="api-debug-form-preview__output">
                <div class="api-debug-form-preview__output-hd">
                    <h4 class="api-debug-form-preview__output-title">当前表单值</h4>
                    <button type="button" class="api-debug-copy-btn" id="form_preview_copy_btn" title="复制当前表单值 JSON 到剪贴板"><x-scaffold::icon name="copy" :size="12" /><span>复制</span></button>
                </div>
                <pre class="api-debug-form-preview__output-body" id="form_preview_output">{}</pre>
            </aside>
        </div>
    </x-scaffold::panel>
    @endif
</x-slot:right>

@if (! empty($current_app))
<x-slot:scripts>
<script src="/vendor/scaffold/javascript/jsonFormat.js"></script>
<script src="/vendor/scaffold/javascript/clipboard.min.js"></script>
<script nonce="{{ $cspNonce ?? "" }}">
    // 统一命名空间，避免裸全局
    window.ScaffoldConfig = Object.assign(window.ScaffoldConfig || {}, {
        apiCache:  '{{ route('api.cache', [], false) }}',
        apiProxy:  '{{ route('api.proxy', [], false) }}',
        apiParam:  '{{ route('api.param', [], false) }}'
    });

    // 所有 jQuery $.ajax 自动带 CSRF token——apiProxy / apiCache / apiRecord 都在 scaffold 的 VerifyCsrfToken 组里
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' } });

    // pages/api-request-index.js 的页面级配置
    window.ScaffoldRequestIndex = {
        routes: {
            apiParam:  window.ScaffoldConfig.apiParam,
            apiRecord: window.ScaffoldConfig.apiRecord
        },
        currentApp:     '{{ $current_app }}',
        currentAppName: '{{ $apps[$current_app] ?? $current_app }}'
    };
</script>
<script src="/vendor/scaffold/javascript/pages/api-request.js?v={{ @filemtime(public_path('vendor/scaffold/javascript/pages/api-request.js')) ?: time() }}"></script>
<script src="/vendor/scaffold/javascript/pages/api-request-index.js?v={{ @filemtime(public_path('vendor/scaffold/javascript/pages/api-request-index.js')) ?: time() }}"></script>
@if (!empty($current_controller) && ! empty($current_action))
<script nonce="{{ $cspNonce ?? "" }}">
    // plan-50:首次落地接口走 ScaffoldDebugTabs.openOrSwitch 把它做成第一个 tab
    // (而不是直接 getParams 绕过 tabs 编排)
    $(function () {
        if (window.ScaffoldDebugTabs && window.ScaffoldDebugTabs.openOrSwitch) {
            // 找 sidebar 对应 link 拿 url + apiName(避免重复 server-render 信息)
            var $link = $('#aside_container .side-tree__item-link[data-a="{{ $current_action }}"][data-c="{{ $current_controller }}"][data-f="{{ $current_folder }}"]').first();
            ScaffoldDebugTabs.openOrSwitch({
                f: '{{ $current_folder }}',
                c: '{{ $current_controller }}',
                a: '{{ $current_action }}',
                m: '{{ $current_method }}',
                url: $link.data('url') || location.href,
                module: $link.data('module') || '',
                apiName: $link.data('api-name') || '{{ $current_action }}',
            });
        } else {
            // fallback(若 ScaffoldDebugTabs 没载入)
            window.scaffoldRequestIndexGetParams(
                '{{ $current_folder }}',
                '{{ $current_controller }}',
                '{{ $current_action }}',
                '{{ $current_method }}'
            );
        }
    });
</script>
@endif
</x-slot:scripts>
@endif

</x-scaffold::shell>
