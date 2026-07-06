{{-- plan-22 P1-S3:layouts.two_columns 兼容层删,直接用 <x-scaffold::shell> --}}
<x-scaffold::shell title="Scaffold - 概览">
<div class="dashboard-page">
    <div class="dashboard-layout">
        <aside class="dashboard-sidebar dashboard-shell">
            <div class="dashboard-commands-wrap">
                <div class="dashboard-commands-header">
                    {{-- 2026-05-30:配图标 — 复用全站 section-icon-box(软橙底 + 橙图标),code 图标对应"指令" --}}
                    <span class="section-icon-box" aria-hidden="true"><x-scaffold::icon name="code" :size="16" /></span>
                    <span class="dashboard-commands-header__label">指令速览</span>
                </div>
                <x-scaffold::panel class="dashboard-commands">
                    {{-- 2026-06-18 plan-A:一键命令速览上移到主栏 hero(见 .dashboard-main 顶部),
                         侧栏只留分步详解,避免命令重复出现两处。 --}}
                    @foreach ($command_guides as $group)
                        <div class="dashboard-command-group-title">
                            <span>{{ $group['name'] }}</span>
                            @if (! empty($group['hint']))
                                <em>{{ $group['hint'] }}</em>
                            @endif
                        </div>
                        <ol class="dashboard-command-list">
                            @foreach ($group['items'] as $item)
                            <li class="dashboard-command-item">
                                <div class="dashboard-command-head">
                                    <div class="dashboard-command-step">{{ $item['step'] }}</div>
                                    <div class="dashboard-command-body">
                                        <strong class="dashboard-command-title">{{ $item['title'] }}</strong>
                                        <p class="dashboard-command-desc">{{ $item['desc'] }}</p>
                                    </div>
                                </div>
                                <div class="dashboard-command-detail">
                                    <div class="dashboard-command-box">
                                        <code>{{ $item['command'] }}</code>
                                        <code class="is-example">{{ $item['example'] }}</code>
                                    </div>
                                </div>
                            </li>
                            @endforeach
                        </ol>
                    @endforeach
                </x-scaffold::panel>
            </div>
        </aside>

        <div class="dashboard-main">
            {{-- 2026-06-18 plan-A「流水线即主角」:主栏顶部身份 hero —— 把 schema→生成 这条工具 thesis
                 摆到最显眼处(气势集中:brand pill + 流水线 signature),命令速览从侧栏上移于此;
                 下方金刚区随之降为「佐证体量」。流水线节点 = 一份 YAML 铺出的产物链(migration→model→
                 controller→request→resource→auth→api;request 实际随 controller 步一起生成,这里单列出来强调
                 它也是产物;fresh/i18n 等纯过程步不进 hero,完整分步命令在侧栏)。 --}}
            <section class="dashboard-hero">
                <div class="dashboard-hero__lead">
                    {{-- 2026-06-19:eyebrow pill 与标题同排(原上下两行合一行) --}}
                    <div class="dashboard-hero__titlerow">
                        <span class="dashboard-command-tag">{{ $command_shortcut['title'] }}</span>
                        <h1 class="dashboard-hero__title">Schema 驱动代码生成</h1>
                    </div>
                    <p class="dashboard-hero__sub">一份 YAML，铺一套 Laravel CRUD —— 整套生成一条命令搞定，再按需单步。</p>
                </div>

                <ol class="dashboard-hero__pipeline" aria-label="代码生成流水线">
                    <li class="dashboard-hero__node"><span class="dashboard-hero__stage is-input">YAML</span></li>
                    <li class="dashboard-hero__node"><span class="dashboard-hero__stage">migration</span></li>
                    <li class="dashboard-hero__node"><span class="dashboard-hero__stage">model</span></li>
                    <li class="dashboard-hero__node"><span class="dashboard-hero__stage">controller</span></li>
                    <li class="dashboard-hero__node"><span class="dashboard-hero__stage">request</span></li>
                    <li class="dashboard-hero__node"><span class="dashboard-hero__stage">resource</span></li>
                    <li class="dashboard-hero__node"><span class="dashboard-hero__stage">auth</span></li>
                    <li class="dashboard-hero__node"><span class="dashboard-hero__stage">api</span></li>
                </ol>

                <div class="dashboard-command-box dashboard-hero__command">
                    <code>{{ $command_shortcut['command'] }}</code>
                    <code class="is-example">{{ $command_shortcut['example'] }}</code>
                </div>
            </section>

            {{-- 2026-06-04 dashboard:Runtime 大卡 + 最近异常面板随云端化移除(查看统一在 moo-scaffold-cloud);
                 金刚区改为业务体量统一统计网格(数据表 / 控制器 / 应用 / 接口 / 模块),等宽升格卡片。
                 历史:用户拒掉 inline-meta 单调方案 — 首页是门面,需要气势,故用渐变 + 阴影大卡。 --}}
            @php
                $statIconMap = [
                    '数据表' => 'database',
                    '控制器' => 'code',
                    '应用'   => 'shield',
                    '接口'   => 'send',
                    '模块'   => 'list',
                ];
            @endphp

            <div class="dashboard-kingkong">
                @foreach ($stats as $item)
                    @php
                        $label = (string) $item['label'];
                        $iconName = 'list';
                        foreach ($statIconMap as $needle => $svg) {
                            if (str_contains($label, $needle)) { $iconName = $svg; break; }
                        }
                    @endphp
                    <div class="dashboard-stat-card">
                        <span class="dashboard-stat-icon" aria-hidden="true">
                            <x-scaffold::icon :name="$iconName" :size="20" />
                        </span>
                        <strong class="dashboard-stat-value">{{ number_format((int) $item['value']) }}</strong>
                        <span class="dashboard-stat-label">{{ $label }}</span>
                    </div>
                @endforeach
            </div>

            {{-- 2026-06-05 云端汇聚:本项目运行时健康度从 moo-scaffold-cloud 回拉(本地仅瞬时缓冲、todos 本地根本没有)。
                 只在已接入(enabled + URL + token)时出现;拉取失败 / 未接入都不拖垮首页 —— 见 ScaffoldController::getCloudPanelData。 --}}
            @if ($cloud_configured)
                @php
                    $s          = $cloud_summary['stats'] ?? [];
                    $todoOpen   = (int) ($s['todos']['open'] ?? 0) + (int) ($s['todos']['in_progress'] ?? 0);
                    $runtimeUrl = $cloud_console_url ? $cloud_console_url . '/runtimes' : null;
                    $slowUrl    = $cloud_console_url ? $cloud_console_url . '/slow-queries' : null;
                    $todoUrl    = $cloud_console_url ? $cloud_console_url . '/todos' : null;

                    $statusTone = ['open' => 'warning', 'in_progress' => 'info', 'resolved' => 'success', 'done' => 'success'];
                    $statusText = ['open' => '未处理', 'in_progress' => '处理中', 'resolved' => '已解决', 'done' => '已完成'];
                    $prioTone   = ['high' => 'danger', 'normal' => 'neutral', 'low' => 'info'];
                    $prioText   = ['high' => '高', 'normal' => '中', 'low' => '低'];
                    // recent 行数据来自外部云端,形状可漂移(字段缺失/坏时间串);裸取 key 会
                    // ErrorException 500 且坏 payload 被缓存 60s → 首页连环炸,违反「云端绝不
                    // 拖垮首页」的不变量 —— 下方渲染一律 ?? 兜底,时间解析包 try(2026-06-10 修)
                    $fmtTime = function ($iso) {
                        try {
                            return $iso ? \Illuminate\Support\Carbon::parse($iso)->format('m-d H:i') : '—';
                        } catch (\Throwable) {
                            return '—';
                        }
                    };
                @endphp

                <x-scaffold::panel class="dashboard-cloud">
                    <x-slot:hd>
                        <div class="dashboard-cloud-hd">
                            <h2 class="section-title-with-icon">
                                <span class="section-icon-box" aria-hidden="true"><x-scaffold::icon name="cloud" :size="18" /></span>
                                S-Cloud 云端汇聚
                            </h2>
                            @if ($cloud_console_url)
                                <a href="{{ $cloud_console_url }}" target="_blank" rel="noopener noreferrer" class="dashboard-cloud-console">
                                    进入 Moo Scaffold Cloud <x-scaffold::icon name="external-link" :size="13" />
                                </a>
                            @endif
                        </div>
                    </x-slot:hd>

                    @if (! $cloud_summary)
                        <x-scaffold::empty compact title="云端暂不可达" desc="稍后自动重试 · 记录查看统一在 Moo Scaffold Cloud。">
                            <x-slot:icon><x-scaffold::icon name="cloud" :size="24" /></x-slot:icon>
                        </x-scaffold::empty>
                    @else
                        <div class="dashboard-cloud-stats">
                            <a class="dashboard-cloud-tile" @if ($runtimeUrl) href="{{ $runtimeUrl }}" target="_blank" rel="noopener noreferrer" @endif>
                                <span class="dashboard-cloud-tile__icon"><x-scaffold::icon name="warn" :size="18" /></span>
                                <span class="dashboard-cloud-tile__body">
                                    <strong class="dashboard-cloud-tile__value">{{ number_format((int) ($s['runtimes']['open'] ?? 0)) }}</strong>
                                    <span class="dashboard-cloud-tile__label">未处理异常</span>
                                </span>
                                <span class="dashboard-cloud-tile__sub">共 {{ number_format((int) ($s['runtimes']['total'] ?? 0)) }}</span>
                            </a>
                            <a class="dashboard-cloud-tile" @if ($slowUrl) href="{{ $slowUrl }}" target="_blank" rel="noopener noreferrer" @endif>
                                <span class="dashboard-cloud-tile__icon"><x-scaffold::icon name="database" :size="18" /></span>
                                <span class="dashboard-cloud-tile__body">
                                    <strong class="dashboard-cloud-tile__value">{{ number_format((int) ($s['slow_queries']['open'] ?? 0)) }}</strong>
                                    <span class="dashboard-cloud-tile__label">未处理慢 SQL</span>
                                </span>
                                <span class="dashboard-cloud-tile__sub">共 {{ number_format((int) ($s['slow_queries']['total'] ?? 0)) }}</span>
                            </a>
                            <a class="dashboard-cloud-tile" @if ($todoUrl) href="{{ $todoUrl }}" target="_blank" rel="noopener noreferrer" @endif>
                                <span class="dashboard-cloud-tile__icon"><x-scaffold::icon name="inbox" :size="18" /></span>
                                <span class="dashboard-cloud-tile__body">
                                    <strong class="dashboard-cloud-tile__value">{{ number_format($todoOpen) }}</strong>
                                    <span class="dashboard-cloud-tile__label">未完成待办</span>
                                </span>
                                <span class="dashboard-cloud-tile__sub">共 {{ number_format((int) ($s['todos']['total'] ?? 0)) }}</span>
                            </a>
                        </div>

                        <div class="dashboard-cloud-recent">
                            <section class="dashboard-cloud-col">
                                <header class="dashboard-cloud-col__hd"><span class="section-icon-box" aria-hidden="true"><x-scaffold::icon name="warn" :size="16" /></span>最近异常</header>
                                @forelse (($cloud_summary['recent']['runtimes'] ?? []) as $r)
                                    <a class="dashboard-cloud-row" @if ($runtimeUrl) href="{{ $runtimeUrl }}" target="_blank" rel="noopener noreferrer" @endif>
                                        <span class="dashboard-cloud-row__main">
                                            <code class="dashboard-cloud-row__title">{{ ($r['exc_class'] ?? '') ?: '—' }}</code>
                                            <span class="dashboard-cloud-row__desc">{{ $r['exc_message'] ?? '' }}</span>
                                        </span>
                                        <span class="dashboard-cloud-row__meta">
                                            <x-scaffold::badge :tone="$statusTone[$r['status'] ?? ''] ?? 'neutral'" size="sm">{{ $statusText[$r['status'] ?? ''] ?? ($r['status'] ?? '—') }}</x-scaffold::badge>
                                            <span class="dashboard-cloud-row__num">×{{ number_format((int) ($r['count'] ?? 0)) }}</span>
                                            <span class="dashboard-cloud-row__time">{{ $fmtTime($r['last_seen'] ?? null) }}</span>
                                        </span>
                                    </a>
                                @empty
                                    <p class="dashboard-cloud-row-empty">暂无异常 🎉</p>
                                @endforelse
                            </section>

                            <section class="dashboard-cloud-col">
                                <header class="dashboard-cloud-col__hd"><span class="section-icon-box" aria-hidden="true"><x-scaffold::icon name="inbox" :size="16" /></span>最近待办</header>
                                @forelse (($cloud_summary['recent']['todos'] ?? []) as $t)
                                    <a class="dashboard-cloud-row" @if ($todoUrl) href="{{ $todoUrl }}" target="_blank" rel="noopener noreferrer" @endif>
                                        <span class="dashboard-cloud-row__main">
                                            <span class="dashboard-cloud-row__title is-text">{{ ($t['title'] ?? '') ?: '—' }}</span>
                                        </span>
                                        <span class="dashboard-cloud-row__meta">
                                            <x-scaffold::badge :tone="$prioTone[$t['priority'] ?? ''] ?? 'neutral'" size="sm">{{ $prioText[$t['priority'] ?? ''] ?? ($t['priority'] ?? '—') }}</x-scaffold::badge>
                                            <x-scaffold::badge :tone="$statusTone[$t['status'] ?? ''] ?? 'neutral'" size="sm">{{ $statusText[$t['status'] ?? ''] ?? ($t['status'] ?? '—') }}</x-scaffold::badge>
                                            <span class="dashboard-cloud-row__time">{{ $fmtTime($t['created_at'] ?? null) }}</span>
                                        </span>
                                    </a>
                                @empty
                                    <p class="dashboard-cloud-row-empty">暂无待办</p>
                                @endforelse
                            </section>
                        </div>
                    @endif
                </x-scaffold::panel>
            @endif

            <x-scaffold::panel class="dashboard-history">
                <x-slot:hd>
                    <h2 class="section-title-with-icon">
                        <span class="section-icon-box" aria-hidden="true">
                            <x-scaffold::icon name="inbox" :size="18" />
                        </span>
                        接口发布历史
                    </h2>
                </x-slot:hd>
                    @if (empty($publish_history_groups))
                        <x-scaffold::empty
                            title="还没有接口发布记录"
                            desc="执行一次 `php artisan moo:api api Light` 后，这里会展示最近发布的接口历史。"
                        >
                            <x-slot:icon><x-scaffold::icon name="inbox" :size="24" /></x-slot:icon>
                        </x-scaffold::empty>
                    @else
                        @php
                            $activeGroupKey = $active_publish_history_group['key'] ?? null;
                            $activeGroup = $active_publish_history_group;
                        @endphp
                        <nav class="dashboard-history-tabs" aria-label="发布历史按应用切换">
                            @foreach ($publish_history_groups as $group)
                                @php
                                    $tabQuery = request()->query();
                                    $tabQuery['history_app'] = $group['key'];
                                    unset($tabQuery['history_page']);
                                    $isActiveTab = $group['key'] === $activeGroupKey;
                                @endphp
                                <a
                                    href="{{ route('scaffold.home', $tabQuery, false) }}"
                                    class="dashboard-history-tab{{ $isActiveTab ? ' is-active' : '' }}"
                                    @if ($isActiveTab) aria-current="page" @endif
                                    aria-label="{{ $group['name'] }}，{{ $group['count'] }} 条记录"
                                >
                                    <span>{{ $group['name'] }}</span>
                                    <em>{{ $group['count'] }}</em>
                                </a>
                            @endforeach
                        </nav>

                        @if (! empty($activeGroup))
                        <div class="dashboard-history-pane is-active" data-pane="{{ $activeGroup['pane_key'] }}">
                            <ol class="dashboard-history-list">
                            @foreach ($activeGroup['items'] as $item)
                            <li class="dashboard-history-item">
                                <div class="dashboard-history-head">
                                    <div class="dashboard-history-head-main">
                                        <span class="dashboard-history-time">{{ $item['published_at'] }}</span>
                                        <strong>{{ $item['app_name'] }} / {{ $item['namespace'] }}</strong>
                                    </div>
                                    <small class="dashboard-history-summary">
                                        @if (! empty($item['author']))
                                            <span>作者：{{ $item['author'] }}</span>
                                        @endif
                                        <span>{{ $item['controller_count'] }} 控制器 · {{ $item['action_count'] }} 接口</span>
                                        @if (! empty($item['operations']))
                                            @foreach ($item['operations'] as $operation)
                                                <span class="dashboard-history-badge dashboard-history-badge-{{ $operation['key'] }}">
                                                    {{ $operation['label'] }} {{ $operation['count'] }}
                                                </span>
                                            @endforeach
                                        @endif
                                    </small>
                                </div>
                                <div class="dashboard-history-actions">
                                    @foreach ($item['items'] as $action)
                                        @if (! empty($action['debug_url']))
                                            <a href="{{ $action['debug_url'] }}" target="debug_history" class="dashboard-history-action-link">
                                                <span class="dashboard-history-action">
                                                    {{ $action['name'] ?? $action['action_key'] ?? $action['action'] ?? '-' }}
                                                    <code>{{ strtoupper($action['method'] ?? 'GET') }} {{ $action['uri'] ?? '' }}</code>
                                                </span>
                                            </a>
                                        @else
                                            <span class="dashboard-history-action">
                                                {{ $action['name'] ?? $action['action_key'] ?? $action['action'] ?? '-' }}
                                                <code>{{ strtoupper($action['method'] ?? 'GET') }} {{ $action['uri'] ?? '' }}</code>
                                            </span>
                                        @endif
                                    @endforeach
                                </div>
                                <div class="dashboard-history-file">{{ $item['relative_path'] }}</div>
                            </li>
                            @endforeach
                            </ol>

                            @if ($publish_history_paginator && $publish_history_paginator->hasPages())
                                @php
                                    $currentPage = $publish_history_paginator->currentPage();
                                    $lastPage = $publish_history_paginator->lastPage();
                                    $startPage = max(1, $currentPage - 2);
                                    $endPage = min($lastPage, $currentPage + 2);
                                @endphp
                                <div class="dashboard-history-pagination">
                                    <div class="dashboard-history-pagination-meta">
                                        当前 app 共 {{ $publish_history_paginator->total() }} 条记录，每页 10 条
                                    </div>
                                    <div class="dashboard-history-pagination-links">
                                        @if ($publish_history_paginator->onFirstPage())
                                            <span class="dashboard-history-page is-disabled">上一页</span>
                                        @else
                                            <a href="{{ $publish_history_paginator->previousPageUrl() }}" class="dashboard-history-page">上一页</a>
                                        @endif

                                        @if ($startPage > 1)
                                            <a href="{{ $publish_history_paginator->url(1) }}" class="dashboard-history-page{{ $currentPage === 1 ? ' is-active' : '' }}">1</a>
                                            @if ($startPage > 2)
                                                <span class="dashboard-history-page is-gap">...</span>
                                            @endif
                                        @endif

                                        @for ($page = $startPage; $page <= $endPage; $page++)
                                            <a href="{{ $publish_history_paginator->url($page) }}" class="dashboard-history-page{{ $page === $currentPage ? ' is-active' : '' }}">{{ $page }}</a>
                                        @endfor

                                        @if ($endPage < $lastPage)
                                            @if ($endPage < $lastPage - 1)
                                                <span class="dashboard-history-page is-gap">...</span>
                                            @endif
                                            <a href="{{ $publish_history_paginator->url($lastPage) }}" class="dashboard-history-page{{ $currentPage === $lastPage ? ' is-active' : '' }}">{{ $lastPage }}</a>
                                        @endif

                                        @if ($publish_history_paginator->hasMorePages())
                                            <a href="{{ $publish_history_paginator->nextPageUrl() }}" class="dashboard-history-page">下一页</a>
                                        @else
                                            <span class="dashboard-history-page is-disabled">下一页</span>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </div>
                        @endif
                    @endif
            </x-scaffold::panel>
        </div>
    </div>
</div>

{{-- plan-22 T9: inline <style> 已外迁到 public/sass/7-pages/_dashboard.scss --}}
</x-scaffold::shell>
