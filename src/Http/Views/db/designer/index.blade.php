{{-- plan-22 P1-S3:layouts.two_columns 兼容层删,直接用 <x-scaffold::shell>
     复用 /db 的单栏 shell;这里不上 sidebar,纯卡片网格 --}}
<x-scaffold::shell title="Scaffold - 数据库设计器" containerClass="is-route">

{{-- 2026-05-23 v3:designer index 不挂 sub-nav,字典从模块下方"其他工具"独立入口跳 --}}

{{--
    Plan 19 数据库设计器 · 模块总览(v9 重排)
    -------------------------------------------------------------
    首屏调整:
      F1 锁定 badge 文案 + 颜色中性化(SCSS 端配套)
      F2 + 新建 schema 卡片接通 Alpine modal(_modal_new_schema partial)
      F3 砍 "prototype mock" hint
      F5 hero 压扁:描述砍 + chip 跟 h2 同行
--}}
<div class="p-route-shell">
    <div class="route-main">
        @if ($designer_locked ?? false)
            {{-- 2026-05-23:生产 / 只读环境顶部红条 banner — 跟 show.blade.php 同款,新建 / 重命名 / 删除 schema 全锁 --}}
            <div class="p-designer-locked-banner" role="status" aria-live="polite">
                <x-scaffold::icon name="warn" :size="14" />
                <strong>{{ ($designer_is_prod ?? false) ? '生产环境' : (($designer_is_readonly ?? false) ? '只读模式' : '无设计权限') }}</strong>
                <span>所有写操作已禁用 — 新建 / 重命名 / 删除 schema。</span>
                <span class="p-designer-locked-banner__hint">{{ ($designer_is_prod ?? false) ? 'APP_ENV=production' : (($designer_is_readonly ?? false) ? 'SCAFFOLD_CONFIG_READONLY=true' : '需 admin 在「开发人员」授权设计数据库') }}</span>
            </div>
        @endif
        <div class="p-db {{ ($designer_locked ?? false) ? 'is-locked' : '' }}" x-data="dbDesigner"
            @if ($designer_locked ?? false) data-locked="true" @endif>
            {{-- v9 F2:Alpine 初始 state JSON(只 newSchema modal 需要;其他字段空 fallback) --}}
            <script type="application/json" data-designer-initial nonce="{{ $cspNonce ?? '' }}">{!! json_encode($designer_initial, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>

            {{-- 2026-05-30:金刚区 bespoke 数据 KPI 横排(替代共享 <x-scaffold::hero card compact>)。
                 user 拍板:砍"数据库设计器"标题(顶部导航已高亮"数据库设计",冗余),
                 4 个大号 mono 数字等分铺满整条,数据为主、霸气。共享 hero 组件不动。 --}}
            <div class="p-designer-hero">
                <x-scaffold::stat-card tone="accent" label="模块" :value="$designer_stats['modules']">
                    <x-slot:icon><x-scaffold::icon name="database" :size="20" /></x-slot:icon>
                </x-scaffold::stat-card>
                <x-scaffold::stat-card tone="accent" label="表" :value="$designer_stats['tables']">
                    <x-slot:icon><x-scaffold::icon name="list" :size="20" /></x-slot:icon>
                </x-scaffold::stat-card>
                <x-scaffold::stat-card tone="accent" label="字段" :value="$designer_stats['fields']">
                    <x-slot:icon><x-scaffold::icon name="code" :size="20" /></x-slot:icon>
                </x-scaffold::stat-card>
                <x-scaffold::stat-card tone="accent" label="模型" :value="$designer_stats['models']">
                    <x-slot:icon><x-scaffold::icon name="settings" :size="20" /></x-slot:icon>
                </x-scaffold::stat-card>
                <x-scaffold::stat-card tone="accent" label="枚举字段" :value="$designer_dict_stats['fields']">
                    <x-slot:icon><x-scaffold::icon name="key" :size="20" /></x-slot:icon>
                </x-scaffold::stat-card>
                <x-scaffold::stat-card tone="accent" label="字典值" :value="$designer_dict_stats['values']">
                    <x-slot:icon><x-scaffold::icon name="wordbook" :size="20" /></x-slot:icon>
                </x-scaffold::stat-card>
                <x-scaffold::stat-card tone="accent" label="迁移" :value="$designer_stats['migrations']">
                    <x-slot:icon><x-scaffold::icon name="refresh" :size="20" /></x-slot:icon>
                </x-scaffold::stat-card>
            </div>

            {{-- 模块卡片网格 --}}
            <section class="p-db__module">
                <div class="p-db__module-head">
                    {{-- v9 update:左侧 h3 + count;右侧主操作 button(挪自原 ghost 卡) --}}
                    <div class="p-db__module-head__group">
                        <h3 class="section-title-with-icon">
                            <span class="section-icon-box" aria-hidden="true">
                                <x-scaffold::icon name="database" :size="16" />
                            </span>
                            模块
                        </h3>
                        {{-- 2026-05-24 二轮 audit C6:section header `N 个` 重复 hero meta `N 模块`,砍 inline meta --}}
                    </div>
                    <x-scaffold::btn variant="primary" size="sm" x-on:click="openNewSchema">
                        + 新建 schema
                    </x-scaffold::btn>
                </div>

                {{-- plan-53 出身分块:仅 host(单块)时不渲染块标题,视觉与旧版一致;有扩展包时 host / 各包各一块 --}}
                @php $originGroups = $designer_module_groups ?? ['' => ['origin' => null, 'label' => '宿主项目', 'writable' => true, 'modules' => $designer_modules]]; @endphp
                @foreach ($originGroups as $group)
                @if (count($originGroups) > 1)
                    <h4 class="p-designer-grid__group-title">
                        <x-scaffold::icon :name="$group['origin'] === null ? 'database' : 'package'" :size="13" />
                        {{ $group['label'] }}
                        @if ($group['origin'] !== null)
                            <span class="p-designer-grid__group-tag">扩展包{{ $group['writable'] ? ' · 软链直写' : ' · 只读(vendor 拷贝)' }}</span>
                        @endif
                    </h4>
                @endif
                <div class="p-designer-grid">
                    @foreach ($group['modules'] as $key => $mod)
                        <a href="{{ route('db.designer.show', ['schema' => $key]) }}"
                           data-schema-key="{{ $key }}"
                           class="p-designer-card {{ $mod['locked'] ? 'is-locked' : '' }}">
                            {{-- 2026-05-26 R-8:已生成 = 常态(17 模块中绝大多数),chip 信息冗余 →
                                                  改 lock icon 微提示(锁定态语义还在,但不抢卡片视觉);
                                                  草稿才是值得 highlight 的状态(用户在意"还没 ship 的"),保留 badge --}}
                            <div class="p-designer-card__head">
                                <h4>
                                    <x-scaffold::icon name="database" :size="14" />
                                    {{ $mod['folder'] }}
                                    @if ($mod['locked'])
                                        <span class="p-designer-card__lock-hint" title="migration 已生成 · 部分字段不可改名">
                                            <x-scaffold::icon name="lock" :size="11" />
                                        </span>
                                    @else
                                        {{-- 草稿 badge 紧贴模块名后,跟"已生成"右侧布局区分 --}}
                                        <span class="p-designer-card__badge p-designer-card__badge--draft" title="未生成 migration · 可自由编辑">草稿</span>
                                    @endif
                                </h4>
                                @if (! $mod['locked'])
                                    {{-- 草稿态 ⋯ 菜单:重命名 / 删除(锁定态卡片不挂,后端 isSchemaDraft 双 guard) --}}
                                    <div class="p-designer-card__menu">
                                        <button type="button"
                                            class="p-designer-card__menu-btn"
                                            data-schema="{{ $key }}"
                                            x-on:click.stop.prevent="toggleSchemaMenu"
                                            title="更多操作"
                                            aria-label="更多操作"
                                        >⋯</button>
                                        <div class="p-designer-card__menu-popover"
                                            style="display:none"
                                            role="menu"
                                        >
                                            <button type="button" role="menuitem"
                                                data-schema="{{ $key }}"
                                                x-on:click.stop.prevent="openRenameSchema"
                                            >重命名</button>
                                            <button type="button" role="menuitem"
                                                class="is-danger"
                                                data-schema="{{ $key }}"
                                                x-on:click.stop.prevent="openDeleteSchema"
                                            >删除</button>
                                        </div>
                                    </div>
                                @endif
                            </div>
                            {{-- 2026-05-26 R-6:desc 独立 / stats 独立 / migration 时间独立,三行清爽不挤
                                 R-5:last_migration `Y-m-d H:i:s` 截前 10 位日期,hover title 留完整 --}}
                            <p class="p-designer-card__desc">{{ $mod['name'] }}{{ ! empty($mod['desc']) ? ' · '.$mod['desc'] : '' }}</p>
                            <div class="p-designer-card__meta">
                                <span><strong>{{ $mod['tables_count'] }}</strong> 表</span>
                                <span><strong>{{ $mod['fields_count'] }}</strong> 字段</span>
                            </div>
                            <div class="p-designer-card__migration" @if (! empty($mod['last_migration'])) title="{{ $mod['last_migration'] }}" @endif>
                                {{ ! empty($mod['last_migration']) ? substr($mod['last_migration'], 0, 10) : '未生成 migration' }}
                            </div>
                        </a>
                    @endforeach
                </div>
                @endforeach

                {{-- v9 update:0 模块时显示 ghost onboarding 卡(避免首屏纯空白);非 0 时主入口在右上 button --}}
                @if (count($designer_modules) === 0)
                <div class="p-designer-grid">
                    <button type="button"
                            class="p-designer-card p-designer-card--ghost p-designer-card--action"
                            x-on:click="openNewSchema"
                            title="新建一个模块(scaffold/database/<Module>.yaml)">
                        <div class="p-designer-card__head">
                            <h4>
                                <x-scaffold::icon name="plus" :size="14" />
                                新建第一个 schema
                            </h4>
                        </div>
                        <p class="p-designer-card__desc">
                            点击创建你的第一个模块，以开始 designer 工作流
                        </p>
                    </button>
                </div>
                @endif

                {{-- 2026-06-22:数据字典入口移到「数据库文档」(只读 hub)—— 字典是「看」不是「改」,
                                 归到 db.docs aside 底部更自洽;designer 落地页回归纯 schema 管理 --}}
            </section>

            {{-- v9 F2:newSchema modal 在整页 x-data 内 --}}
            @include('scaffold::db.designer._modal_new_schema')
            @include('scaffold::db.designer._modal_rename_schema')
            @include('scaffold::db.designer._modal_delete_schema')
            {{-- 点击空白关 ⋯ 菜单 --}}
            <div x-on:click.window="closeSchemaMenu" x-on:keydown.escape.window="closeSchemaMenu" style="display:none"></div>
        </div>
    </div>
</div>

{{-- v9 F2:首屏也需要 dbDesigner Alpine 组件(为了 + 新建 schema modal) --}}
<x-slot:scripts>
    <script src="/vendor/scaffold/javascript/designer.js?v={{ @filemtime(public_path('vendor/scaffold/javascript/designer.js')) ?: time() }}"></script>
</x-slot:scripts>
</x-scaffold::shell>
