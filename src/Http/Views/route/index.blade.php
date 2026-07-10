@php
    $shellTitle = 'Scaffold - 接口路由 - ' . ($apps[$current_app] ?? '选择应用');
@endphp

{{-- plan-22 P1-S3:layouts.two_columns 兼容层删,直接用 <x-scaffold::shell>
     原 @section('app-subnav') 在 two_columns 桥接下根本不渲染(dead section),
     迁过来后 sub-nav 真的会显示(修复 plan-22 P1-S2 意图遗留 bug) --}}
<x-scaffold::shell :title="$shellTitle" containerClass="is-route">
<x-slot:subnav>
    <x-scaffold::app-tabs :apps="$apps ?? []" :current="$current_app ?? null" route="route.list" />
</x-slot:subnav>
<div class="p-route-shell">
@if ($current_app !== '' && isset($app_routes[$current_app]))
    @php
        $modules = $app_routes[$current_app]['modules'];

        // plan-32:transform $modules → $routeGroups 喂 <x-scaffold::side-tree>
        // 一级 = module(protocol icon),二级 = controller(count = 该 controller 路由数)
        $routeGroups = [];
        foreach ($modules as $moduleKey => $module) {
            $ctrlCount = [];
            foreach ($module['routes'] as $r) {
                $c = $r['controller'];
                $ctrlCount[$c] = ($ctrlCount[$c] ?? 0) + 1;
            }
            $items = [];
            foreach ($ctrlCount as $ctrl => $count) {
                $items[] = [
                    'key' => $moduleKey . '__' . $ctrl,
                    'label' => $ctrl,
                    'count' => $count,
                    'href' => '#ctrl-' . $moduleKey . '-' . $ctrl,
                    // plan-32:用 side-tree 内置 status 点缀(灰圆点,纯装饰 — 跟 /api 二级 action 同款)
                    'status' => 'incomplete',
                    'data' => ['module' => $moduleKey, 'controller' => $ctrl],
                ];
            }
            $routeGroups[] = [
                'key' => $moduleKey,
                'label' => $module['name'],
                'count' => $module['route_count'],
                'items' => $items,
            ];
        }
    @endphp

    @if (!empty($modules))
        {{-- plan-32:接口路由 sidebar 迁 side-tree;默认收起(二级 controller 列表默认折叠) --}}
        <x-scaffold::side-tree
            id="route_sidebar"
            :groups="$routeGroups"
            :searchable="false"
            :collapsedByDefault="true"
        />
        {{-- 2026-07-09:路由 sidebar 拖拽把手（JS 贴 #route_sidebar 右沿；var = 卡片宽度，card 模式）--}}
        <div class="side-resizer" role="separator" aria-orientation="vertical"
             title="拖动调整侧栏宽度（双击复位）"
             data-resize-target="route_sidebar"
             data-resize-var="--scaffold-route-aside-width"
             data-resize-key="scaffold_route_aside_width"
             data-resize-min="200" data-resize-max="520" data-resize-default="260"></div>
    @endif
@endif

<div class="route-main">
    @php
        $totalAllRoutes = 0;
        foreach ($apps as $app => $_name) {
            foreach (($app_routes[$app]['modules'] ?? []) as $mod) {
                $totalAllRoutes += $mod['route_count'] ?? 0;
            }
        }
        $currentAppModules = isset($app_routes[$current_app]) ? count($app_routes[$current_app]['modules']) : 0;
        $currentAppRoutes  = isset($app_routes[$current_app])
            ? array_sum(array_column($app_routes[$current_app]['modules'], 'route_count'))
            : 0;
    @endphp
    {{-- 2026-05-30:头部砍"接口路由"标题(跟顶部导航高亮项重复)+ 搜索上移到 header 行(搜索左 / stats 右)。
                     共享 <x-scaffold::hero> 不再用,改 bespoke .route-header。 --}}
    <div class="route-header">
        @if ($current_app !== '' && isset($app_routes[$current_app]))
            @php
                $modules = $app_routes[$current_app]['modules'];
                $whitelistCount = 0;
                foreach ($modules as $mod) {
                    foreach ($mod['routes'] as $r) { if (!empty($r['is_whitelist'])) $whitelistCount++; }
                }
            @endphp
            <div class="route-search-bar">
                <x-scaffold::input
                    type="search"
                    id="route_search"
                    placeholder="搜索路由：URI、控制器、方法名、接口名称..."
                    value="{{ $keyword }}"
                    autocomplete="off"
                    aria-label="搜索路由（URI、控制器、方法名、接口名称）"
                />
                <x-scaffold::btn variant="primary" type="button" id="route_search_btn" aria-label="搜索">
                    <x-scaffold::icon name="search" :size="14" />
                    搜索
                </x-scaffold::btn>
                <label class="route-filter-chip" for="route_filter_whitelist" title="只显示 ACL 白名单接口（豁免登录）">
                    <input type="checkbox" id="route_filter_whitelist" />
                    <span>只看白名单</span>
                    <span class="route-filter-chip__count">{{ $whitelistCount }}</span>
                </label>
            </div>
        @endif
        <div class="route-header__stats section-meta-inline">
            <span><strong>{{ count($apps) }}</strong> 应用</span>
            @if ($currentAppModules > 0)
                <span><strong>{{ $currentAppModules }}</strong> 模块</span>
                <span><strong>{{ $currentAppRoutes }}</strong>/{{ $totalAllRoutes }} 路由</span>
            @else
                <span><strong>{{ $totalAllRoutes }}</strong> 路由</span>
            @endif
        </div>
    </div>

    {{-- 2026-05-22 plan-43 Batch E:.route-app-tabs page 内 app switcher 砍 — 顶部 sub-nav <x-scaffold::app-tabs> 已唯一入口 --}}

    @if ($current_app !== '' && isset($app_routes[$current_app]))
        @php $modules = $app_routes[$current_app]['modules']; @endphp

        @if (empty($modules))
            <x-scaffold::empty title="该应用暂无已注册的路由">
                <x-slot:icon><x-scaffold::icon name="protocol" :size="24" /></x-slot:icon>
            </x-scaffold::empty>
        @else
            {{-- plan-34:.route-summary 已并入 hero meta --}}
            <div class="route-modules" id="route_modules">
                @foreach ($modules as $moduleKey => $module)
                <div class="route-module" data-module="{{ $moduleKey }}" id="module-{{ $moduleKey }}">
                    <div
                        class="route-module-header"
                        role="button"
                        tabindex="0"
                        aria-expanded="true"
                        aria-controls="module-body-{{ $moduleKey }}"
                        aria-label="折叠/展开 {{ $module['name'] }} 模块"
                    >
                        <h3 class="route-module-name section-title-with-icon">
                            <span class="section-icon-box" aria-hidden="true">
                                <x-scaffold::icon name="protocol" :size="16" />
                            </span>
                            {{ $module['name'] }}
                        </h3>
                        <span class="route-module-meta">
                            {{ $module['controller_count'] }} 个控制器 / {{ $module['route_count'] }} 个路由
                        </span>
                        <span class="route-module-toggle" aria-hidden="true"></span>
                    </div>
                    <div class="route-module-body" id="module-body-{{ $moduleKey }}">
                        <table class="route-table">
                            <thead>
                                <tr>
                                    <th class="col-index">#</th>
                                    <th class="col-api-name">接口名称</th>
                                    <th class="col-method">Method</th>
                                    <th class="col-uri">URI</th>
                                    <th class="col-controller">Controller</th>
                                    <th class="col-action">Action</th>
                                    <th class="col-acl">ACL 明文</th>
                                    <th class="col-acl-hash">ACL 密文</th>
                                    <th class="col-links">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php $prevController = null; $seq = 0; @endphp
                                @foreach ($module['routes'] as $route)
                                @if ($route['controller'] !== $prevController)
                                    @php $prevController = $route['controller']; $seq = 0; @endphp
                                    <tr class="route-group-row"
                                        id="ctrl-{{ $moduleKey }}-{{ $route['controller'] }}"
                                        data-ctrl-anchor="{{ $route['controller'] }}"
                                        data-module="{{ $moduleKey }}">
                                        <td colspan="9">{{ $route['controller'] }}Controller</td>
                                    </tr>
                                @endif
                                @php
                                    $seq++;
                                    // plan-29:每行嵌完整 route 数据到 data-route(json),抽屉直接读
                                    $rowJson = json_encode($route + ['acl_yaml_path' => 'scaffold/acl/' . $current_app . '.yaml'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                                @endphp
                                <tr
                                    class="route-row {{ !empty($route['is_whitelist']) ? 'is-whitelist' : '' }}"
                                    data-search="{{ strtolower($route['method'] . ' ' . $route['uri'] . ' ' . $route['api_name'] . ' ' . $route['controller'] . ' ' . $route['action'] . ' ' . $route['acl_key'] . ' ' . ($route['name'] ?? '')) }}"
                                    data-whitelist="{{ !empty($route['is_whitelist']) ? '1' : '0' }}"
                                    data-route="{{ $rowJson }}"
                                    tabindex="0"
                                    role="button"
                                    aria-label="查看 {{ $route['method'] }} {{ $route['uri'] }} 的 ACL 详情"
                                >
                                    <td class="col-index">{{ $seq }}</td>
                                    <td class="col-api-name">
                                        {{ $route['api_name'] ?: '-' }}
                                        @if (!empty($route['is_whitelist']))
                                            <span class="route-whitelist-chip" title="ACL 白名单 · 豁免登录">✓ 白名单</span>
                                        @endif
                                    </td>
                                    <td class="col-method">
                                        @foreach ($route['methods'] as $method)
                                            <x-scaffold::method-badge :method="$method" size="sm" />
                                        @endforeach
                                    </td>
                                    <td class="col-uri"><code>{{ $route['uri'] }}</code></td>
                                    <td class="col-controller">{{ $route['controller'] }}</td>
                                    <td class="col-action">{{ $route['action'] }}</td>
                                    <td class="col-acl"><code class="acl-code">{{ $route['acl_key'] ?: '-' }}</code></td>
                                    <td class="col-acl-hash"><code class="acl-code">{{ $route['acl_hash'] ?: '-' }}</code></td>
                                    <td class="col-links">
                                        @if ($route['debug_url'])
                                            {{-- 2026-06-10:primary 实心橙整列太抢(user 反馈),降 secondary(边框灰字,hover 泛橙),仍比文档 ghost 重一档 --}}
                                            <x-scaffold::btn variant="secondary" size="sm" :href="$route['debug_url']" title="接口调试">调试</x-scaffold::btn>
                                        @endif
                                        @if ($route['doc_url'])
                                            <x-scaffold::btn variant="ghost" size="sm" :href="$route['doc_url']" title="接口文档">文档</x-scaffold::btn>
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                @endforeach
            </div>
        @endif
    @endif

    {{-- plan-29:ACL 详情抽屉(替代独立 /scaffold/acl 页) --}}
    <x-scaffold::drawer name="route-acl-detail" title="接口 · ACL 详情" width="680px">
        <div class="p-route-drawer" x-data="routeAclDetail" @set-route-detail.window="handleSet">
            <template x-if="hasRoute">
                <div class="p-route-drawer__body">
                    {{-- 头部:method + uri + 白名单 chip --}}
                    <div class="p-route-drawer__head">
                        <code class="p-route-drawer__methoduri">
                            <span class="p-route-drawer__methods" x-text="methodLabel"></span>
                            <span class="p-route-drawer__uri" x-text="route.uri"></span>
                        </code>
                        <span class="route-whitelist-chip" x-show="route.is_whitelist">✓ 白名单</span>
                    </div>

                    {{-- 中文路径(module · controller · action) --}}
                    <p class="p-route-drawer__zh-path" x-text="zhPath"></p>

                    {{-- 备注(可选) --}}
                    <p class="p-route-drawer__desc" x-show="route.acl_desc" x-text="route.acl_desc"></p>

                    {{-- ACL key 明 / 密 + 复制 --}}
                    <dl class="p-route-drawer__keys">
                        <dt>ACL 明文</dt>
                        <dd>
                            <code x-text="route.display_key"></code>
                            <button type="button" class="p-route-drawer__copy" @click="copyPlain" title="复制 ACL 明文"><x-scaffold::icon name="copy" :size="12" /></button>
                        </dd>
                        <dt>ACL 密文</dt>
                        <dd>
                            <code x-text="route.display_hash"></code>
                            <button type="button" class="p-route-drawer__copy" @click="copyHash" title="复制 ACL 密文"><x-scaffold::icon name="copy" :size="12" /></button>
                        </dd>
                    </dl>

                    {{-- plan-29 fix:transform_methods 场景(resource create→store / edit→update 等),
                         上面 ACL 明文实际是 transform 后的目标,这里告诉用户原始路由 key 和 ACL 目标 --}}
                    <template x-if="route.acl_transformed">
                        <div class="p-route-drawer__transform">
                            <span class="p-route-drawer__label">⤴ ACL transform <small>（路由本身不鉴权，落到 target action 上）</small></span>
                            <ul class="p-route-drawer__transform-list">
                                <template x-for="(tip, i) in transformTips" :key="i">
                                    <li>
                                        <span class="p-route-drawer__transform-key" x-text="tip.label"></span>
                                        <code x-text="tip.value"></code>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </template>

                    {{-- plan-29 #2 B:Controller 文件路径 + 复制按钮 --}}
                    <template x-if="route.controller_file">
                        <div class="p-route-drawer__file">
                            <span class="p-route-drawer__label">Controller</span>
                            <code x-text="route.controller_file_display"></code>
                            <button type="button" class="p-route-drawer__copy" @click="copyControllerFile" title="复制路径（粘到 IDE 跳转）"><x-scaffold::icon name="copy" :size="12" /></button>
                        </div>
                    </template>

                    {{-- plan-29 #2 B:middleware 完整链 --}}
                    <template x-if="hasMiddleware">
                        <div class="p-route-drawer__middleware">
                            <span class="p-route-drawer__label">Middleware</span>
                            <ul class="p-route-drawer__mw-list">
                                <template x-for="mw in route.middleware" :key="mw">
                                    <li><code x-text="mw"></code></li>
                                </template>
                            </ul>
                        </div>
                    </template>

                    {{-- plan-29 #2 B:快捷按钮 --}}
                    <div class="p-route-drawer__actions">
                        <template x-if="route.debug_url">
                            <a :href="route.debug_url" class="p-route-drawer__action-btn p-route-drawer__action-btn--primary" title="打开接口调试器">
                                <x-scaffold::icon name="send" :size="14" /><span>打开调试</span>
                            </a>
                        </template>
                        <template x-if="route.doc_url">
                            <a :href="route.doc_url" class="p-route-drawer__action-btn" title="打开接口文档">
                                <x-scaffold::icon name="file" :size="14" /><span>打开文档</span>
                            </a>
                        </template>
                    </div>

                    {{-- plan-29 #3 C1:curl 命令 + 复制 --}}
                    <div class="p-route-drawer__curl">
                        <span class="p-route-drawer__label">
                            curl 命令
                            <button type="button" class="p-route-drawer__copy" @click="copyCurl" title="复制 curl 命令"><x-scaffold::icon name="copy" :size="12" /></button>
                        </span>
                        <pre class="p-route-drawer__curl-pre"><code x-text="curlCommand"></code></pre>
                        <p class="p-route-drawer__curl-hint">含 &lt;token&gt; / 路径 &#123;id&#125; 占位符，自行替换</p>
                    </div>

                    {{-- plan-29 #3 C3:跨 app 同名对照 --}}
                    <template x-if="hasSiblingApps">
                        <div class="p-route-drawer__cross">
                            <span class="p-route-drawer__label">
                                跨 app 同名对照
                                <small>（同名，业务可能不同，谨慎对照）</small>
                            </span>
                            <div class="p-route-drawer__cross-list">
                                <template x-for="s in route.sibling_apps" :key="s.plain_key">
                                    <a :href="s.href" class="p-route-drawer__cross-chip" :title="s.display_zh">
                                        <span x-text="s.display_label"></span>
                                    </a>
                                </template>
                            </div>
                        </div>
                    </template>

                    {{-- 同 controller 兄弟 actions --}}
                    <template x-if="hasSiblings">
                        <div class="p-route-drawer__siblings">
                            <h4 class="p-route-drawer__section-title">
                                同 controller 兄弟 actions
                                <span class="p-route-drawer__count" x-text="siblings.length"></span>
                            </h4>
                            <ul>
                                <template x-for="(sib, idx) in siblings" :key="idx">
                                    <li>
                                        <button
                                            type="button"
                                            :class="sib.row_class"
                                            :data-sibling-idx="idx"
                                            @click="selectSiblingFromEl"
                                        >
                                            <span class="p-route-drawer__sibling-method" x-text="sib.method"></span>
                                            <code class="p-route-drawer__sibling-uri" x-text="sib.uri"></code>
                                            <span class="p-route-drawer__sibling-name" x-text="sib.display_label"></span>
                                            <span class="route-whitelist-chip route-whitelist-chip--sm" x-show="sib.is_whitelist" title="白名单">✓</span>
                                        </button>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </template>

                    {{-- ACL yaml 未生成提示 --}}
                    <template x-if="missingAcl">
                        <p class="p-route-drawer__missing">
                            未在 ACL yaml 找到此 action。<br>
                            执行 <code>php artisan moo:acl</code> 重新生成，或检查 controller 是否在生成范围内。
                        </p>
                    </template>

                    {{-- 来源 --}}
                    <p class="p-route-drawer__source">
                        来源： <code x-text="route.acl_yaml_path"></code>
                    </p>
                </div>
            </template>
        </div>
    </x-scaffold::drawer>
</div>
</div>

<x-slot:scripts>
{{-- 2026-06-20:内联 <script>(232 行)外提到 pages/route.js(清 static-guards「>30 行内联脚本」告警);逻辑零改动。 --}}
<script src="/vendor/scaffold/javascript/pages/route.js?v={{ @filemtime(public_path('vendor/scaffold/javascript/pages/route.js')) ?: time() }}"></script>
</x-slot:scripts>
</x-scaffold::shell>
