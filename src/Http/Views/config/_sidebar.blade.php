{{-- 配置页左侧导航:4 个顶层分组(基础配置 / AI 配置 / Env 镜像 / 人员管理)
     基础配置可展开现有 6 个字段组(锚点);其余 3 组各自一个独立页面。
     基础配置子项带 data-group → alpine-init.js#configToc scroll-spy 反查 toggle .is-active。 --}}
@php
    /** @var array<string, array{key:string,label:string,desc:string}> $all_groups */
    $allGroups = $all_groups ?? [];
    $current = $active ?? '';
    $sidebarClass = $sidebar_class ?? 'p-config-page__sidebar';

    // 「基础配置」:现有 6 个字段组锚点(回 config 主页 + #group-{key})
    $basicItems = [];
    foreach ($allGroups as $key => $g) {
        $basicItems[] = [
            'key' => 'cfg_' . $key,
            'label' => $g['label'],
            'href' => route('scaffold.config') . '#group-' . $key,
            'is_active' => false,                       // 留给 scroll-spy 动态 toggle
            'data' => ['group' => $key],                // <a data-group> 给 alpine configToc 反查
        ];
    }

    $configGroups = [
        ['key' => 'g_basic', 'label' => '基础配置', 'items' => $basicItems],
        ['key' => 'g_ai', 'label' => 'AI 配置', 'items' => [
            ['key' => 'cfg_ai', 'label' => 'DeepSeek 翻译', 'href' => route('scaffold.config.ai'), 'is_active' => $current === '__ai'],
        ]],
        ['key' => 'g_env', 'label' => 'Env 镜像', 'items' => [
            ['key' => 'cfg_env', 'label' => '.env 镜像', 'href' => route('scaffold.config.env'), 'is_active' => $current === '__env'],
        ]],
    ];
    // 人员管理仅 admin 可见:非 admin 进 /accounts 会被 EnforceAdminOnly 拦,入口同步隐藏;
    // auth 关闭(scaffold_is_admin 未 share)→ 默认显示(无 role 体系,跟中间件放行一致)。
    if ($scaffold_is_admin ?? true) {
        $configGroups[] = ['key' => 'g_people', 'label' => '人员管理', 'items' => [
            ['key' => 'cfg_accounts', 'label' => '开发人员', 'href' => route('scaffold.accounts'), 'is_active' => $current === '__accounts'],
        ]];
    }
@endphp

<x-scaffold::side-tree :class="$sidebarClass" :groups="$configGroups" :searchable="false" />
