@php
    $shellTitle = empty($current_app) ? 'Scaffold - 接口文档' : 'Scaffold - 接口文档 - ' . ($apps[$current_app] ?? $current_app);
    $shellContainerClass = ! empty($current_app) ? 'is-collapsed-sidebar' : '';

    // plan-27:transform 3 层 ($menus + $apis) → $groups,sidebar 走 <x-scaffold::side-tree>
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
                                'href' => 'javascript:;',
                                'deprecated' => ! empty($api['deprecated']),
                                // 2026-05-24:f+c+a 三元组精确算 is_active,避免跨 controller 同名 action 全部高亮
                                'is_active' => $folder_name === $current_folder
                                    && $controller_class === $current_controller
                                    && $action === $current_action,
                                'data' => [
                                    'module' => $attr['name'] ?? '',
                                    'api-name' => $api['name'] ?? '',
                                    'f' => $folder_name,
                                    'c' => $controller_class,
                                    'a' => $action,
                                    'url' => route('api.list', ['app' => $current_app, 'f' => $folder_name, 'c' => $controller_class, 'a' => $action]),
                                ],
                            ];
                        })->values()->all(),
                    ];
                })->values()->all(),
            ];
        })->values()->all()
        : [];

    // 复杂数组在 @php 块里组装:blade @json(...) 的解析器对嵌套方括号
    // ($apis[$f][$c][$a]['name'])匹配不正确,会让外层 ) 错位
    $scaffoldApiInitial = (! empty($current_app) && ! empty($current_controller) && ! empty($current_action))
        ? [
            'folder'     => $current_folder,
            'controller' => $current_controller,
            'action'     => $current_action,
            'apiName'    => $apis[$current_folder][$current_controller][$current_action]['name'] ?? $current_action,
            'moduleName' => $menus[$current_folder][$current_controller]['name'] ?? '',
        ]
        : null;
    $scaffoldApiCurrentAppName = $apps[$current_app] ?? $current_app;
@endphp

{{-- plan-22 P1-S3:layouts.app 兼容层删,直接用 <x-scaffold::shell> --}}
<x-scaffold::shell :title="$shellTitle" :containerClass="$shellContainerClass">

<x-slot:subnav>
    <x-scaffold::app-tabs :apps="$apps ?? []" :current="$current_app ?? null" route="api.list" />
</x-slot:subnav>

@if (! empty($current_app))
<x-slot:aside>
    <x-scaffold::side-tree :groups="$apiGroups" :searchable="true" :collapsedByDefault="true" />
</x-slot:aside>
@endif

{{-- 2026-06-19:取消"选应用"落地页(picker 与 subnav app-tabs 冗余)—— controller 无 app 时直接重定向到
     默认应用。下面 no-app 分支只在"一个 app 都没配"的极端情况渲染。 --}}
<x-slot:right>
    @if (empty($current_app))
        <x-scaffold::empty
            title="没有可用应用"
            desc="未发现任何接口应用。在 routes/ 下配置应用后，这里会自动进入。"
        >
            <x-slot:icon><x-scaffold::icon name="file" :size="24" /></x-slot:icon>
        </x-scaffold::empty>
    @else
        <x-scaffold::empty
            title="请选择接口"
            desc="左侧展开模块和控制器后，点击具体接口，右侧会展示文档详情，并可跳转到接口调试页。"
        >
            <x-slot:icon><x-scaffold::icon name="file" :size="24" /></x-slot:icon>
        </x-scaffold::empty>
    @endif
</x-slot:right>

@if (! empty($current_app))
<x-slot:scripts>
<script nonce="{{ $cspNonce ?? "" }}">
    window.ScaffoldApiIndex = {
        currentApp:     @json($current_app),
        currentAppName: @json($scaffoldApiCurrentAppName),
        apiShowUrl:     @json(route('api.show')),
        initial:        @json($scaffoldApiInitial)
    };
</script>
<script src="/vendor/scaffold/javascript/pages/api-index.js?v={{ @filemtime(public_path('vendor/scaffold/javascript/pages/api-index.js')) ?: time() }}"></script>
</x-slot:scripts>
@endif

</x-scaffold::shell>
