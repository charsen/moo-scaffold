@php
    $shellTitle = 'Scaffold - 数据库设计器 · ' . ($schema ?? 'User');
@endphp

{{-- plan-22 P1-S3:layouts.two_columns 兼容层删,直接用 <x-scaffold::shell> --}}
<x-scaffold::shell :title="$shellTitle" containerClass="is-route">

{{-- 2026-05-24 二轮 audit C7:sub-nav 整条砍 — 跟 dictionaries 一致(2026-05-24 也砍了),返回链接挪到
     左侧 .p-designer-col-sidebar__schema 顶部跟 schema 名同区域。原 sub-nav 只挂一个"返回"链占 48px
     空 bar 太亏。同时 _subnav.blade.php 0 caller,可整 file 删。 --}}

{{--
    数据库设计器 · 三栏设计页(Plan 19)
    -------------------------------------------------------------
    Alpine 'dbDesigner' 组件管所有交互(定义在 alpine-init.js)。
    初始 state 通过 <script type="application/json" data-designer-initial> 注入(CSP 友好)。
    save 流程:fields 行内编辑 → 500ms debounced POST /save → toast。
    CSP build Alpine 写法守则详见 alpine-init.js 顶部。
--}}

@php
    // F29:字段前缀从 yaml.tables.<>.attrs.prefix 读(持久化),而非每次空字符串
    // F30:把 yaml.table.index 拆 single vs multi,multi 部分(fields 多字段)传到 client 让 GUI 管理
    $_idxList = (array) ($designer_current_table['index'] ?? []);
    $_multiIndexes = [];
    foreach ($_idxList as $_idxName => $_idx) {
        $_f = is_array($_idx) ? ($_idx['fields'] ?? null) : null;
        $_fieldsArr = is_string($_f) ? [$_f] : (is_array($_f) ? $_f : []);
        if (count($_fieldsArr) < 2) continue;     // 单字段走字段表 index column,跳过
        $_multiIndexes[] = [
            '__rowId'    => $_idxName,
            'name'       => (string) $_idxName,
            'type'       => $_idx['type'] ?? 'index',
            'fields_str' => implode(', ', array_map('strval', $_fieldsArr)),
        ];
    }
    // F36 enums init shape:[{field, items: [{__rowId, key, value, label_en, label_zh}]}]
    // 2026-05-21 bug fix:__rowId 用 (field + row idx) 当唯一锚,避免 pending(key='')
    // 多行碰撞撞 Alpine :key 不渲染 tbody。
    $_enumGroups = [];
    foreach ((array) ($designer_enums ?? []) as $_eField => $_eRows) {
        $_items = [];
        foreach (array_values((array) $_eRows) as $_idx => $_r) {
            $_items[] = [
                '__rowId'  => $_eField.':r'.$_idx,
                'key'      => (string) ($_r['key'] ?? ''),
                'value'    => $_r['value'] ?? '',
                'label_en' => (string) ($_r['label_en'] ?? ''),
                'label_zh' => (string) ($_r['label_zh'] ?? ''),
            ];
        }
        $_enumGroups[] = ['field' => (string) $_eField, 'items' => $_items];
    }
    // plan 19 v11:Model / Controller / Resource 初始值传给前端
    $_ctrl = $designer_current_table['controller'] ?? [];
    $designer_initial = [
        'fields' => $designer_fields,
        'preview' => $designer_preview,
        'multiIndexes' => $_multiIndexes,
        'enumGroups' => $_enumGroups,
        'tablePrefix' => $designer_current_table['prefix'] ?? '',
        'tableName' => $designer_current_table['name'] ?? '',
        'tableDesc' => $designer_current_table['desc'] ?? '',
        'tableModelClass'    => $designer_current_table['model']['class'] ?? '',
        'tableCtrlClass'     => $_ctrl['class'] ?? '',
        'tableCtrlApps'      => is_array($_ctrl['app'] ?? null) ? $_ctrl['app'] : ($_ctrl['app'] ?? null ? [(string) $_ctrl['app']] : []),
        'tableCtrlResources' => is_array($_ctrl['resource'] ?? null) ? $_ctrl['resource'] : ($_ctrl['resource'] ?? null ? [(string) $_ctrl['resource']] : []),
        // v11:所有可能的 app keys,前端预算 chip class map 时遍历用(避免 CSP build 对 undefined 属性 warn)
        'allApps'            => array_keys($designer_controller_apps ?? []),
        'schema' => $schema ?? '',
        'tableKey' => $designer_current_table_key ?? '',
        'csrfToken' => csrf_token(),
        'saveEndpoint' => $designer_current_table_key ? route('db.designer.save', ['schema' => $schema]) : '',
        'translateEndpoint' => route('db.designer.translate'),
        'previewEndpoint' => route('db.designer.preview', ['schema' => $schema]),
        'migrateEndpoint' => route('db.designer.migrate', ['schema' => $schema]),
        'createTableEndpoint' => route('db.designer.create_table', ['schema' => $schema]),
        // #4:createSchema endpoint
        'createSchemaEndpoint' => route('db.designer.create_schema'),
        // v6.2 round 7:deleteTable endpoint(走 DELETE,只删 yaml 节点)
        'deleteTableEndpoint' => $designer_current_table_key ? route('db.designer.delete_table', ['schema' => $schema, 'table' => $designer_current_table_key]) : '',
        // 表 key 改名 endpoint(按当前表构建;改名走 popover → PUT)
        'renameTableEndpoint' => $designer_current_table_key ? route('db.designer.rename_table', ['schema' => $schema, 'table' => $designer_current_table_key]) : '',
        'migrationContentBase' => route('db.designer.migration_content', ['schema' => $schema]),     // 前端拼 ?file=
        // plan-49:migration 合并 endpoint(dry-run preview + execute 两段式)
        'compactPreviewEndpoint' => route('db.designer.migrations.compact_preview', ['schema' => $schema]),
        'compactExecuteEndpoint' => route('db.designer.migrations.compact_execute', ['schema' => $schema]),
        // 2026-05-21 C 方案:删 migration 文件 endpoint tpl。URL 不带 .php(nginx 会把 .php URL 当静态 PHP 走
        // fastcgi → 404 HTML),sentinel 用 FILENAMESTEM 占位,前端 strip 文件名 .php 后 replace。
        'deleteMigrationEndpointTpl' => route('db.designer.migration.delete', ['schema' => $schema, 'stem' => 'FILENAMESTEM']),
    ];
@endphp

<div class="p-route-shell">
    <div class="route-main">
        @if ($designer_locked ?? false)
            {{-- 2026-05-23:生产 / 只读环境顶部红条 banner — UI 层视觉守护,后端 EnforceScaffoldWritable 是兜底闸
                 plan-53:扩展包 vcs 拷贝(非软链)只读也走这条红条(写权硬线的 UI 层) --}}
            <div class="p-designer-locked-banner" role="status" aria-live="polite">
                <x-scaffold::icon name="warn" :size="14" />
                <strong>{{ ($designer_is_prod ?? false) ? '生产环境' : (($designer_is_readonly ?? false) ? '只读模式' : (($designer_origin_readonly ?? false) ? '扩展包只读' : '无设计权限')) }}</strong>
                <span>所有写操作已禁用 — 保存 / 加表 / 删表 / 改字段 / 生成 migration / 跑 migrate / 合并 migration / AI 翻译。</span>
                <span class="p-designer-locked-banner__hint">{{ ($designer_is_prod ?? false) ? 'APP_ENV=production' : (($designer_is_readonly ?? false) ? 'SCAFFOLD_CONFIG_READONLY=true' : (($designer_origin_readonly ?? false) ? ($designer_origin ?? '') . ' 是 vendor 拷贝,软链(composer path 仓)安装后可编辑' : '需 admin 在「开发人员」授权设计数据库')) }}</span>
            </div>
        @endif
        <div
            class="p-designer"
            x-data="dbDesigner"
            :data-modal-open="_isAnyModalOpen"
            @if ($designer_locked ?? false) data-locked="true" @endif
        >
            {{-- 初始 state JSON 注入（CSP 安全：script tag 带 nonce；浏览器不会 eval） --}}
            <script type="application/json" data-designer-initial nonce="{{ $cspNonce ?? '' }}">{!! json_encode($designer_initial, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>

            {{-- 2026-05-22:.p-designer-header title-group 块整删 — schema 信息进 sidebar 顶部
                 2026-05-26:designer sub-nav 砍后 actions 借 .scaffold-subnav-bar 全宽 fixed bar 视觉(跟 /scaffold/api/* 同款)
                            + 左半补 breadcrumb(schema · table · 字段数)填补空白 --}}
            <div class="scaffold-subnav-bar p-designer-header__actions">
                    {{-- 2026-05-27:返回链从 sidebar 顶部挪到 subnav bar 最左 — sidebar 第一行空间还给表列表,
                                    且导航流(返回 + breadcrumb)统一在二级栏天然成串 --}}
                    <a href="{{ route('db.designer.index') }}"
                       class="p-designer-header__back p-designer-no-lock"
                       title="返回数据库设计模块列表">
                        <x-scaffold::icon name="chevron-left" :size="12" />
                        <span>返回</span>
                    </a>
                    {{-- P0-1 breadcrumb:返回链后跟当前定位,margin-right:auto(在 crumb 上)推开右侧 actions --}}
                    <div class="p-designer-header__crumb">
                        <span class="p-designer-header__crumb-mod">{{ $designer_current_module['folder'] }}</span>
                        @if ($designer_current_table_key)
                            <span class="p-designer-header__crumb-sep">/</span>
                            <span class="p-designer-header__crumb-table">{{ $designer_current_table_key }}</span>
                            <span class="p-designer-header__crumb-count">{{ count($designer_current_table['fields'] ?? []) }} 字段</span>
                        @else
                            <span class="p-designer-header__crumb-hint">未选表</span>
                        @endif
                        @if (! empty($designer_origin) && ! ($designer_locked ?? false))
                            {{-- plan-53:包 schema git 归属提醒 — 用户拍板(2026-07-03)从整行 banner 收进 breadcrumb 行内 chip --}}
                            <span class="p-designer-header__origin-chip" title="此 schema 属于扩展包 {{ $designer_origin }}（软链直写）—— 改动与生成物落在该包仓库，commit 请到 {{ $designer_origin }} 仓提交">
                                <x-scaffold::icon name="package" :size="12" />
                                {{ $designer_origin }} · 改动落包仓,commit 到该仓
                            </span>
                        @endif
                    </div>
                    {{-- v6 持续 saving 状态(替代瞬时 toast,user 滚到任何位置都能看到) --}}
                    <span class="p-designer-header__save-status" :class="saveStatusClass" x-text="saveStatusText"></span>
                    {{-- plan-35 B3:save 失败时给重试按钮,网络抖动场景能快速重发 --}}
                    <button type="button" x-show="isSaveError" x-cloak x-on:click="saveNow" class="p-designer-header__save-retry" title="点击重试保存">重试</button>
                    @if ($designer_current_table_key)
                        {{-- v6.2 round 7:danger 删表按钮(只在选中表时显示) --}}
                        <x-scaffold::btn variant="danger" size="sm" x-on:click="openDeleteTable" title="删除当前表的 yaml 节点">删表</x-scaffold::btn>
                    @endif
                    {{-- 2026-05-26:主路径(保存→生成 migration)前加 spacer 跟次按钮(删表)拉开,视觉层级 --}}
                    <span class="p-designer-header__divider" aria-hidden="true"></span>
                    <x-scaffold::btn variant="secondary" size="sm" x-on:click="saveNow" x-bind:disabled="saving"><span x-show="savingIdle">保存</span><span x-show="saving">保存中…</span></x-scaffold::btn>
                    <x-scaffold::btn variant="primary" size="sm" x-on:click="openPreview">生成 migration</x-scaffold::btn>
            </div>

            {{-- plan-22 修订:用户砍掉表内 quick-nav(字段/索引/枚举/Migration/yaml)一行
                 砍原因:与右侧工作区各 section 标题已有冗余,且 sticky 占空间 --}}

            {{-- plan-22: 二栏 — 左表列表 280 + 右工作区 flex(原左栏"模块列表"已砍,模块切换走 hero 面包屑回 index) --}}
            <div class="p-designer-shell">
                {{-- 中：表列表 --}}
                <aside class="p-designer-col-sidebar p-designer-col-tables" id="designer_tables_sidebar">
                    {{-- 2026-05-22:schema 名顶部块(原 .p-designer-header 砍后挪到此处)
                         2026-05-26 P2-7:模块名 + 中文 desc 一行,表数 + 新建按钮另一行 — 信息密度降低
                         2026-05-27:返回链已挪到 subnav bar 最左,本块只剩 schema 信息 + 表数/新建 --}}
                    <div class="p-designer-col-sidebar__schema">
                        <div class="p-designer-col-sidebar__schema-title">
                            <span class="p-designer-col-sidebar__schema-name">{{ $designer_current_module['folder'] }}</span>
                            <span class="p-designer-col-sidebar__schema-zh">{{ $designer_current_module['name'] }}{{ !empty($designer_current_module['desc']) ? ' · '.$designer_current_module['desc'] : '' }}</span>
                        </div>
                        <div class="p-designer-col-sidebar__schema-row">
                            <span class="p-designer-col-sidebar__schema-count">表 · {{ count($designer_module_tables) }}</span>
                            <button type="button" class="p-designer-col-sidebar__add-btn" x-on:click="openNewTable">+ 新建</button>
                        </div>
                    </div>
                    <ul class="route-sidebar-list p-designer-col-sidebar__list">
                        @foreach ($designer_module_tables as $tKey => $t)
                            @php $isActive = $tKey === $designer_current_table_key; @endphp
                            <li>
                                <a href="{{ route('db.designer.show', ['schema' => $schema ?? 'User']) }}?table={{ $tKey }}"
                                   class="route-sidebar-item is-mono {{ $isActive ? 'active' : '' }}"
                                   title="{{ $t['name'] }}{{ $t['locked'] ? '（migration 已生成）' : '' }}"
                                >
                                    <span class="sidebar-index">{{ $loop->iteration }}.</span>
                                    <span class="sidebar-tname">{{ $tKey }}</span>
                                    <span class="sidebar-count">{{ $t['fields'] }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </aside>

                {{-- 2026-07-09:表列表栏拖拽把手（JS 贴 #designer_tables_sidebar 右沿；var = 卡片宽度，card 模式）--}}
                <div class="side-resizer" role="separator" aria-orientation="vertical"
                     title="拖动调整表列表宽度（双击复位）"
                     data-resize-target="designer_tables_sidebar"
                     data-resize-var="--scaffold-designer-aside-width"
                     data-resize-key="scaffold_designer_aside_width"
                     data-resize-min="240" data-resize-max="560" data-resize-default="330"></div>

                {{-- 右：当前表设计（每块卡片视觉跟左中栏对齐：border + radius + shadow + 标题区） --}}
                <main class="p-designer-col-main">
                @if ($designer_current_table_key)

                    {{-- 1. 基础信息(v6 inline 紧凑布局:3 列同行,描述单独一行) --}}
                    @php
                        $designer_model_class = $designer_current_table['model']['class'] ?? null;
                        $designer_ctrl = $designer_current_table['controller'] ?? null;
                        $designer_ctrl_app = is_array($designer_ctrl['app'] ?? null) ? implode(', ', $designer_ctrl['app']) : ($designer_ctrl['app'] ?? null);
                        $designer_ctrl_class = $designer_ctrl['class'] ?? null;
                    @endphp
                    {{-- plan 19 v10 F10-2:基础信息卡紧凑化 — 200px → 60-80px,3 行(key+name+prefix / desc / Model+Ctrl chip) --}}
                    <section class="p-designer-card-block">
                        <div class="p-designer-card-block__hd"><span>基础信息</span></div>
                        <div class="p-designer-card-block__bd">
                            <div class="p-designer-base-form p-designer-base-form--inline p-designer-base-form--compact">
                                {{-- 行 1:表 key / 显示名 / 字段前缀 --}}
                                <div class="p-designer-base-form__field">
                                    <label for="designer-table-key">
                                        表 key
                                        @unless ($designer_locked ?? false)
                                            {{-- 2026-07-04:migration 锁撤除 —— 有 migration 的表改名走闭环
                                                 (后端自动生成 Schema::rename migration + 迁 snapshot),改名入口常驻;
                                                 走显式 rename popover(避开 autosave 逐字 rename);
                                                 prod/readonly 由 @unless 排除,不渲染入口。 --}}
                                            <button type="button" class="p-designer-base-form__rename-btn"
                                                x-on:click="openRenameTable"
                                                title="{{ $designer_current_table['locked'] ? '重命名表 key（已有 migration，将自动生成 rename migration）' : '重命名表 key' }}">改名</button>
                                        @endunless
                                    </label>
                                    {{-- 表 key 不在此行内编辑(改名走「改名」按钮 → popover);恒只读展示 --}}
                                    <input id="designer-table-key" name="table_key" type="text" autocomplete="off"
                                        value="{{ $designer_current_table['key'] }}"
                                        readonly
                                        class="p-designer-base-form__input is-locked"
                                    />
                                </div>
                                <div class="p-designer-base-form__field">
                                    <label for="designer-table-name">显示名</label>
                                    <input id="designer-table-name" name="table_name" type="text" autocomplete="off"
                                        :value="tableName"
                                        x-on:input="setTableName"
                                        class="p-designer-base-form__input p-designer-base-form__input--free"
                                    />
                                </div>
                                <div class="p-designer-base-form__field">
                                    <label for="designer-table-prefix">字段前缀</label>
                                    <input id="designer-table-prefix" name="table_prefix" type="text" autocomplete="off"
                                        :value="tablePrefix"
                                        x-on:input="setTablePrefix"
                                        placeholder="如 page_（批量加字段时拼接）"
                                        class="p-designer-base-form__input"
                                    />
                                </div>
                                {{-- 行 2:描述 textarea(单行) --}}
                                <div class="p-designer-base-form__field p-designer-base-form__field--full">
                                    <label for="designer-table-desc">描述</label>
                                    <textarea id="designer-table-desc" name="table_desc" rows="1"
                                        x-on:input="setTableDesc"
                                        class="p-designer-base-form__textarea"
                                    >{{ $designer_current_table['desc'] }}</textarea>
                                </div>
                                {{-- plan 19 v11.2:语义配对 — Controller class + apps 一行,Model class + Resource apps 一行 --}}
                                <div class="p-designer-base-form__field p-designer-base-form__field--full p-designer-meta">
                                    {{-- Row 1:Controller class + 生成到哪些 app --}}
                                    <div class="p-designer-meta__row">
                                        <label for="designer-ctrl-class" class="p-designer-meta__label">Controller</label>
                                        <input id="designer-ctrl-class" name="ctrl_class" type="text" autocomplete="off"
                                            :value="tableCtrlClass"
                                            x-on:input="setTableCtrlClass"
                                            placeholder="如 BannerController（空 = 不生成）"
                                            class="p-designer-base-form__input p-designer-meta__input"
                                        />
                                    </div>
                                    <div class="p-designer-meta__row">
                                        <span class="p-designer-meta__label">生成到</span>
                                        <div class="p-designer-toggle-chips">
                                            @foreach ($designer_controller_apps as $appKey => $appInfo)
                                                <button type="button"
                                                        class="p-designer-toggle-chip"
                                                        data-app="{{ $appKey }}"
                                                        x-on:click="toggleTableCtrlApp"
                                                        :class="chipClass.app_{{ $appKey }}"
                                                        title="在 app/{{ ucfirst($appKey) }}/Controllers/ 下生成 Controller">
                                                    {{ $appInfo['label'] }}
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                    {{-- Row 2:Model class + Resource 生成到哪些 app --}}
                                    <div class="p-designer-meta__row">
                                        <label for="designer-model-class" class="p-designer-meta__label">Model</label>
                                        <input id="designer-model-class" name="model_class" type="text" autocomplete="off"
                                            :value="tableModelClass"
                                            x-on:input="setTableModelClass"
                                            placeholder="如 Banner（空 = 不生成）"
                                            class="p-designer-base-form__input p-designer-meta__input"
                                        />
                                    </div>
                                    <div class="p-designer-meta__row">
                                        <span class="p-designer-meta__label">Resource 到</span>
                                        <div class="p-designer-toggle-chips">
                                            @foreach ($designer_controller_apps as $appKey => $appInfo)
                                                <button type="button"
                                                        class="p-designer-toggle-chip"
                                                        data-app="{{ $appKey }}"
                                                        x-on:click="toggleTableCtrlResource"
                                                        :class="chipClass.res_{{ $appKey }}"
                                                        title="在 app/{{ ucfirst($appKey) }}/Resources/ 下生成 Eloquent Resource">
                                                    {{ $appInfo['label'] }}
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                                {{-- 行 4(audit metadata):schema 创建/修改人 + 日期,只读小字;无 stamp 不渲染
                                     2026-05-26:datetime 截前 10 位只留日期,时分秒 hover title 显示完整 --}}
                                @if (! empty($designer_current_table['created_at']))
                                    @php
                                        $designer_audit_created = (string) $designer_current_table['created_at'];
                                        $designer_audit_updated = (string) ($designer_current_table['updated_at'] ?? '');
                                    @endphp
                                    <div class="p-designer-base-form__field p-designer-base-form__field--full p-designer-base-form__audit">
                                        <span title="{{ $designer_audit_created }}">
                                            <span class="p-designer-base-form__audit-label">创建</span>
                                            {{ $designer_current_table['created_by'] ?? '—' }} · {{ substr($designer_audit_created, 0, 10) }}
                                        </span>
                                        @if ($designer_audit_updated !== '')
                                            <span title="{{ $designer_audit_updated }}">
                                                <span class="p-designer-base-form__audit-label">改</span>
                                                {{ $designer_current_table['updated_by'] ?? '—' }} · {{ substr($designer_audit_updated, 0, 10) }}
                                            </span>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </div>
                    </section>

                    {{-- v10 F10-1:「模块/备注」section 已砍 — 模块信息在顶栏面包屑已显示,
                         remark 是 yaml metadata 罕用,无需独立 section。如未来真需要可 inline 进基础信息卡。 --}}

                    {{-- 2. 字段表(v6 --primary modifier:top accent border + 加粗 hd,标主操作区) --}}
                    <section id="sec-fields" class="p-designer-card-block p-designer-card-block--primary">
                        <div class="p-designer-card-block__hd">
                            <span>字段</span>
                            <button type="button" class="p-designer-card-block__hd-btn" x-on:click="openAddField">+ 加字段</button>
                            <button type="button" class="p-designer-card-block__hd-btn" x-on:click="openBatch"
                                :title="batchDraftTitle">
                                + 批量加 (AI)
                                <span x-show="hasBatchDraft" x-cloak class="p-designer-card-block__hd-btn-dot" aria-label="有未完成草稿"></span>
                            </button>
                            {{-- 2026-05-21:DeepSeek 一键字段拼写检查 — 标记疑似 typo,不纠正 --}}
                            <button type="button" class="p-designer-card-block__hd-btn"
                                x-on:click="aiSpellCheckFields" x-bind:disabled="spellChecking"
                                x-text="spellCheckBtnLabel" title="AI 拼写检查所有字段名（只标记 typo，不自动改名）">拼写检查</button>
                            {{-- 2026-06-11 简洁/完整列:精度+format 多数表用不到,默认隐藏;本表用到则 init 自动显示。
                                 切换是显示偏好不是操作,走 yaml 卡同款安静按钮(aux--with-action + copy-btn),不与「加字段」抢眼 --}}
                            <span class="p-designer-card-block__hd-aux p-designer-card-block__hd-aux--with-action">
                                <span title="字段名 / 中文名 / 类型 / 大小 等都可直接点击编辑，无需进 modal">✏️ 行内可编辑</span>
                                <button type="button" class="p-designer-preview__copy-btn" x-on:click="toggleAdvancedCols"
                                    x-text="advColsBtnLabel"
                                    title="精度 / format 两列默认按本表是否用到自动显隐，点击手动切换"></button>
                            </span>
                        </div>
                        <div class="p-designer-card-block__bd">
                            <table class="p-designer-fields">
                                <thead>
                                    <tr>
                                        <th class="col-move"></th>
                                        <th class="col-key">字段名</th>
                                        <th class="col-name">中文名</th>
                                        <th class="col-type">类型</th>
                                        <th class="col-size">大小</th>
                                        <th class="col-precision" x-show="showAdvancedCols" x-cloak title="精度（decimal/double/float 的小数位）">精度</th>
                                        <th class="col-default">默认值</th>
                                        <th class="col-index">索引</th>
                                        <th class="col-null">null</th>
                                        <th class="col-null" title="yaml.unsigned（无符号 int）">±</th>
                                        <th class="col-format" x-show="showAdvancedCols" x-cloak title="自定义 format，如 float:100 让 model 自动整 ↔ 浮点 cast">format</th>
                                        <th class="col-comment">注释</th>
                                        <th class="col-act"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {{-- CSP build:模板里 method call 几乎全拒(:key/:disabled/:readonly/:class/x-show 都不行;
                                         x-on 也不能带 literal+$event 这种 multi-arg)。
                                         做法:① shapeField 预算 row_readonly/name_readonly/index_disabled/row_class/can_remove
                                              ② 每个 attr 一个专属 single-arg setter(setFieldKey/Name/Type/Size/Default/Index/Nullable/Comment)
                                              ③ <tr> 加 :data-rk="f.__rowId",setter 内从 closest('tr').dataset.rk 反查 fields[] 行
                                              ④ :key 用 __rowId(stable session id,改 key 不会触发 DOM remount) --}}
                                    <template x-for="f in fields" :key="f.__rowId">
                                        <tr :class="f.row_class" :data-rk="f.__rowId" :title="f.row_title">
                                            {{-- 行上/下移排序(2026-06-16,放最左列):只在业务字段(can_move)显示;
                                                 系统行(id/时间戳)不出。边界 move_up_disabled / move_down_disabled 置灰。
                                                 CSP build:x-show/:disabled 用单 flag,不能写 !/||/三元。--}}
                                            <td class="col-move">
                                                <button type="button"
                                                    x-show="f.can_move"
                                                    x-on:click="moveFieldUp"
                                                    :disabled="f.move_up_disabled"
                                                    class="p-designer-fields__row-btn p-designer-fields__row-btn--move"
                                                    aria-label="上移字段" title="上移"
                                                >↑</button>
                                                <button type="button"
                                                    x-show="f.can_move"
                                                    x-on:click="moveFieldDown"
                                                    :disabled="f.move_down_disabled"
                                                    class="p-designer-fields__row-btn p-designer-fields__row-btn--move"
                                                    aria-label="下移字段" title="下移"
                                                >↓</button>
                                            </td>
                                            <td>
                                                {{-- v6 批次 D:每个 input 加 name + aria-label(列含义,跨 row 重复 OK)
                                                     2026-05-21:input 前加 strip 前缀按钮(prefix_strippable derived),
                                                     一键去掉 tablePrefix(走 rename 流) --}}
                                                <div class="p-designer-fields__key-cell">
                                                    <button type="button"
                                                        :class="f.prefix_strip_btn_class"
                                                        :disabled="f.prefix_strip_disabled"
                                                        x-on:click="stripFieldPrefix"
                                                        title="剪掉字段前缀（走 renameColumn 保数据）">✂</button>
                                                    <input type="text" name="field_key" aria-label="字段 key" autocomplete="off"
                                                        :value="f.key"
                                                        :readonly="f.name_readonly"
                                                        class="p-designer-fields__input"
                                                        x-on:change="setFieldKey"
                                                        title="改名走 renameColumn 保数据（失焦时触发）"
                                                    />
                                                    {{-- 2026-05-21:AI 拼写检查 warning icon(置 input 后,占位齐宽,只 has_spell_warning 时 visible)
                                                         点 ⚠ → toast 显示完整建议(原生 title hover 兜底) --}}
                                                    <span :class="f.spell_warn_class" :title="f.spell_warning"
                                                          x-on:click="showSpellWarning" role="button" tabindex="-1">⚠</span>
                                                </div>
                                            </td>
                                            <td>
                                                <input type="text" name="field_name" aria-label="字段中文名" autocomplete="off"
                                                    :value="f.name"
                                                    :readonly="f.row_readonly"
                                                    class="p-designer-fields__input p-designer-fields__input--bordered"
                                                    x-on:input="setFieldName"
                                                />
                                            </td>
                                            <td>
                                                <select name="field_type" aria-label="字段类型" :value="f.type"
                                                    :disabled="f.row_readonly"
                                                    class="p-designer-fields__select"
                                                    x-on:change="setFieldType"
                                                >
                                                    @foreach ($designer_type_options as $t)
                                                        <option value="{{ $t }}">{{ $t }}</option>
                                                    @endforeach
                                                </select>
                                            </td>
                                            <td>
                                                <input type="text" name="field_size" aria-label="字段大小" autocomplete="off"
                                                    :value="f.size"
                                                    :readonly="f.row_readonly"
                                                    :class="f.size_class"
                                                    :title="f.size_title"
                                                    class="p-designer-fields__input p-designer-fields__input--bordered"
                                                    x-on:input="setFieldSize"
                                                />
                                            </td>
                                            <td x-show="showAdvancedCols" x-cloak>
                                                <input type="text" name="field_precision" aria-label="字段精度（decimal/double/float 用）" autocomplete="off"
                                                    :value="f.precision"
                                                    :readonly="f.row_readonly"
                                                    :disabled="f.precision_disabled"
                                                    placeholder="—"
                                                    class="p-designer-fields__input p-designer-fields__input--bordered"
                                                    x-on:input="setFieldPrecision"
                                                />
                                            </td>
                                            <td>
                                                {{-- v12.5:有 enum 时用 select(保证值正确性),无 enum 时普通 input --}}
                                                <template x-if="f.has_enum">
                                                    <select name="field_default" aria-label="字段默认值"
                                                        :disabled="f.row_readonly"
                                                        :class="f.default_class"
                                                        :title="f.default_title"
                                                        class="p-designer-fields__select"
                                                        x-on:change="setFieldDefault">
                                                        <option value="" :selected="f.empty_selected"></option>
                                                        <template x-for="opt in f.enum_options" :key="opt.value">
                                                            <option :value="opt.value" :selected="opt.selected" x-text="opt.label"></option>
                                                        </template>
                                                    </select>
                                                </template>
                                                <template x-if="f.no_enum">
                                                    <input type="text" name="field_default" aria-label="字段默认值" autocomplete="off"
                                                        :value="f.default"
                                                        :readonly="f.row_readonly"
                                                        :class="f.default_class"
                                                        :title="f.default_title"
                                                        class="p-designer-fields__input p-designer-fields__input--bordered"
                                                        x-on:input="setFieldDefault"
                                                    />
                                                </template>
                                            </td>
                                            <td>
                                                <select name="field_index" aria-label="字段索引" :value="f.index"
                                                    :disabled="f.index_disabled"
                                                    class="p-designer-fields__select"
                                                    x-on:change="setFieldIndex"
                                                >
                                                    @foreach ($designer_index_options as $ikey => $ilabel)
                                                        <option value="{{ $ikey }}">{{ $ilabel }}</option>
                                                    @endforeach
                                                </select>
                                            </td>
                                            <td class="col-null">
                                                <input type="checkbox" name="field_nullable" aria-label="允许 null"
                                                    :checked="f.nullable"
                                                    :disabled="f.row_readonly"
                                                    x-on:change="setFieldNullable"
                                                />
                                            </td>
                                            <td class="col-null">
                                                <input type="checkbox" name="field_unsigned" aria-label="无符号（unsigned）"
                                                    :checked="f.unsigned"
                                                    :disabled="f.unsigned_disabled"
                                                    x-on:change="setFieldUnsigned"
                                                    title="unsigned（只对 numeric 类型有效）"
                                                />
                                            </td>
                                            <td x-show="showAdvancedCols" x-cloak>
                                                <input type="text" name="field_format" aria-label="字段 format（如 float:100）" autocomplete="off"
                                                    :value="f.format"
                                                    :readonly="f.row_readonly"
                                                    placeholder="—"
                                                    title="自定义 format，如 float:100 让 model 自动整 ↔ 浮点 cast"
                                                    class="p-designer-fields__input p-designer-fields__input--bordered"
                                                    x-on:input="setFieldFormat"
                                                />
                                            </td>
                                            <td class="col-comment">
                                                <input type="text" name="field_comment" aria-label="字段注释" autocomplete="off"
                                                    :value="f.comment"
                                                    :readonly="f.row_readonly"
                                                    class="p-designer-fields__input p-designer-fields__input--bordered"
                                                    x-on:input="setFieldComment"
                                                />
                                            </td>
                                            <td class="col-act">
                                                {{-- 2026-05-23:直接出删除按钮(改名走 key 列行内编辑,失焦自动 rename,
                                                     不需要单独 popover 入口)。`can_remove` 控显隐:id 不可删,
                                                     system timestamps 可删。--}}
                                                <button type="button"
                                                    x-show="f.can_remove"
                                                    x-on:click="removeFieldFromEvent"
                                                    class="p-designer-fields__row-btn p-designer-fields__row-btn--danger"
                                                    aria-label="删除字段"
                                                    title="删除字段"
                                                >×</button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>

                            {{-- 2026-05-23:改名 popover 删除 — 改名走 key 列行内 input 失焦自动
                                 setFieldKey → rename_hint → renameColumn migration,popover 入口冗余。--}}

                        </div>
                    </section>

                    {{-- 2.5 索引(F30 多字段索引 CRUD + 单字段从字段表 index column 派生只读展示)--}}
                    <section id="sec-indexes" class="p-designer-card-block">
                        <div class="p-designer-card-block__hd">
                            <span>索引</span>
                            <button type="button" class="p-designer-card-block__hd-btn" x-on:click="openAddMultiIndex">+ 加多字段</button>
                            <span class="p-designer-card-block__hd-aux">单字段在字段表 index 列改</span>
                        </div>
                        <div class="p-designer-card-block__bd">
                            {{-- plan 19 v11.4:单字段 + 多字段 索引合并 1 个 table
                                 - 单字段行:readonly + .is-readonly 灰色(数据源在字段表 index 列,操作列空)
                                 - 多字段行:可编辑 input + select + × --}}
                            {{-- 2026-05-27:用户反馈索引列拥挤 — 去 compact(还原 base 行高 + cell padding),
                                                类型列 120 → 150 给 badge 多一点呼吸 --}}
                            <x-scaffold::table class="p-designer-indexes">
                                <thead>
                                    <tr>
                                        <th>名称</th>
                                        <th style="width:150px;">类型</th>
                                        <th>字段</th>
                                        <th style="width:80px;">操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {{-- 单字段索引(派生只读,但操作列可 × 删 — 等价改字段表 index 列为 none)
                                         2026-05-26 R-1:类型走 badge,primary/unique-app/unique/index 视觉分色 --}}
                                    <template x-for="s in singleFieldIndexes" :key="s.__rowId">
                                        <tr :data-field="s.field">
                                            <td><code x-text="s.name"></code></td>
                                            <td><span class="badge badge--sm" :class="s.type_badge_class" x-text="s.type"></span></td>
                                            <td><code x-text="s.field"></code></td>
                                            <td>
                                                <button type="button" class="p-designer-fields__row-btn p-designer-fields__row-btn--danger"
                                                        x-on:click="removeSingleIndex"
                                                        :disabled="s.is_primary"
                                                        :title="s.remove_title">×</button>
                                            </td>
                                        </tr>
                                    </template>
                                    {{-- 多字段索引(可编辑) --}}
                                    <template x-for="m in multiIndexes" :key="m.__rowId">
                                        <tr :data-midx="m.__rowId">
                                            <td><code x-text="m.name"></code></td>
                                            <td>
                                                <select name="multi_idx_type" aria-label="多字段索引类型" :value="m.type" x-on:change="setMultiIndexType" class="p-designer-fields__select p-designer-indexes__type-select">
                                                    <option value="unique">unique</option>
                                                    <option value="index">index</option>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="text" name="multi_idx_fields" aria-label="多字段索引字段（逗号分隔）" autocomplete="off"
                                                    :value="m.fields_str" x-on:input="setMultiIndexFields"
                                                    class="p-designer-fields__input p-designer-fields__input--bordered"
                                                    placeholder="如 user_id, created_at" />
                                            </td>
                                            <td>
                                                <button type="button" class="p-designer-fields__row-btn p-designer-fields__row-btn--danger" x-on:click="removeMultiIndex" title="删除">×</button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </x-scaffold::table>
                        </div>
                    </section>

                    {{-- 4. 枚举(F36 CRUD entries / F37 加新 group + 删 group)
                         v12:对齐索引区 v11.4 — 合并 1 张 table,group header 行 + 数据行字段列留空 --}}
                    <section id="sec-enums" class="p-designer-card-block">
                        <div class="p-designer-card-block__hd">
                            <span>枚举</span>
                            <button type="button" class="p-designer-card-block__hd-btn" x-on:click="openAddEnumGroup">+ 加枚举</button>
                            <span class="p-designer-card-block__hd-aux">每行行内编辑；× 删项 / 删组</span>
                        </div>
                        <div class="p-designer-card-block__bd p-designer-card-block__bd--enums">
                            {{-- v12.2:每组 1 个小 table,grid auto-fit 大屏横排 2~3 列 --}}
                            <template x-for="g in enumGroups" :key="g.field">
                                <div class="p-designer-enums-group" :data-egroup="g.field">
                                    <div class="p-designer-enums-group__hd">
                                        <strong x-text="g.field"></strong>
                                        <span class="p-designer-enums-group__count"><span x-text="g.items.length"></span> 项</span>
                                        <button type="button" class="p-designer-enums-group__add-btn" x-on:click="addEnumItem">+ 加项</button>
                                        {{-- 2026-05-21:enum group AI 翻译按钮 — 扫该 group 所有 key='' + label_zh!='' 的 row 一次性翻译 --}}
                                        {{-- 陷阱 #5:CSP build 不支持 !translating 表达式,改用 x-text 单 span + getter --}}
                                        <button type="button" class="p-designer-enums-group__ai-btn" x-on:click="aiTranslateEnumGroup" title="AI 翻译该组所有未翻译的 key（读 中文标签 → 填 key + 英文标签）" x-bind:disabled="translating" x-text="aiBtnLabel">AI 翻译</button>
                                        <button type="button" class="p-designer-fields__row-btn p-designer-enums-group__del-btn" x-on:click="removeEnumGroup" title="删枚举组">×</button>
                                    </div>
                                    <table class="p-designer-enums-group__table">
                                        <thead>
                                            <tr>
                                                <th class="col-key">key</th>
                                                <th class="col-val">值</th>
                                                <th class="col-en">EN</th>
                                                <th class="col-zh">中文</th>
                                                <th class="col-ops"></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <template x-for="r in g.items" :key="r.__rowId">
                                                <tr :data-erk="r.__rowId">
                                                    <td><input type="text" name="enum_key" aria-label="enum key" autocomplete="off" :value="r.key" x-on:input="setEnumKey" class="p-designer-fields__input" placeholder="如 web" /></td>
                                                    <td><input type="text" name="enum_value" aria-label="enum value" autocomplete="off" :value="r.value" x-on:input="setEnumValue" class="p-designer-fields__input" placeholder="如 1" /></td>
                                                    <td><input type="text" name="enum_label_en" aria-label="enum 英文标签" autocomplete="off" :value="r.label_en" x-on:input="setEnumLabelEn" class="p-designer-fields__input" placeholder="如 Web" /></td>
                                                    <td><input type="text" name="enum_label_zh" aria-label="enum 中文标签" autocomplete="off" :value="r.label_zh" x-on:input="setEnumLabelZh" class="p-designer-fields__input" placeholder="如 网站" /></td>
                                                    <td class="col-ops">
                                                        {{-- 2026-05-21:行级 AI 重译 — 不论 key 是否已填,都用 label_zh 重新翻译覆盖 key + label_en --}}
                                                        <button type="button" class="p-designer-fields__row-btn p-designer-fields__row-btn--ai" x-on:click="aiTranslateEnumRow" x-bind:disabled="translating" title="AI 重新翻译此行（用中文标签覆盖 key + 英文标签）">↻</button>
                                                        <button type="button" class="p-designer-fields__row-btn p-designer-fields__row-btn--danger" x-on:click="removeEnumItem" title="删除">×</button>
                                                    </td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </template>
                        </div>
                    </section>

                    {{-- v6 modals 全部抽到 col-main 末尾(原 secondary-grid wrapper 已删除,JS 零引用) --}}

                    {{-- 加多字段索引 modal --}}
                    <div x-show="multiIdxOpen" x-cloak x-on:click="cancelAddMultiIndex"
                         x-on:keydown.escape.window="cancelAddMultiIndex"
                         class="p-designer-rename-popover__backdrop"></div>
                    <div x-show="multiIdxOpen" x-cloak class="p-designer-rename-popover"
                         x-on:keydown.enter.prevent="confirmAddMultiIndex"
                         role="dialog" aria-modal="true">
                        <button type="button" class="p-designer-rename-popover__close" x-on:click="cancelAddMultiIndex" aria-label="关闭">×</button>
                        <h4>加多字段索引</h4>
                        <label for="midx-name">索引名（snake_case）</label>
                        <input id="midx-name" name="multi_idx_name" type="text" autocomplete="off"
                            :value="multiIdxName" x-on:input="setMultiIdxName"
                            placeholder="如 user_status_idx"
                            class="p-designer-rename-popover__input" />
                        <label for="midx-type">类型</label>
                        <select id="midx-type" name="multi_idx_type"
                            :value="multiIdxType" x-on:change="setMultiIdxType"
                            class="p-designer-rename-popover__input">
                            <option value="unique">unique</option>
                            <option value="index">index</option>
                        </select>
                        {{-- v11.7:字段选择改 chip toggle multi-select,无需手输 + 逗号(避免拼错) --}}
                        <label>字段（至少选 2 个）</label>
                        <div class="p-designer-toggle-chips">
                            <template x-for="c in multiIdxFieldChips" :key="c.__rowId">
                                <button type="button"
                                        class="p-designer-toggle-chip"
                                        :data-field="c.field"
                                        x-on:click="toggleMultiIdxField"
                                        :class="c.chip_class">
                                    <span x-text="c.field"></span>
                                </button>
                            </template>
                        </div>
                        <div class="p-designer-rename-popover__actions">
                            <x-scaffold::btn variant="ghost" size="sm" x-on:click="cancelAddMultiIndex">取消</x-scaffold::btn>
                            <x-scaffold::btn variant="primary" size="sm" x-on:click="confirmAddMultiIndex">加</x-scaffold::btn>
                        </div>
                    </div>

                    {{-- 加单字段 modal --}}
                    <div x-show="addFieldOpen" x-cloak x-on:click="cancelAddField"
                         x-on:keydown.escape.window="cancelAddField"
                         class="p-designer-rename-popover__backdrop"></div>
                    <div x-show="addFieldOpen" x-cloak class="p-designer-rename-popover p-designer-rename-popover--md"
                         x-on:keydown.enter.prevent="confirmAddField"
                         role="dialog" aria-modal="true">
                        <button type="button" class="p-designer-rename-popover__close" x-on:click="cancelAddField" aria-label="关闭">×</button>
                        <h4>加字段</h4>
                        {{-- plan 19 v8 D5:有 prefix 时提示;key 已自动预填 prefix,用户继续敲后半段 --}}
                        @if (! empty($designer_current_table['prefix']))
                            <p class="p-designer-rename-popover__prefix-note">
                                当前表 prefix：<code>{{ $designer_current_table['prefix'] }}</code>（已预填到下方 key，继续敲后半段即可）
                            </p>
                        @endif
                        <label for="addfield-key">字段 key(snake_case)</label>
                        <input id="addfield-key" name="add_field_key" type="text" autocomplete="off"
                            :value="addFieldKey" x-on:input="setAddFieldKey"
                            placeholder="如 page_views" class="p-designer-rename-popover__input" />
                        <label for="addfield-name">中文名（可选）</label>
                        <input id="addfield-name" name="add_field_name" type="text" autocomplete="off"
                            :value="addFieldName" x-on:input="setAddFieldName"
                            placeholder="如 浏览数" class="p-designer-rename-popover__input" />
                        {{-- 类型 / 大小 / 默认值 同一行;字段属性扁平化,跟字段表列对齐 --}}
                        <label>类型 · 大小 · 默认值</label>
                        <div class="p-designer-popover__field-row">
                            <select id="addfield-type" name="add_field_type"
                                :value="addFieldType" x-on:change="setAddFieldType"
                                class="p-designer-rename-popover__input p-designer-rename-popover__input--row">
                                @foreach ($designer_type_options as $t)
                                    <option value="{{ $t }}">{{ $t }}</option>
                                @endforeach
                            </select>
                            <input id="addfield-size" name="add_field_size" type="text" autocomplete="off"
                                aria-label="新字段大小"
                                :value="addFieldSize" x-on:input="setAddFieldSize"
                                placeholder="size" class="p-designer-rename-popover__input p-designer-rename-popover__input--row p-designer-rename-popover__input--size" />
                            <input id="addfield-default" name="add_field_default" type="text" autocomplete="off"
                                aria-label="新字段默认值"
                                :value="addFieldDefault" x-on:input="setAddFieldDefault"
                                placeholder="默认值" class="p-designer-rename-popover__input p-designer-rename-popover__input--row" />
                        </div>
                        {{-- null / unsigned 紧跟在字段值属性下面(跟字段表 null/± 列语义一致)
                             2026-05-20 用户反馈:贴太近,加 margin-top 让跟「类型·大小·默认值」行分开 --}}
                        <div class="p-designer-popover__inline-row">
                            <label for="addfield-nullable" class="p-designer-checkbox-inline">
                                <input type="checkbox" id="addfield-nullable" :checked="addFieldNullable" x-on:change="toggleAddFieldNullable" />
                                <span>允许 null</span>
                            </label>
                            <label for="addfield-unsigned" class="p-designer-checkbox-inline">
                                <input type="checkbox" id="addfield-unsigned" :checked="addFieldUnsigned" x-on:change="toggleAddFieldUnsigned" />
                                <span>unsigned</span>
                            </label>
                        </div>
                        <label for="addfield-index">索引</label>
                        <select id="addfield-index" name="add_field_index"
                            :value="addFieldIndex" x-on:change="setAddFieldIndex"
                            class="p-designer-rename-popover__input">
                            @foreach ($designer_index_options as $ikey => $ilabel)
                                <option value="{{ $ikey }}">{{ $ilabel }}</option>
                            @endforeach
                        </select>
                        <label for="addfield-comment">注释（可选）</label>
                        <input id="addfield-comment" name="add_field_comment" type="text" autocomplete="off"
                            :value="addFieldComment" x-on:input="setAddFieldComment"
                            placeholder="字段说明" class="p-designer-rename-popover__input" />
                        {{-- 插入位置:user 选"在 X 之后" --}}
                        <label for="addfield-after">插入位置</label>
                        <select id="addfield-after" name="add_field_after"
                            :value="addFieldAfter" x-on:change="setAddFieldAfter"
                            class="p-designer-rename-popover__input">
                            <template x-for="opt in addFieldAfterOptions" :key="opt.key">
                                <option :value="opt.key" :selected="opt.selected" x-text="opt.label"></option>
                            </template>
                        </select>
                        <div class="p-designer-rename-popover__actions">
                            <x-scaffold::btn variant="ghost" size="sm" x-on:click="cancelAddField">取消</x-scaffold::btn>
                            <x-scaffold::btn variant="primary" size="sm" x-on:click="confirmAddField">加</x-scaffold::btn>
                        </div>
                    </div>

                    {{-- 批量加字段 modal(plan-35 卡 E:textarea modal,Enter 是换行不是提交,by design 不加 Enter handler)--}}
                    <div x-show="batchOpen" x-cloak x-on:click="closeBatch"
                         x-on:keydown.escape.window="closeBatch"
                         class="p-designer-rename-popover__backdrop"></div>
                    <div x-show="batchOpen" x-cloak class="p-designer-rename-popover p-designer-rename-popover--md"
                         role="dialog" aria-modal="true">
                        <button type="button" class="p-designer-rename-popover__close" x-on:click="closeBatch" aria-label="关闭">×</button>
                        <h4>批量加字段（AI 翻译）</h4>
                        <label for="batch-input">中文字段名（逗号或换行分隔）</label>
                        <textarea id="batch-input" name="batch_input"
                            :value="batchInput"
                            x-on:input="setBatchInput" rows="6"
                            placeholder="头像缩略图, 昵称, 手机号&#10;每行或逗号分隔一个字段"
                            class="p-designer-rename-popover__input"></textarea>
                        <p class="is-note">前缀：<span x-text="tablePrefix"></span> · 默认 varchar(64) nullable=false</p>
                        <label class="p-designer-checkbox-inline">
                            <input type="checkbox" :checked="batchLenient" x-on:change="toggleBatchLenient" />
                            <span>宽松翻译</span>
                            <span class="p-designer-rename-popover__hint">（人名/品牌等无字段语义的词，用拼音 fallback 不放弃）</span>
                        </label>
                        <label for="batch-after">插入位置</label>
                        <select id="batch-after" name="batch_after"
                            :value="batchAfter" x-on:change="setBatchAfter"
                            class="p-designer-rename-popover__input">
                            <template x-for="opt in batchAfterOptions" :key="opt.key">
                                <option :value="opt.key" :selected="opt.selected" x-text="opt.label"></option>
                            </template>
                        </select>
                        <div class="p-designer-rename-popover__actions">
                            <x-scaffold::btn variant="ghost" size="sm" x-on:click="closeBatch">取消</x-scaffold::btn>
                            <x-scaffold::btn variant="primary" size="sm" x-on:click="translateAndAdd"
                                x-bind:disabled="translating"
                                x-text="translateBtnLabel">翻译并追加</x-scaffold::btn>
                        </div>
                    </div>

                    {{-- #1 v6.3:翻译结果预览 modal(user 编辑 / 取消每条再追加) --}}
                    <div x-show="translatePreviewOpen" x-cloak x-on:click="cancelTranslatePreview"
                         x-on:keydown.escape.window="cancelTranslatePreview"
                         class="p-designer-rename-popover__backdrop"></div>
                    <div x-show="translatePreviewOpen" x-cloak class="p-designer-rename-popover p-designer-rename-popover--lg"
                         x-on:keydown.enter.prevent="confirmTranslateAppend"
                         role="dialog" aria-modal="true">
                        <button type="button" class="p-designer-rename-popover__close" x-on:click="cancelTranslatePreview" aria-label="关闭">×</button>
                        <h4>翻译结果预览</h4>
                        <p class="is-note">勾选要追加的项，可修改 key / 注释。失败项默认不勾。</p>
                        <table class="p-designer-translate-preview">
                            <thead>
                                <tr>
                                    <th style="width:36px;"></th>
                                    <th>原文</th>
                                    <th>字段 key</th>
                                    <th style="width:110px;">类型</th>
                                    <th style="width:70px;">大小</th>
                                    <th>注释</th>
                                    <th style="width:80px;">状态</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="r in translatePreviewResults" :key="r.__idx">
                                    <tr :data-tridx="r.__idx">
                                        <td><input type="checkbox" :checked="r.include" x-on:change="setTranslateItemInclude" aria-label="是否追加" /></td>
                                        <td><code x-text="r.input"></code></td>
                                        <td><input type="text" :value="r.key" x-on:input="setTranslateItemKey" class="p-designer-fields__input p-designer-fields__input--bordered" autocomplete="off" /></td>
                                        <td>
                                            <select :value="r.type" x-on:change="setTranslateItemType" class="p-designer-fields__select">
                                                @foreach ($designer_type_options as $t)
                                                    <option value="{{ $t }}">{{ $t }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td><input type="text" :value="r.size" x-on:input="setTranslateItemSize" placeholder="—" class="p-designer-fields__input p-designer-fields__input--bordered" autocomplete="off" /></td>
                                        <td><input type="text" :value="r.comment" x-on:input="setTranslateItemComment" class="p-designer-fields__input p-designer-fields__input--bordered" autocomplete="off" /></td>
                                        <td><span :class="r.status_class" x-text="r.status_label"></span></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                        <label for="preview-after">插入位置</label>
                        <select id="preview-after" name="preview_after"
                            :value="batchAfter" x-on:change="setBatchAfter"
                            class="p-designer-rename-popover__input">
                            <template x-for="opt in batchAfterOptions" :key="opt.key">
                                <option :value="opt.key" :selected="opt.selected" x-text="opt.label"></option>
                            </template>
                        </select>
                        <div class="p-designer-rename-popover__actions">
                            <span class="p-designer-rename-popover__count">将追加 <span x-text="translateIncludeCount"></span> 项</span>
                            <x-scaffold::btn variant="ghost" size="sm" x-on:click="backToBatchInput">← 上一步</x-scaffold::btn>
                            <x-scaffold::btn variant="ghost" size="sm" x-on:click="cancelTranslatePreview">取消</x-scaffold::btn>
                            <x-scaffold::btn variant="primary" size="sm" x-on:click="confirmTranslateAppend">追加</x-scaffold::btn>
                        </div>
                    </div>

                    {{-- 表 key 改名 popover(未生成 migration 时;走显式确认,避开 autosave 逐字 rename) --}}
                    @include('scaffold::db.designer._modal_rename_table')

                    {{-- v6.2 round 7:删表 confirm modal(danger,要求输入表 key 才能删)
                         2026-05-23 plan-48 F1:迁 <x-scaffold::modal tone='danger'> 跟全局 destructive 模式统一 --}}
                    <div x-show="deleteTableOpen" x-cloak
                         x-on:keydown.enter.prevent="confirmDeleteTable"
                         x-on:keydown.escape.window="cancelDeleteTable">
                        <x-scaffold::modal size="sm" tone="danger" onClose="cancelDeleteTable" title="删除表 {{ $designer_current_table_key }}">
                            <p>这会立刻删 <strong>yaml 节点</strong>。</p>
                            <p class="is-note">物理 DB 表 <code>{{ $designer_current_table_key }}</code> 仍在，删后跑 <code>php artisan moo:migration {{ $schema }}</code> 生成 drop migration（走正常流程，可预览）。</p>
                            <label for="del-confirm">请输入表 key <code>{{ $designer_current_table_key }}</code> 确认：</label>
                            <input id="del-confirm" name="delete_confirm" type="text" autocomplete="off"
                                :value="deleteTableConfirm"
                                x-on:input="setDeleteTableConfirm"
                                placeholder="{{ $designer_current_table_key }}"
                                class="p-designer-rename-popover__input" />
                            <x-slot:footer>
                                <x-scaffold::btn variant="ghost" size="sm" x-on:click="cancelDeleteTable">取消</x-scaffold::btn>
                                <x-scaffold::btn variant="danger" size="sm" x-on:click="confirmDeleteTable" x-bind:disabled="deleteTableBlocked">
                                    <span x-show="deleteTableCanConfirm">永久删除</span>
                                    <span x-show="deleteTableBlocked">请输入表 key</span>
                                </x-scaffold::btn>
                            </x-slot:footer>
                        </x-scaffold::modal>
                    </div>

                    {{-- 2026-05-21:删 migration 文件 modal(替原 window.confirm 二连)
                         C+ 方案:checkbox 一次性决定是否清表 baseline,enter 确认 / esc 取消 / × 关闭
                         2026-05-23 plan-48 F1:迁 <x-scaffold::modal tone='danger'> --}}
                    <div x-show="deleteMigrationOpen" x-cloak
                         x-on:keydown.enter.prevent="submitDeleteMigration"
                         x-on:keydown.escape.window="cancelDeleteMigration">
                        <x-scaffold::modal size="sm" tone="danger" onClose="cancelDeleteMigration" title="删除 migration 文件">
                            <p>将删除 <code x-text="deleteMigrationFile"></code></p>
                            <p class="is-note">仅删文件，且 <code>migrations</code> 表无 record 才允许（server 端再校验一次）。</p>
                            <label class="p-designer-rename-popover__checkbox-row">
                                <input type="checkbox" :checked="deleteMigrationClearBaseline" x-on:change="toggleDeleteMigrationBaseline" />
                                <span>同时清此表 baseline snapshot（C+ 高级：让 designer 重 detect 该表 diff 重生成此 migration）</span>
                            </label>
                            <p class="is-note">⚠ 仅当 DB 没跑过此 migration 时勾。如果 DB 已有该表，SchemaDiffService 走 baseline_drift 守护拒生成 create_table，需手动恢复 baseline 才能继续 designer 工作流。</p>
                            <x-slot:footer>
                                <x-scaffold::btn variant="ghost" size="sm" x-on:click="cancelDeleteMigration">取消</x-scaffold::btn>
                                <x-scaffold::btn variant="danger" size="sm" x-on:click="submitDeleteMigration" x-bind:disabled="deletingMigration">
                                    <span x-text="deleteMigrationBtnLabel">永久删除</span>
                                </x-scaffold::btn>
                            </x-slot:footer>
                        </x-scaffold::modal>
                    </div>

                    {{-- plan-49:合并 migration 历史 modal —
                         dry-run preview → modal 列文件 + drift + 已 push 警告 → 二次确认执行 --}}
                    <div x-show="compactOpen" x-cloak
                         x-on:keydown.escape.window="cancelCompact">
                        <x-scaffold::modal size="lg" onClose="cancelCompact" title="合并 migration 历史">
                            <template x-if="compactBlocked">
                                <div class="is-note">
                                    <p class="p-designer-compact-modal__warn">⚠ <span x-text="compactBlockedReason"></span></p>
                                    <p x-text="compactBlockedMsg"></p>
                                </div>
                            </template>
                            <template x-if="compactShowPreview">
                                <div>
                                    {{-- 工作流语义:合并 = 部署前清理,只要该表「尚未在 production/shared 服务器部署」即可——已 push 到 git(团队协作常态)不挡,仅需人工确认未部署 --}}
                                    <p class="is-note">适用于<strong>尚未部署到 production / shared</strong> 的表（已 push 到 git 不挡）：合并后单一 create 以当前 yaml 为准，生产首跑一次性建全量；本地多条 migrations 记录部署后拉库覆盖即归零。</p>
                                    <p><strong>📁 保留 1 个文件</strong>（改写为合并后内容）：</p>
                                    <ul class="p-designer-compact-modal__list">
                                        <li><code x-text="compactCreateFile"></code></li>
                                    </ul>
                                    <p><strong>📁 删除 <span x-text="compactUpdateFiles.length"></span> 个文件</strong>:</p>
                                    <ul class="p-designer-compact-modal__list">
                                        <template x-for="f in compactUpdateFiles" :key="f">
                                            <li><code x-text="f"></code></li>
                                        </template>
                                    </ul>
                                    <template x-if="compactHasGitPushed">
                                        <div class="p-designer-compact-modal__pushed">
                                            <p class="p-designer-compact-modal__warn p-designer-compact-modal__warn--amber">
                                                ⚠ <span x-text="compactGitPushed.length"></span> 个文件已 push 到远端 —— 这些 migration 可能已被其他 dev 拉取并在<strong>本地</strong>跑过。若该表<strong>已在 production / shared 服务器部署</strong>（库里真跑过、回不去），<strong>不要合并</strong>；仅本地 / dev 环境可继续。（检测基于本地 origin refs，担心过期先 <code>git fetch</code>）
                                            </p>
                                            <label class="p-designer-rename-popover__checkbox-row">
                                                <input type="checkbox" :checked="compactForceAck" x-on:change="toggleCompactForceAck" />
                                                <span>我确认该表<strong>尚未在 production / shared 服务器部署</strong>，继续合并（其他 dev 切回此分支后需清理本地 <code>migrations</code> 表记录）</span>
                                            </label>
                                        </div>
                                    </template>
                                    <template x-if="compactHasDrift">
                                        <div>
                                            <p class="p-designer-compact-modal__warn p-designer-compact-modal__warn--amber">⚠ Schema drift <span x-text="compactDrift.length"></span> 处差异（真 DB ↔ yaml）：</p>
                                            <ul class="p-designer-compact-modal__list">
                                                <template x-for="d in compactDrift" :key="d.detail">
                                                    <li><code x-text="d.type"></code>: <span x-text="d.detail"></span></li>
                                                </template>
                                            </ul>
                                            <p class="is-note">合并以 yaml 为准（source of truth）：生产首跑新 create 拿到 yaml 全量；上述差异只说明<strong>本地 DB</strong> 与 yaml 不同步，部署后拉生产库覆盖本地即齐。仅比列名 —— 类型/索引级核对用 <code>moo:db:audit</code>。</p>
                                        </div>
                                    </template>
                                    <template x-if="compactCleanInfo">
                                        <p class="p-designer-compact-modal__hint">✓ 无 schema drift，无 git push 检测命中，可安全合并</p>
                                    </template>
                                    <details class="p-designer-compact-modal__details">
                                        <summary class="p-designer-compact-modal__summary">预览新 create 文件内容</summary>
                                        <pre class="json-block p-designer-compact-modal__preview-pre" x-text="compactPreviewPhp"></pre>
                                    </details>
                                    <label class="p-designer-rename-popover__checkbox-row">
                                        <input type="checkbox" :checked="compactCleanDb" x-on:change="toggleCompactCleanDb" />
                                        <span>同时清理 <code>migrations</code> 表中对应的孤儿记录（LOCAL DB <span x-text="compactUpdateFiles.length"></span> 条）</span>
                                    </label>
                                    <p class="is-note">⚠ 仅在该表的 migration 未发版到 production / shared 环境时使用。多人协作：其他 dev 切回此分支需要清理本地 <code>migrations</code> 表对应记录。</p>
                                </div>
                            </template>
                            <template x-if="compactLoading">
                                <p>读取 migration 文件 + drift 检测 + git 状态…</p>
                            </template>
                            <x-slot:footer>
                                <x-scaffold::btn variant="ghost" size="sm" x-on:click="cancelCompact">取消</x-scaffold::btn>
                                <x-scaffold::btn variant="primary" size="sm" x-on:click="confirmCompact"
                                                 x-bind:disabled="compactConfirmDisabled">
                                    <span x-show="compactRunningIdle">确认合并</span>
                                    <span x-show="compactRunning">合并中…</span>
                                </x-scaffold::btn>
                            </x-slot:footer>
                        </x-scaffold::modal>
                    </div>

                    {{-- 加枚举 group modal --}}
                    <div x-show="newEnumGroupOpen" x-cloak x-on:click="cancelAddEnumGroup"
                         x-on:keydown.escape.window="cancelAddEnumGroup"
                         class="p-designer-rename-popover__backdrop"></div>
                    <div x-show="newEnumGroupOpen" x-cloak class="p-designer-rename-popover"
                         x-on:keydown.enter.prevent="confirmAddEnumGroup"
                         role="dialog" aria-modal="true">
                        <button type="button" class="p-designer-rename-popover__close" x-on:click="cancelAddEnumGroup" aria-label="关闭">×</button>
                        <h4>加枚举组</h4>
                        <p>为字段创建一组 enum entries。每张表的每个字段只能有一组 enum。</p>
                        <label for="newenum-field">字段</label>
                        <select id="newenum-field" name="new_enum_field"
                            :value="newEnumGroupField" x-on:change="setNewEnumGroupField"
                            class="p-designer-rename-popover__input">
                            <option value="">— 请选 —</option>
                            <template x-for="k in enumFieldOptions" :key="k">
                                <option :value="k" x-text="k"></option>
                            </template>
                        </select>
                        <div class="p-designer-rename-popover__actions">
                            <x-scaffold::btn variant="ghost" size="sm" x-on:click="cancelAddEnumGroup">取消</x-scaffold::btn>
                            <x-scaffold::btn variant="primary" size="sm" x-on:click="confirmAddEnumGroup">加</x-scaffold::btn>
                        </div>
                    </div>

                    {{-- 2026-05-21:拼写疑问就近 popover(锚 ⚠ icon 下方,absolute 定位通过 :style 注入 left/top) --}}
                    <div x-show="spellPopoverOpen" x-cloak
                         x-on:click.away="closeSpellPopover"
                         x-on:keydown.escape.window="closeSpellPopover"
                         :style="spellPopoverStyle"
                         class="p-designer-fields__spell-popover"
                         role="dialog" aria-label="拼写疑问">
                        <button type="button" class="p-designer-fields__spell-popover__close" x-on:click="closeSpellPopover" aria-label="关闭">×</button>
                        <div class="p-designer-fields__spell-popover__head">
                            <span class="p-designer-fields__spell-popover__icon">⚠</span>
                            <span>拼写疑问</span>
                        </div>
                        <div class="p-designer-fields__spell-popover__row">
                            <span class="p-designer-fields__spell-popover__label">当前</span>
                            <code class="p-designer-fields__spell-popover__current" x-text="spellPopoverField"></code>
                        </div>
                        <div class="p-designer-fields__spell-popover__row" x-show="spellPopoverHasSuggestion">
                            <span class="p-designer-fields__spell-popover__label">建议</span>
                            <code class="p-designer-fields__spell-popover__sug" x-text="spellPopoverSuggestion"></code>
                            <button type="button" class="p-designer-fields__spell-popover__accept"
                                    x-on:click="acceptSpellSuggestion"
                                    title="一键采纳此建议（走 renameColumn 保数据）" aria-label="采纳建议">✓</button>
                        </div>
                        <div class="p-designer-fields__spell-popover__reason" x-show="spellPopoverHasReason" x-text="spellPopoverReason"></div>
                        <div class="p-designer-fields__spell-popover__hint">不会自动改名，你自己在「字段名」输入框里修订。</div>
                    </div>

                    {{-- 5. migration 历史(v12.4:不折叠 + 砍"时间"列,文件名已带日期)--}}
                    <section id="sec-migrations" class="p-designer-card-block">
                        <div class="p-designer-card-block__hd">
                            <span>Migration 历史</span>
                            {{-- plan-49 合并按钮:用 hd-btn 跟"+ 加字段 / 批量加 / 拼写检查"等同款风格 --}}
                            @if (count($designer_migrations) > 1)
                                <button type="button" class="p-designer-card-block__hd-btn" x-on:click="openCompactMigrations" title="合并 1 create + N update → 1 create（发版前）">合并 migration</button>
                            @endif
                            {{-- 2026-05-26 R-4:计数从 plain text 升 chip,跟同级 section header 风格对齐 --}}
                            <span class="badge badge--sm badge--neutral" style="margin-left: auto;">本表 {{ count($designer_migrations) }} 条</span>
                        </div>
                        <div class="p-designer-card-block__bd">
                            <x-scaffold::table compact>
                                <thead>
                                    <tr>
                                        <th class="col-batch">批次</th>
                                        <th class="col-status">状态</th>
                                        <th>文件</th>
                                        <th class="col-author">作者</th>
                                        <th class="col-summary">摘要</th>
                                        <th class="col-action">操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($designer_migrations as $m)
                                        <tr data-file="{{ $m['file'] }}">
                                            <td class="p-designer-migration-batch">{{ ($m['ran'] ?? false) ? '#'.$m['batch'] : '—' }}</td>
                                            <td>
                                                @if ($m['ran'] ?? false)
                                                    <span class="p-designer-migration-status p-designer-migration-status--ran"
                                                          title="已通过 php artisan migrate 执行（batch #{{ $m['batch'] }}）">
                                                        已执行
                                                    </span>
                                                @else
                                                    <span class="p-designer-migration-status p-designer-migration-status--pending"
                                                          title="尚未跑 php artisan migrate（或 migrations 表不可达）">
                                                        未执行
                                                    </span>
                                                @endif
                                            </td>
                                            <td>
                                                <button type="button"
                                                    class="p-designer-no-lock p-designer-migration-file p-designer-migration-file--btn"
                                                    x-on:click="viewMigration"
                                                    title="{{ $m['date'] }} · 查看 PHP 内容"
                                                ><code>{{ $m['file'] }}</code></button>
                                            </td>
                                            <td class="p-designer-migration-author">{{ $m['author'] ?: '—' }}</td>
                                            <td>{{ $m['summary'] }}</td>
                                            <td class="p-designer-migration-action">
                                                {{-- C 方案:仅未执行可删,migrations 表无 record 才允许 --}}
                                                @if (! ($m['ran'] ?? false))
                                                    <button type="button"
                                                        x-on:click="openDeleteMigration"
                                                        data-file="{{ $m['file'] }}"
                                                        class="p-designer-migration-delete-btn"
                                                        title="删除此 migration 文件（仅未执行，不动 snapshot）"
                                                    >删除</button>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </x-scaffold::table>
                        </div>
                    </section>

                    {{-- 代码生成 panel 已砍(plan 19 v5 §A):checkboxes 从来没接 Alpine state,
                         且现有 generator 命令(moo:model/api/resource/view)是 schema 粒度,
                         designer 单表视图触发会重生成整 schema 所有 Model,心智不一致 + over-aggressive。
                         用户在命令行直接跑 `php artisan moo:model {schema}` 5 秒搞定。 --}}

                    {{-- yaml 原文(v6 collapsible card,跟 migration 历史一致;hd 直接 toggle) --}}
                    @if (!empty($designer_yaml_raw))
                    @php
                        // v6.2 round 6:server-side 简单 yaml 染色(key 蓝 / 数字橙 / bool 蓝粗 / 注释灰)
                        // 顺序:先 escape → 再注入 span 的 <,后续 regex 在已注入 span 之间 match 须避开 class 属性
                        // 故只挑保证不在 class 属性里匹配的 pattern(注释 # 开头 / key 开头 / 冒号后数字 / 冒号后 bool)
                        // 字符串引号染色因 class 属性带引号会重叠 match,跳过
                        $_raw = e($designer_yaml_raw);
                        $_html = preg_replace_callback('/^(\s*)(#.*)$/m', fn ($m) => $m[1] . '<span class="yh-comment">' . $m[2] . '</span>', $_raw);
                        $_html = preg_replace('/^(\s*)([A-Za-z0-9_]+)(\s*:)/m', '$1<span class="yh-key">$2</span>$3', $_html);
                        $_html = preg_replace('/:\s+(-?\d+(?:\.\d+)?)(\s|$)/', ': <span class="yh-num">$1</span>$2', $_html);
                        $_html = preg_replace('/:\s+(true|false|null)(\s|$)/i', ': <span class="yh-bool">$1</span>$2', $_html);
                    @endphp
                    <section id="sec-yaml" class="p-designer-card-block p-designer-card-block--collapsible" :class="yamlRawCardClass" data-yaml-raw="{{ $designer_yaml_raw }}">
                        <div class="p-designer-card-block__hd">
                            <span class="p-designer-card-block__toggle p-designer-no-lock" x-on:click="toggleYamlRaw" role="button" tabindex="0"><span class="p-designer-card-block__chevron">▼</span>yaml 原文 · 当前表</span>
                            <span class="p-designer-card-block__hd-aux p-designer-card-block__hd-aux--with-action">
                                {{ strlen($designer_yaml_raw) }} 字节 · debug
                                <button type="button" class="p-designer-preview__copy-btn p-designer-no-lock" x-on:click="copyYamlRaw">复制</button>
                            </span>
                        </div>
                        <div class="p-designer-card-block__bd">
                            <pre class="p-designer-code p-designer-code--yaml">{!! $_html !!}</pre>
                        </div>
                    </section>
                    @endif

                @else
                    {{-- 空 schema(刚新建,还没加表):引导用户去点左侧"+ 新建"加首张表 --}}
                    <x-scaffold::empty
                        title="模块还没有表"
                        desc="yaml stub 已生成，点下面按钮新建第一张表；之后回到这里加字段、索引和枚举。"
                    >
                        <x-slot:icon><x-scaffold::icon name="table" :size="24" /></x-slot:icon>
                        <x-slot:actions>
                            <x-scaffold::btn variant="primary" x-on:click="openNewTable">+ 新建表</x-scaffold::btn>
                        </x-slot:actions>
                    </x-scaffold::empty>
                @endif
                </main>
            </div>

            {{-- 新建表弹窗 — 必须放在 .p-designer-shell 外,否则 fixed backdrop 被 sidebar
                 grid item 的 paint 顺序圈住,字段表 toolbar 的按钮(在 main grid item 内)会
                 浮在 backdrop 之上(实测 elementsFromPoint 显示 button > backdrop)。
                 同 Alpine x-data="dbDesigner" scope 内,newTableOpen / cancelNewTable 等不变。 --}}
            <div x-show="newTableOpen"
                 x-cloak
                 x-on:click="cancelNewTable"
                 x-on:keydown.escape.window="cancelNewTable"
                 class="p-designer-rename-popover__backdrop"
            ></div>
            <div x-show="newTableOpen"
                 x-cloak
                 x-on:keydown.enter.prevent="confirmNewTable"
                 class="p-designer-rename-popover"
                 role="dialog"
                 aria-modal="true"
            >
                <button type="button" class="p-designer-rename-popover__close" x-on:click="cancelNewTable" aria-label="关闭">×</button>
                <h4>新建表</h4>
                <p>新表会写一个 minimal yaml 节点（只 attrs + id 字段），后续在 designer 加字段。</p>
                <label for="newtable-key">表 key(snake_case)</label>
                <input id="newtable-key" name="new_table_key" type="text" autocomplete="off"
                    class="p-designer-rename-popover__input"
                    :value="newTableKey"
                    x-on:input="setNewTableKey"
                    placeholder="如 platform_videos"
                />
                <label for="newtable-name">显示名</label>
                <input id="newtable-name" name="new_table_name" type="text" autocomplete="off"
                    class="p-designer-rename-popover__input"
                    :value="newTableName"
                    x-on:input="setNewTableName"
                    placeholder="如 视频"
                />
                <label for="newtable-desc">描述（可选）</label>
                <input id="newtable-desc" name="new_table_desc" type="text" autocomplete="off"
                    class="p-designer-rename-popover__input"
                    :value="newTableDesc"
                    x-on:input="setNewTableDesc"
                    placeholder="如 视频管理"
                />
                {{-- plan 19 v8 D4:可选字段前缀(写入 yaml.attrs.prefix) --}}
                <label for="newtable-prefix">字段前缀（可选）</label>
                <input id="newtable-prefix" name="new_table_prefix" type="text" autocomplete="off"
                    class="p-designer-rename-popover__input"
                    :value="newTablePrefix"
                    x-on:input="setNewTablePrefix"
                    placeholder="如 video_（留空则不强制前缀）"
                />
                <div class="p-designer-rename-popover__actions">
                    <x-scaffold::btn variant="ghost" size="sm" x-on:click="cancelNewTable">取消</x-scaffold::btn>
                    <x-scaffold::btn variant="primary" size="sm" x-on:click="confirmNewTable" x-bind:disabled="creatingTable">
                        <span x-show="creatingTableIdle">创建</span>
                        <span x-show="creatingTable">创建中…</span>
                    </x-scaffold::btn>
                </div>
            </div>

            {{-- Preview Drawer:body 全 Alpine state-driven(this.preview);openPreview fetch /preview 后填充 --}}
            <x-scaffold::drawer name="designer-preview" title="生成新 migration · 预览" width="720px">
                <div class="p-designer-preview" x-show="preview.has_preview" x-cloak>
                    <div class="p-designer-preview__lead">
                        模块 <strong x-text="schema"></strong>
                        → 工作树
                    </div>

                    {{-- Round 2 P2:baseline 缺失(.snapshots/{schema}.yaml 不存在)首次进 designer 提示 --}}
                    {{-- 2026-05-23:baseline_missing(从未建立)+ baseline_drift(snapshot 文件存在但表段缺)
                         两 banner 之前同时显示导致措辞矛盾("从未建立"vs"已被清掉"),且 missing 场景下
                         git checkout 建议无效(文件没 commit 过)。改互斥显示 + missing banner 给正确恢复命令 --}}
                    <div class="p-designer-preview__banner p-designer-preview__banner--info"
                         x-show="preview.baseline_missing" x-cloak>
                        ℹ️ 该模块尚未建立 baseline 快照(<code>scaffold/database/.snapshots/<span x-text="schema"></span>.yaml</code>)。
                        若 DB 已建过这些表（prod 已 run 过 migration），所有表会被拒 emit <code>create_table</code>。
                        请先跑 <code>php artisan moo:snapshot:init --schema=<span x-text="schema"></span></code> 用当前 yaml 锚定 baseline，
                        后续 diff 才能识别真实变更。全新 schema（DB 没建过）则首次 migrate 后 baseline 自动建立。
                    </div>

                    {{-- 2026-05-21:baseline 缺该表但 DB 已存在 → drift 守护,拒生成 create_table。red banner 引导恢复路径
                         2026-05-23:加 !baseline_missing 互斥,避免跟 info banner 同时显示自相矛盾 --}}
                    <div class="p-designer-preview__banner p-designer-preview__banner--danger"
                         x-show="showBaselineDriftBanner" x-cloak>
                        🚫 <strong>baseline drift</strong> · 该表 <code x-text="baselineDriftTable"></code>
                        的 baseline 快照已被清掉，但 DB 里实际有此表。
                        当前拒生成 <code>create_table</code> migration（会跟已 run 的 prod 冲突）。
                        <br>
                        恢复：<code>git checkout HEAD -- scaffold/database/.snapshots/<span x-text="baselineDriftSchema"></span>.yaml</code>
                        把该 schema 的 snapshot 文件复位，再回 designer 重试。
                    </div>

                    <h4>变更摘要</h4>
                    <ul>
                        <template x-for="s in previewSummary" :key="s">
                            <li x-text="s"></li>
                        </template>
                    </ul>

                    <div x-show="preview.has_field_changes">
                        <h4>字段变化</h4>
                        <ul class="p-designer-preview__changes">
                            <template x-for="(chg, idx) in previewFieldChanges" :key="idx">
                                <li :class="chg.cls">
                                    <span class="p-designer-preview__op" x-text="chg.prefix"></span>
                                    <code x-text="chg.label"></code>
                                </li>
                            </template>
                        </ul>
                    </div>

                    <div x-show="preview.has_index_changes">
                        <h4>索引变化</h4>
                        <ul class="p-designer-preview__changes">
                            <template x-for="(chg, idx) in previewIndexChanges" :key="idx">
                                <li :class="chg.cls">
                                    <span class="p-designer-preview__op" x-text="chg.prefix"></span>
                                    <code x-text="chg.label"></code>
                                </li>
                            </template>
                        </ul>
                    </div>

                    <div x-show="preview.has_migration" x-cloak>
                    <h4>即将生成的文件</h4>
                    <div class="p-designer-preview__file-block">
                        <div class="p-designer-preview__file-toolbar">
                            <button type="button"
                                x-on:click="toggleCode"
                                class="p-designer-preview__file-toggle"
                            >
                                <span x-show="codeCollapsed">▸</span>
                                <span x-show="codeExpanded">▼</span>
                                <span x-text="previewFileName"></span>
                            </button>
                            <button type="button"
                                x-on:click="copyPreviewPhp"
                                class="p-designer-preview__copy-btn"
                                aria-label="复制 PHP migration 内容"
                                title="复制 migration PHP 到剪贴板"
                            >复制</button>
                        </div>
                        <pre x-show="codeExpanded"
                             x-cloak
                             class="p-designer-code p-designer-code--php"
                             x-html="previewPhpHtml"
                        ></pre>
                    </div>
                    </div>{{-- /x-show="preview.has_migration" --}}

                    <div x-show="preview.has_warnings">
                        <h4>⚠️ 警告</h4>
                        <ul class="p-designer-preview__warnings">
                            <template x-for="(warn, i) in previewWarnings" :key="i">
                                <li :class="warn.level_class" x-text="warn.label"></li>
                            </template>
                        </ul>
                    </div>

                    {{-- plan-41 §三 A:reverse-dep 分组渲染 — MANUAL 突出 + 一键复制 vim 命令 / AUTO 折叠 --}}
                    {{-- CSP fix:obj.prop 点链禁用,走 dbDesigner getter(hasDepManual / depManual 等) --}}
                    <div x-show="hasDepManual" x-cloak class="p-designer-preview__dep-block p-designer-preview__dep-block--manual">
                        <div class="p-designer-preview__dep-head">
                            <h4>⚠ 反向依赖 · 需手清</h4>
                            <button type="button"
                                x-on:click="copyDepGrepCmd"
                                class="p-designer-preview__copy-btn"
                                aria-label="复制 vim 命令到剪贴板"
                                title="复制全部 vim 命令（逐条跳行手清）"
                            >📋 复制 vim</button>
                        </div>
                        <ul class="p-designer-preview__dep-list">
                            <template x-for="(dep, idx) in depManual" :key="idx">
                                <li class="p-designer-preview__dep-item is-manual">
                                    <code class="p-designer-preview__dep-field" x-text="dep.field"></code>
                                    <span class="p-designer-preview__dep-op" x-text="dep.op_label"></span>
                                    <code class="p-designer-preview__dep-loc" x-text="dep.loc"></code>
                                    <code class="p-designer-preview__dep-snippet" x-text="dep.snippet"></code>
                                </li>
                            </template>
                        </ul>
                    </div>

                    <div x-show="hasDepAuto" x-cloak class="p-designer-preview__dep-block p-designer-preview__dep-block--auto">
                        <details class="p-designer-preview__dep-details">
                            <summary>🔄 反向依赖 · AUTO（下次 <code>moo:fresh</code> / 重生 Enum 时自动重写）</summary>
                            <ul class="p-designer-preview__dep-list">
                                <template x-for="(dep, idx) in depAuto" :key="idx">
                                    <li class="p-designer-preview__dep-item is-auto">
                                        <code class="p-designer-preview__dep-field" x-text="dep.field"></code>
                                        <code class="p-designer-preview__dep-loc" x-text="dep.loc"></code>
                                    </li>
                                </template>
                            </ul>
                        </details>
                    </div>

                    {{-- plan 39:commit_message textarea + 复制按钮整删 — GUI 不做 git commit,开发者手动 git add + commit --}}
                </div>

                {{-- v6.2 round 2:actions 移到 footer slot,长内容滚动时按钮一直可见 --}}
                <x-slot:footer>
                    <div class="p-designer-preview__footer" x-show="preview.has_preview" x-cloak>
                        <x-scaffold::btn variant="ghost" size="sm" x-on:click="closePreview">取消</x-scaffold::btn>
                        <x-scaffold::btn variant="primary" size="sm" x-on:click="confirmMigrate" x-bind:disabled="migrating">
                            <span x-show="migratingIdle">确认生成 migration</span>
                            <span x-show="migrating">生成中…</span>
                        </x-scaffold::btn>
                    </div>
                </x-slot:footer>
            </x-scaffold::drawer>

            {{-- Migration 历史 drill-down drawer:点 file 名查看 PHP 内容
                 width 走 drawer 默认风格(720px);代码框高度撑满 drawer 视口,溢出 pre 内滚动
                 底部关闭按钮砍掉,drawer 顶部 × + ESC 已够 --}}
            <x-scaffold::drawer name="designer-migration-view" title="Migration 文件内容" width="720px">
                <div class="p-designer-preview" x-show="historyView.has_content" x-cloak>
                    <div class="p-designer-preview__lead">
                        文件 <code x-text="historyView.file_name"></code>
                    </div>
                    <pre class="p-designer-code p-designer-code--php p-designer-code--fit" x-html="historyView.php_html"></pre>
                </div>
            </x-scaffold::drawer>

        </div>
    </div>
</div>

{{-- plan-25:dbDesigner Alpine 组件已抽到独立 designer.js,只在本页加载 --}}
<x-slot:scripts>
    <script src="/vendor/scaffold/javascript/designer.js?v={{ @filemtime(public_path('vendor/scaffold/javascript/designer.js')) ?: time() }}"></script>
</x-slot:scripts>
</x-scaffold::shell>
