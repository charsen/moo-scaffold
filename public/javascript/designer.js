/*
 * dbDesigner Alpine component(plan-25 从 alpine-init.js 抽出)
 *
 * 加载顺序约束(per shell.blade.php):
 *   <script src="alpine-init.js">           sync,head,通用 + 注册 alpine:init listener
 *   <script defer src="alpine-csp.min.js">  defer,head,启动 Alpine
 *   <script src="designer.js">              sync,body 末(本文件),注册 dbDesigner
 *   defer 跑完 → alpine-csp.min.js 触发 alpine:init → 两个 listener 都跑
 *
 * 只在 db/designer/show 页 load,通过 @section('scripts') 注入。
 * CSP build 写法守则:不允许 inline `===` / `!` / 三元 / 对象字面量 / `&&` 短路等,详见 alpine-init.js 顶部。
 */
document.addEventListener('alpine:init', () => {

    // ─────────────────────────────────────────────────────────
    // db designer 页（plan 19 prototype）
    //   - fields[] 行内编辑 / 删除 / 改名（renameColumn hint）
    //   - 批量加字段：textarea → mock 翻译 → 追加
    //   - preview drawer：codeExpanded 控制 PHP migration 展开
    // 初始 state 走 <script type="application/json" data-designer-initial>，
    // 避开 CSP inline-expression 限制。所有模板里的判断走方法，不写 ===/三元。
    // ─────────────────────────────────────────────────────────
    Alpine.data('dbDesigner', () => ({
        fields: [],
        // 2026-06-11:精度 / format 两列多数表用不到 —— 默认简洁模式隐藏;
        // init 时本表任一字段用到即自动显示,手动可切(会话内有效,不持久化)
        showAdvancedCols: false,
        tablePrefix: '',
        tableName: '',
        tableDesc: '',
        // plan 19 v11:Model / Controller / Resource 可编辑 state
        tableModelClass: '',
        tableCtrlClass: '',
        tableCtrlApps: [],         // ['admin', 'api']
        tableCtrlResources: [],    // ['api']
        _allApps: [],              // 所有可能 app keys(从 server config 拉),chipClass getter 遍历用
        // preview state:openPreview fetch 后填充。view 模板 CSP 限制,预算 boolean / class string
        preview: {
            has_preview: false,
            has_warnings: false,
            has_field_changes: false,
            has_index_changes: false,
            has_migration: false,        // 2026-05-21:无 migration file 时模板隐 file block(baseline_drift 场景)
            baseline_missing: false,     // Round 2 P2:首次进 designer / .snapshots/ 不存在时 true
            baseline_drift: false,       // 2026-05-21:baseline 缺该表 + DB 已存在,拒生成 create_table
            baseline_drift_table: '',
            baseline_drift_schema: '',
            summary: [],            // [string]
            warnings: [],            // [{ level_class: 'is-high'|'is-medium', label: '[level] msg' }]
            field_changes: [],       // [{ icon, label, op }]
            index_changes: [],
            file_name: '',
            php_code: '',
            php_html: '',     // #2 染色后 HTML
            // plan 39:commit_message 字段彻底砍 — GUI 不再做 git commit,开发者手动 git commit
        },

        // 后端契约相关 state
        schema: '',
        tableKey: '',
        csrfToken: '',
        saveEndpoint: '',
        translateEndpoint: '',
        previewEndpoint: '',
        migrateEndpoint: '',
        createTableEndpoint: '',
        migrationContentBase: '',
        compactPreviewEndpoint: '',     // plan-49
        compactExecuteEndpoint: '',     // plan-49
        renameHints: {},          // {oldKey: newKey}
        savingState: 'idle',      // idle | saving | saved | error
        _saveTimer: null,
        migrating: false,
        // v6.2 round 5:saving 状态扩展
        lastSavedAt: 0,           // ms epoch,0 = 从未保存
        saveErrorMsg: '',         // error 状态下的具体后端原因
        _nowTick: 0,              // 每 5s ++,触发 saveStatusText 重算 "N 秒前"
        // #3:显式 dirty flag,跟 savingState 解耦
        _isDirty: false,
        get saving() { return this.savingState === 'saving'; },
        get savingIdle() { return this.savingState !== 'saving'; },

        // 2026-05-23:改名 popover 状态删除(renameOpen/renameIdx/renameFrom/renameTo)
        //   改名走 key 列行内 input → setFieldKey → rename_hint 自动塞,popover 入口冗余。
        // 批量加字段 / drawer 代码块状态
        batchOpen: false,
        batchInput: '',
        batchAfter: '',        // 批量翻译插入位置(字段 key);'' = tail 之前
        batchLenient: false,   // 宽松翻译:AI 看到人名/品牌等也强行翻(拼音 fallback),不放弃
        translating: false,    // 翻译 API 进行中标志,按钮 loading + 防重复点击
        hasBatchDraft: false,  // localStorage 里是否存了未完成草稿(给"+ 批量加 (AI)"按钮显示红点)
        // 加单字段(不依赖 AI):用户输 key + 中文名 + type/size 直接插字段表
        addFieldOpen: false,
        addFieldKey: '',
        addFieldName: '',
        addFieldType: 'varchar',
        addFieldSize: '64',
        addFieldDefault: '',
        addFieldIndex: 'none',
        addFieldNullable: false,
        addFieldUnsigned: false,
        addFieldComment: '',
        addFieldAfter: '',     // 字段 key,新字段插在其后;'' = 默认尾(自动避开 deleted/created/updated_at tail)
        // 新建表 popover
        newTableOpen: false,
        newTableKey: '',
        newTableName: '',
        newTableDesc: '',
        newTablePrefix: '',     // plan 19 v8 D4:新建表时可选字段前缀
        creatingTable: false,
        get creatingTableIdle() { return !this.creatingTable; },
        // #4 新建 schema(模块)
        createSchemaEndpoint: '',
        newSchemaOpen: false,
        newSchemaKey: '',
        newSchemaName: '',
        newSchemaDesc: '',
        creatingSchema: false,
        get creatingSchemaIdle() { return !this.creatingSchema; },
        // 草稿 schema 改名 / 删除(只 index 页用,锁定态卡片不挂 ⋯ 菜单)
        schemaMenuOpenKey: '',     // 当前打开 ⋯ 菜单的 schema key('' = 关闭)
        renameSchemaOpen: false,
        renameSchemaCurrentKey: '',
        renameSchemaNewKey: '',
        renamingSchema: false,
        get renamingSchemaIdle() { return !this.renamingSchema; },
        // 表 key 改名(show 页;锁定表不挂入口,后端 latestMigrationFor 双 guard)
        renameTableOpen: false,
        renameTableCurrentKey: '',
        renameTableNewKey: '',
        renameTableEndpoint: '',
        renamingTable: false,
        get renamingTableIdle() { return !this.renamingTable; },
        deleteSchemaOpen: false,
        deleteSchemaCurrentKey: '',
        deleteSchemaConfirm: '',
        deletingSchema: false,
        get deleteSchemaCanConfirm() { return this.deleteSchemaConfirm === this.deleteSchemaCurrentKey && !this.deletingSchema; },
        get deleteSchemaBlocked() { return !this.deleteSchemaCanConfirm; },
        // v6.2 round 7:删表
        deleteTableEndpoint: '',
        deleteTableOpen: false,
        deleteTableConfirm: '',
        deletingTable: false,
        get deleteTableCanConfirm() { return this.deleteTableConfirm === this.tableKey && !this.deletingTable; },
        get deleteTableBlocked() { return !this.deleteTableCanConfirm; },
        // 2026-05-21:DeepSeek 字段拼写检查(group 级,一键查所有字段)— in-flight 防并发
        spellChecking: false,
        // 拼写建议就近 popover state(锚 ⚠ icon 位置,inline 显示 suggestion + reason)
        spellPopoverOpen: false,
        spellPopoverField: '',
        spellPopoverSuggestion: '',
        spellPopoverReason: '',
        _spellPopoverRowId: '',     // 锚的 field __rowId(stable);acceptSpellSuggestion 用它反查 fields[]
        _spellPopoverX: 0,
        _spellPopoverY: 0,
        get spellPopoverStyle() { return 'left:' + this._spellPopoverX + 'px;top:' + this._spellPopoverY + 'px;'; },
        get spellPopoverHasSuggestion() { return !!this.spellPopoverSuggestion; },
        get spellPopoverHasReason()     { return !!this.spellPopoverReason; },
        // 2026-05-21:删 migration 文件 modal(替原 window.confirm 二连)— C+ 方案 checkbox 一次性
        deleteMigrationOpen: false,
        deleteMigrationFile: '',
        deleteMigrationClearBaseline: false,
        deletingMigration: false,
        get deleteMigrationBtnLabel() { return this.deletingMigration ? '删除中…' : '永久删除'; },
        // plan-49 合并 migration 历史 modal state
        compactOpen: false,
        compactLoading: false,
        compactRunning: false,
        compactPreviewLoaded: false,
        compactBlocked: false,           // CompactBlockedException → block + 弹 reason
        compactBlockedReason: '',
        compactBlockedMsg: '',
        compactCreateFile: '',
        compactUpdateFiles: [],
        compactPreviewPhp: '',
        compactDrift: [],
        compactGitPushed: [],
        compactCleanDb: false,
        compactForceAck: false,     // 已 push 时,人工确认「该表未在 production/shared 服务器部署」→ 放行(发 force=true)
        // CSP-safe 派生(模板里不能写 ! / && / || / .length 比较)
        get compactShowPreview()  { return !this.compactBlocked && this.compactPreviewLoaded; },
        get compactHasGitPushed() { return (this.compactGitPushed || []).length > 0; },
        get compactHasDrift()     { return (this.compactDrift || []).length > 0; },
        get compactCleanInfo()    { return ! this.compactHasDrift && ! this.compactHasGitPushed; },
        // push 不再硬拦:已 push 时需勾「未部署」确认框(compactForceAck)才放行;其余阻断条件不变
        get compactConfirmDisabled() { return this.compactRunning || this.compactBlocked || ! this.compactPreviewLoaded || (this.compactHasGitPushed && ! this.compactForceAck); },
        get compactRunningIdle()     { return ! this.compactRunning; },
        // Migration 历史 drill-down(点 file 名查看 PHP 内容)
        historyView: {
            has_content: false,
            file_name: '',
            php_code: '',
            php_html: '',     // #2 染色后 HTML
        },
        loadingHistory: false,
        // F36 枚举 CRUD:per field 的 enum entries(client-driven 单源)
        enumGroups: [],     // [{ field, items: [{ __rowId, key, value, label_en, label_zh }] }]
        // F37 加 enum group modal state
        newEnumGroupOpen: false,
        newEnumGroupField: '',
        get enumFieldOptions() {
            // 可选的字段:fields[] 里 user-editable 字段 - 已有 enum group 的字段
            const taken = new Set(this.enumGroups.map(g => g.field));
            return this.fields
                .filter(f => f && !f.row_readonly && !taken.has(f.key))
                .map(f => f.key);
        },
        // F30 多字段索引 CRUD(单字段索引在字段表 index column 改;这里只多字段)
        multiIndexes: [],     // [{ __rowId, name, type, fields_str }]
        multiIdxOpen: false,
        multiIdxName: '',
        multiIdxType: 'index',
        multiIdxFields: [],     // v11.7:改 array,modal 内 multi-select chip toggle 驱动
        // F31:单字段索引派生(从 fields[] 反映,user 改字段 index column 时索引卡自动同步,不需要 reload)
        // v11.5:加 is_primary / remove_title,索引行直接支持 × 删除(primary 禁删)
        // 2026-05-26 R-1:加 type_badge_class,UI 走 badge 分色:primary→info / unique* →accent / index→neutral
        get singleFieldIndexes() {
            const out = [];
            for (const f of this.fields) {
                if (f && f.index && f.index !== 'none') {
                    const isPrimary = f.index === 'primary';
                    out.push({
                        __rowId: 'sidx_' + f.key,
                        name: f.key,       // F12 重建用 fieldKey 作 default index name
                        type: f.index,
                        type_badge_class: this._indexBadgeClass(f.index),
                        field: f.key,
                        is_primary: isPrimary,
                        remove_title: isPrimary ? 'primary 主键不可删' : ('删除「' + f.key + '」索引'),
                    });
                }
            }
            return out;
        },
        _indexBadgeClass(t) {
            if (t === 'primary') return 'badge--info';
            if (t === 'unique' || t === 'unique-app') return 'badge--accent';
            return 'badge--neutral';
        },
        codeExpanded: true,     // 2026-05-20 用户反馈:默认展开 — preview drawer 主要内容就是看 migration php
        yamlRawOpen: false,

        // 任意 modal 打开:hotkey 检测用
        get _isAnyModalOpen() {
            return this.newTableOpen || this.newSchemaOpen || this.deleteTableOpen
                || this.batchOpen || this.addFieldOpen
                || this.multiIdxOpen || this.newEnumGroupOpen
                || this.translatePreviewOpen
                || this.renameSchemaOpen || this.deleteSchemaOpen || this.renameTableOpen
                || this.deleteMigrationOpen
                || this.compactOpen;     // plan-49 compact migration modal
        },
        get yamlRawCardClass() { return { 'is-closed': !this.yamlRawOpen }; },

        // v6 批次 A:持续 saving 状态文字 + class(替代瞬时 toast,sticky header 一直显示)
        get saveStatusClass() {
            return {
                'is-idle':   this.savingState === 'idle',
                'is-saving': this.savingState === 'saving',
                'is-saved':  this.savingState === 'saved',
                'is-error':  this.savingState === 'error',
            };
        },
        get saveStatusText() {
            // 触读 _nowTick 让 getter 跟 setInterval 走(每 5s 重算 "N 秒前")
            const _ = this._nowTick;
            switch (this.savingState) {
                case 'saving': return '保存中…';
                case 'saved':  return '已保存';
                case 'error':  return '保存失败' + (this.saveErrorMsg ? '：' + this.saveErrorMsg : '');
                default:
                    if (this.lastSavedAt > 0) {
                        const ago = Math.floor((Date.now() - this.lastSavedAt) / 1000);
                        if (ago < 5)   return '已保存 · 刚刚';
                        if (ago < 60)  return '已保存 · ' + ago + ' 秒前';
                        if (ago < 3600) return '已保存 · ' + Math.floor(ago / 60) + ' 分钟前';
                        return '已保存 · ' + Math.floor(ago / 3600) + ' 小时前';
                    }
                    return '未改动';
            }
        },

        init() {
            try {
                const node = this.$el.querySelector('script[data-designer-initial]');
                const raw = node ? node.textContent : '{}';
                const data = JSON.parse(raw || '{}');
                this.fields = Array.isArray(data.fields) ? data.fields : [];
                // init state 里 designer_preview 永远 null(SSR 早砍了),保 default empty struct
                this.tablePrefix = data.tablePrefix || '';
                this.tableName = data.tableName || '';
                this.tableDesc = data.tableDesc || '';
                // plan 19 v11:Model / Controller / Resource state init
                this.tableModelClass    = data.tableModelClass || '';
                this.tableCtrlClass     = data.tableCtrlClass || '';
                this.tableCtrlApps      = Array.isArray(data.tableCtrlApps) ? data.tableCtrlApps.slice() : [];
                this.tableCtrlResources = Array.isArray(data.tableCtrlResources) ? data.tableCtrlResources.slice() : [];
                this._allApps           = Array.isArray(data.allApps) ? data.allApps.slice() : [];
                this.schema = data.schema || '';
                this.tableKey = data.tableKey || '';
                this.csrfToken = data.csrfToken || '';
                this.saveEndpoint = data.saveEndpoint || '';
                this.translateEndpoint = data.translateEndpoint || '';
                this.previewEndpoint = data.previewEndpoint || '';
                this.migrateEndpoint = data.migrateEndpoint || '';
                this.createTableEndpoint = data.createTableEndpoint || '';
                this.createSchemaEndpoint = data.createSchemaEndpoint || '';
                this.deleteTableEndpoint = data.deleteTableEndpoint || '';
                this.renameTableEndpoint = data.renameTableEndpoint || '';
                this.migrationContentBase = data.migrationContentBase || '';
                // 2026-05-21 C 方案:删 migration 文件 endpoint tpl,前端 replace FILENAME_PLACEHOLDER.php
                this.deleteMigrationEndpointTpl = data.deleteMigrationEndpointTpl || '';
                // plan-49 合并 migration endpoint
                this.compactPreviewEndpoint = data.compactPreviewEndpoint || '';
                this.compactExecuteEndpoint = data.compactExecuteEndpoint || '';
                // F30 multi-field indexes init:拆 designer_indexes 把多字段那部分填进来
                this.multiIndexes = Array.isArray(data.multiIndexes) ? data.multiIndexes : [];
                // F36 enumGroups init from designer_enums
                this.enumGroups = Array.isArray(data.enumGroups) ? data.enumGroups : [];
                // v12.5:字段默认值 select 需要 enum 派生,init 时一次性算
                this._recomputeAllFieldsEnum();
                // 2026-05-21:字段 key 前缀 strip 按钮可见性 — 依赖 tablePrefix + f.key + f.name_readonly
                this._recomputeAllFieldsPrefixStrip();
                // 简洁/完整列模式:按本表是否用到 精度/format 决定默认
                this._autoDetectAdvancedCols();
                this._recomputeMoveFlags();     // 行上下移按钮的边界置灰
            } catch (e) {
                console.warn('[dbDesigner] bad initial state:', e);
                this.fields = [];
            }
            // v6.2 round 5:每 5s tick 一次 _nowTick,让 saveStatusText 重算 "N 秒前"
            setInterval(() => { this._nowTick = (this._nowTick + 1) % 100000; }, 5000);

            // 刷新/重开浏览器后,仅探测有没有批量翻译草稿,设按钮红点;
            // 不强弹 modal,用户主动点"+ 批量加 (AI)"才恢复(见 openBatch)
            this._detectBatchDraft();

            // v6.2 round 7:全局键盘快捷键(Cmd/Ctrl+S 保存,Cmd/Ctrl+Enter 触发 preview)
            // modal 内 Cmd+S 不触发(modal 自身 Enter 已提交,Cmd+S 走 preventDefault 静默)
            document.addEventListener('keydown', (e) => {
                const isMeta = e.metaKey || e.ctrlKey;
                if (!isMeta) return;
                const t = e.target;
                const inEditable = t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable);
                if (e.key === 's' || e.key === 'S') {
                    e.preventDefault();
                    if (this._isAnyModalOpen) return;     // modal 内 noop(避免误存)
                    this.saveNow();
                } else if (e.key === 'Enter') {
                    if (inEditable) return;     // 让 Enter 在 input 里走默认(modal Enter 提交)
                    e.preventDefault();
                    if (this._isAnyModalOpen) return;
                    this.openPreview();
                }
            });

            // #3 切表/刷新/关页面前若有未保存改动 → 浏览器原生 confirm
            // _isDirty 在每次 setter→_scheduleSave 时置 true,在 _flushSave 成功时清回 false
            window.addEventListener('beforeunload', (e) => {
                if (this._isDirty || this.savingState === 'error') {
                    e.preventDefault();
                    e.returnValue = '';       // 兼容旧浏览器
                    return '';
                }
            });
            // 2026-05-20:designer 内 9+ modal/popover 共享 body scroll lock(跟 drawer + confirm 同 counter)
            // 任一 modal open → push body lock;关 → pop。最后一个关掉才真 unlock。
            this.$watch('_isAnyModalOpen', function (open) {
                if (! window.scaffoldBodyLock) return;
                if (open) window.scaffoldBodyLock.push();
                else window.scaffoldBodyLock.pop();
            });
        },

        // —— 真实端点调用 helper ——
        // v6.2 round 7:加 opts 支持自定义 method(DELETE 等)
        async _post(url, body, opts) {
            const headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
            if (this.csrfToken) headers['X-CSRF-TOKEN'] = this.csrfToken;
            const res = await fetch(url, {
                method: (opts && opts.method) || 'POST',
                headers: headers,
                credentials: 'same-origin',
                body: JSON.stringify(body),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                const err = json && json.error ? json.error : { code: 'HTTP_' + res.status, msg: '请求失败' };
                const e = new Error(err.msg || err.code);
                e.code = err.code;
                e.detail = err.detail;
                e.status = res.status;
                throw e;
            }
            return (json && json.data) ? json.data : json;
        },
        async _get(url) {
            const res = await fetch(url, {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                const err = json && json.error ? json.error : { code: 'HTTP_' + res.status, msg: '请求失败' };
                const e = new Error(err.msg || err.code);
                e.code = err.code;
                e.detail = err.detail;
                e.status = res.status;
                throw e;
            }
            return (json && json.data) ? json.data : json;
        },
        // v6.3 C-4:跟 SchemaLoader::rebuildFieldRows 的 $writable 对齐到 single source of truth(JS 侧)
        //   PHP $writable = ['type', 'size', 'required', 'default', 'comment', 'unsigned']
        //   JS 侧拆 2 组:
        //     _FIELD_OUTGOING_ATTRS — 总是发(从 shape 直读,不依赖 dirty)
        //     dirty-tracked        — required/unsigned 只在 _nullable_dirty/_unsigned_dirty 时才发
        //   特殊不在 $writable:
        //     index → 走表级 table.index 块重建,不写 field row
        //     name/display_name → yaml.<key>.name(中文),映射不是属性平替
        _FIELD_OUTGOING_ATTRS: ['type', 'size', 'default', 'comment', 'precision', 'format'],
        _buildFieldEntry(f) {
            // readonly 字段(id / deleted_at / created_at / updated_at)只发 name + display_name + index,
            // 其余 derived attrs 全跳过(保 yaml 原本 `{}` 空 row 干净,让后端 normalize 派生)
            const base = {
                name: f.key,                       // outgoing yaml field key
                display_name: f.name ?? null,      // → yaml.<key>.name(中文)
                index: (f.index === 'none' || f.index == null) ? null : f.index,
            };
            if (f.row_readonly) return base;

            // 普通字段:iterate _FIELD_OUTGOING_ATTRS,?? 而不是 || 保 0/false/'' 不被 falsy 吞
            // 2026-05-23 P0 bug 修(round 2):Laravel 全局 ConvertEmptyStringsToNull 中间件把
            // POST body 内 `""` 转成 null,跟 client `?? null` "未改"信号撞 — 后端无法分辨"清空"vs
            // "未改"。空字符串发 '__CLEAR__' sentinel 绕过中间件,server `rebuildFieldRows` 检测。
            const out = { ...base };
            for (const attr of this._FIELD_OUTGOING_ATTRS) {
                const v = f[attr];
                out[attr] = v === '' ? '__CLEAR__' : (v ?? null);
            }
            // dirty-tracked attrs:F26 nullable / F32 unsigned 只在 user 改过才发,避免 shape 派生值污染 yaml
            if (f._nullable_dirty) out.required = !f.nullable;
            // F32:user 改过 unsigned 就发(true 或 false),后端 rebuildFieldRows 两值都保留写入 yaml。
            // - 发 true:yaml 写 unsigned: true(idempotent,兼容系统字段 creator_id 等历史 yaml)
            // - 发 false:yaml 写 unsigned: false(user 显式 signed 的唯一表达,不能丢)
            // 不在 client 这层吞 false — 否则 user 从 true toggle 回 false 时后端收不到信号,
            // 旧 yaml 的 true 不会被覆盖成 false。
            if (f._unsigned_dirty) out.unsigned = !!f.unsigned;
            return out;
        },
        _buildSavePayload() {
            const tableData = {
                name: this.tableName ?? '',
                desc: this.tableDesc ?? '',
                prefix: this.tablePrefix ?? '',     // F29:字段前缀持久化到 yaml.attrs.prefix
                // plan 19 v11:Model / Controller / Resource 写盘
                model: { class: (this.tableModelClass || '').trim() },
                controller: {
                    class: (this.tableCtrlClass || '').trim(),
                    app: [...(this.tableCtrlApps || [])],
                    resource: [...(this.tableCtrlResources || [])],
                },
                fields: this.fields.map(f => this._buildFieldEntry(f)),
                rename_hints: { ...this.renameHints },
                // F30 多字段索引(client 单源驱动:user 加/改/删都在 client state,save 一次性发全量)
                multi_indexes: this.multiIndexes.map(m => ({
                    name: m.name,
                    type: m.type,
                    fields: (m.fields_str || '').split(/[,，\s]+/).filter(s => s.length > 0),
                })),
                // F36 enums(client 单源驱动,saveModule 完全替换 yaml.<table>.enums)
                // 2026-05-21 enum 翻译辅助:空 key items 不再 filter,允许保存 pending 状态(等 AI 翻译填)。
                // server applyEnums 用 sentinel __pending_<n> 写 yaml,moo:model 端拒生成 + 提示 user 去翻译。
                enums: this.enumGroups.map(g => ({
                    field: g.field,
                    items: (g.items || []).map(r => ({
                        key: r.key || '',
                        value: r.value,
                        label_en: r.label_en || '',
                        label_zh: r.label_zh || '',
                    })),
                })),
            };
            return {
                module: {},
                tables: { [this.tableKey]: tableData },
            };
        },
        // v6.2 round 5:toast dedupe — 同 msg+tone 1.2s 内不重发,避免快速重复操作堆叠
        _lastToastKey: '',
        _lastToastAt: 0,
        _toast(msg, tone, duration) {
            const key = (tone || 'info') + '|' + msg;
            const now = Date.now();
            if (key === this._lastToastKey && (now - this._lastToastAt) < 1200) return;
            this._lastToastKey = key;
            this._lastToastAt = now;
            window.dispatchEvent(new CustomEvent('toast', {
                detail: { message: msg, tone: tone || 'info', duration: typeof duration === 'number' ? duration : 2200 },
            }));
        },

        // —— 字段行编辑 ——
        // CSP build:模板里既不能 method-call 带 literal($event 旁的字符串字面量也算),也不能 x-for destructure,
        //           连"单参方法 + idxOf(f) 反查"也吃 multi-arg method call 的亏 → 全面改:
        //             ① <tr> 上挂 :data-rk="f.__rowId"(stable session id)
        //             ② view 上写 x-on:input="setFieldXxx" 无 parens,Alpine 自动传 $event
        //             ③ setter 内通过 _findIdxByEvent(ev) 从 $event.target.closest('tr').dataset.rk 反查 fields[] 下标
        _findIdxByEvent(ev) {
            const tr = ev?.target?.closest?.('tr');
            const rk = tr?.dataset?.rk;
            if (!rk) return -1;
            return this.fields.findIndex(f => f && f.__rowId === rk);
        },
        _setFieldByEvent(ev, attr, parseVal) {
            const idx = this._findIdxByEvent(ev);
            if (idx < 0) return;
            const f = this.fields[idx];
            if (!f) return;
            f[attr] = parseVal(ev);
            this._scheduleSave();
        },
        _evalVal(ev)     { return (ev && ev.target) ? ev.target.value : ''; },
        _evalChecked(ev) { return !!(ev && ev.target && ev.target.checked); },
        // 字段行上/下移排序(2026-06-16):只在业务字段间换位,不跨系统行(row_readonly:id / 时间戳)。
        // 重排 this.fields 数组 → autosave 按数组序写 yaml(rebuildFieldRows 保序)。CSP build 不能在
        // 模板用 !/||/三元,故把 can_move / move_up_disabled / move_down_disabled 预算成单 flag 挂字段上。
        _recomputeMoveFlags() {
            const fs = this.fields;
            for (let i = 0; i < fs.length; i++) {
                const f = fs[i];
                if (!f) continue;
                const movable = !f.row_readonly;
                const above = fs[i - 1];
                const below = fs[i + 1];
                f.can_move           = movable;
                f.move_up_disabled   = !(movable && above && !above.row_readonly);
                f.move_down_disabled = !(movable && below && !below.row_readonly);
            }
        },
        _moveField(ev, dir) {
            const idx = this._findIdxByEvent(ev);
            if (idx < 0) return;
            const f = this.fields[idx];
            if (!f || f.row_readonly) return;
            const target   = idx + dir;
            const neighbor = this.fields[target];
            if (!neighbor || neighbor.row_readonly) return;     // 不跨系统行(id / 时间戳锁住顶/底)
            this.fields.splice(idx, 1);
            this.fields.splice(target, 0, f);
            this._recomputeMoveFlags();
            this._scheduleSave();
        },
        moveFieldUp(ev)   { this._moveField(ev, -1); },
        moveFieldDown(ev) { this._moveField(ev, 1); },
        setFieldKey(ev) {
            // F39:行内改字段名,自动塞 rename_hint(走 renameColumn 保数据,不是 drop+add)
            const idx = this._findIdxByEvent(ev);
            if (idx < 0) return;
            const f = this.fields[idx];
            if (!f) return;
            const newKey = (ev?.target?.value || '').trim();
            if (!newKey || newKey === f.key) return;
            if (!/^[a-z][a-z0-9_]*$/.test(newKey)) {
                this._toast('字段 key 必须 snake_case', 'warning');
                ev.target.value = f.key;     // 回滚显示
                return;
            }
            if (this.fields.some(other => other !== f && other.key === newKey)) {
                this._toast('字段 key 冲突：' + newKey, 'warning');
                ev.target.value = f.key;
                return;
            }
            const origKey = f._orig_key || f.key;
            this.renameHints[origKey] = newKey;
            f.rename_from = origKey;
            f.key = newKey;
            // F39 配套:enum group 跟字段绑定,字段改名时 enum group field 同步
            const eg = this.enumGroups.find(g => g.field === f.key || g.field === origKey);
            if (eg) eg.field = newKey;
            // v12.5:f.key 变了,需要重算 enum 派生(field 之前匹配旧 key 的 enum,现在按 newKey)
            this._recomputeFieldEnum(f);
            this._recomputeFieldPrefixStrip(f);     // key 改了重算 strip 按钮可见性
            this._setFieldSpellWarning(f, null);    // key 改了之前的拼写检查作废
            this._scheduleSave();
        },
        setFieldName(ev)     { this._setFieldByEvent(ev, 'name', this._evalVal); },
        // v6.2 round 4:size 必填校验 + type 切换时 size 联动
        _needsSize(type) { return ['varchar', 'char', 'decimal'].includes(type); },
        _revalidateSize(f) {
            const bad = this._needsSize(f.type) && (f.size == null || f.size === '' || f.size === 0);
            f.size_class = bad ? 'is-invalid' : '';
            f.size_title = bad ? '此类型需要 size，请填（如 varchar 64）' : '';
        },
        // #30:default 值 vs type 兼容性校验
        _revalidateDefault(f) {
            const v = f.default;
            if (v == null || v === '') { f.default_class = ''; f.default_title = ''; return; }
            const numTypes = ['bigint', 'int', 'tinyint', 'smallint', 'mediumint', 'decimal', 'float', 'double'];
            if (numTypes.includes(f.type)) {
                const ok = !isNaN(parseFloat(v)) && isFinite(v);
                f.default_class = ok ? '' : 'is-invalid';
                f.default_title = ok ? '' : (f.type + ' 默认值须数字');
                return;
            }
            if (f.type === 'bool') {
                const ok = ['0','1','true','false'].includes(String(v));
                f.default_class = ok ? '' : 'is-invalid';
                f.default_title = ok ? '' : 'bool 默认值须 0/1/true/false';
                return;
            }
            f.default_class = ''; f.default_title = '';
        },
        setFieldType(ev) {
            this._setFieldByEvent(ev, 'type', this._evalVal);
            const idx = this._findIdxByEvent(ev);
            if (idx < 0) return;
            const f = this.fields[idx];
            // F32:type 切换后同步 unsigned_disabled(只 numeric 可勾)
            const isNumeric = ['bigint', 'int', 'tinyint', 'smallint', 'mediumint', 'decimal', 'float', 'double'].includes(f.type);
            f.unsigned_disabled = !isNumeric;
            if (!isNumeric && f.unsigned) {       // 切到非 numeric 时清掉 unsigned
                f.unsigned = false;
                f._unsigned_dirty = true;
            }
            // precision 只对 decimal/double/float 开放;切走时禁用框 + 清空值
            const needsPrecision = ['decimal', 'float', 'double'].includes(f.type);
            f.precision_disabled = !needsPrecision;
            if (!needsPrecision && f.precision != null && f.precision !== '') {
                f.precision = null;
            }
            // v6.2 round 4:size 联动
            if (this._needsSize(f.type)) {
                if (f.size == null || f.size === '' || f.size === 0) {
                    f.size = (f.type === 'decimal') ? '8,2' : 64;
                }
            } else if (f.size != null && f.size !== '') {
                f.size = null;
            }
            this._revalidateSize(f);
            this._revalidateDefault(f);     // #30 type 改了 default 也要重算
        },
        setFieldSize(ev) {
            const idx = this._findIdxByEvent(ev);
            if (idx < 0) return;
            const f = this.fields[idx];
            // plan-37 后审 P1:disabled 状态下不接受 setter(键盘/script 绕开 UI 时兜底)
            if (f.row_readonly) return;
            this._setFieldByEvent(ev, 'size', this._evalVal);
            this._revalidateSize(f);
        },
        setFieldDefault(ev) {
            const idx = this._findIdxByEvent(ev);
            if (idx < 0) return;
            const f = this.fields[idx];
            if (f.row_readonly) return;
            this._setFieldByEvent(ev, 'default', this._evalVal);
            this._revalidateDefault(f);
            // v12.5.3/4 重算 default_str + option.selected,保 select 显示同步
            this._recomputeFieldEnum(f);
        },
        setFieldIndex(ev)    {
            const idx = this._findIdxByEvent(ev);
            if (idx < 0) return;
            if (this.fields[idx].row_readonly) return;
            this._setFieldByEvent(ev, 'index', this._evalVal);
        },
        setFieldComment(ev)  {
            const idx = this._findIdxByEvent(ev);
            if (idx < 0) return;
            if (this.fields[idx].row_readonly) return;
            this._setFieldByEvent(ev, 'comment', this._evalVal);
        },
        setFieldPrecision(ev){
            const idx = this._findIdxByEvent(ev);
            if (idx < 0) return;
            const f = this.fields[idx];
            if (f.row_readonly || f.precision_disabled) return;
            this._setFieldByEvent(ev, 'precision', this._evalVal);
        },
        setFieldFormat(ev)   {
            const idx = this._findIdxByEvent(ev);
            if (idx < 0) return;
            if (this.fields[idx].row_readonly) return;
            this._setFieldByEvent(ev, 'format', this._evalVal);
        },
        setFieldNullable(ev) {
            const idx = this._findIdxByEvent(ev);
            if (idx < 0) return;
            const f = this.fields[idx];
            if (!f || f.row_readonly) return;
            f.nullable = this._evalChecked(ev);
            f._nullable_dirty = true;     // F26 dirty 锚点:_buildSavePayload 见此才发 required
            this._scheduleSave();
        },
        setFieldUnsigned(ev) {
            const idx = this._findIdxByEvent(ev);
            if (idx < 0) return;
            const f = this.fields[idx];
            // plan-37 后审 P1:type 不允许 unsigned(text/varchar 等)时静默 reject,避免写非法 yaml
            if (!f || f.row_readonly || f.unsigned_disabled) return;
            f.unsigned = this._evalChecked(ev);
            f._unsigned_dirty = true;     // F32 dirty 锚点
            this._scheduleSave();
        },
        _scheduleSave() {
            if (!this.saveEndpoint) return;
            if (this._saveTimer) clearTimeout(this._saveTimer);
            this._isDirty = true;     // #3:每次 setter 都标 dirty
            this.savingState = 'saving';
            this._saveTimer = setTimeout(() => this._flushSave(), 500);
        },
        // 网络/5xx 错才重试,验证错(422/SUSPECTED_RENAMES)不重试
        _shouldRetrySave(e) {
            if (!e) return false;
            if (e.status === undefined || e.status === 0) return true;     // 网络断
            return e.status >= 500;
        },
        // plan 19 v8 C2:统一错误文案,_toast 1.2s dedup 实现去重
        _saveErrorText(e) {
            return (e && (e.message || e.code)) || '未知';
        },
        async _flushSave(retryCount) {
            this._saveTimer = null;     // 进入 flush 后 timer 标 null,saveNow 据此区分"等待中 vs 真 in-flight"
            retryCount = retryCount || 0;
            try {
                await this._post(this.saveEndpoint, this._buildSavePayload());
                this.savingState = 'saved';
                this.lastSavedAt = Date.now();
                this.saveErrorMsg = '';
                this._isDirty = false;     // #3:flush 成功才清 dirty
                setTimeout(() => { if (this.savingState === 'saved') this.savingState = 'idle'; }, 1500);
            } catch (e) {
                // 第一次失败 + 网络错 → 1s 后静默重试,user 看到的 pill 仍是"保存中…"
                if (retryCount < 1 && this._shouldRetrySave(e)) {
                    setTimeout(() => this._flushSave(retryCount + 1), 1000);
                    return;
                }
                this.savingState = 'error';
                this.saveErrorMsg = this._saveErrorText(e);
                this._toast('保存失败' + (retryCount > 0 ? '（已重试）' : '') + '：' + this.saveErrorMsg, 'danger');
            }
        },
        // —— 立即保存(保存按钮触发):取消 debounce、flush 一次、给 toast 反馈 ——
        // 2026-05-21:enum 翻译辅助 feature,空 key 现在是合法 pending 状态(等 AI 翻译填),
        // save 端不再 warn。codegen(moo:model)看到 pending key 会拒生成 + 提示 user。
        async saveNow() {
            // savingState='saving' 有两层来源:_scheduleSave 设的(timer 等待中) vs _flushSave 真 in-flight。
            // 只有真 in-flight(timer 已 null)才 noop;timer 还在 → bypass 它立刻 flush
            if (this.savingState === 'saving' && !this._saveTimer) return;
            if (!this.saveEndpoint) { this._toast('保存端点未配置', 'warning'); return; }
            if (this._saveTimer) { clearTimeout(this._saveTimer); this._saveTimer = null; }
            this.savingState = 'saving';
            try {
                await this._post(this.saveEndpoint, this._buildSavePayload());
                this.savingState = 'saved';
                this.lastSavedAt = Date.now();
                this.saveErrorMsg = '';
                this._isDirty = false;     // #3
                this._toast('已保存', 'success');
                setTimeout(() => { if (this.savingState === 'saved') this.savingState = 'idle'; }, 1500);
            } catch (e) {
                this.savingState = 'error';
                this.saveErrorMsg = this._saveErrorText(e);
                this._toast('保存失败：' + this.saveErrorMsg, 'danger');
            }
        },
        // CSP build 不支持模板里写 $event.target.value 深属性链，统一走 method 解出
        // 顶层 state 设值:CSP build 拒绝 method call 带字符串字面量,改成每个 state 一个专属 setter
        // template 里写 x-on:input="setTableName"(无 parens),Alpine 自动传 $event
        setTableName(ev)   { this.tableName   = (ev && ev.target) ? ev.target.value : ''; this._scheduleSave(); },
        setTableDesc(ev)   { this.tableDesc   = (ev && ev.target) ? ev.target.value : ''; this._scheduleSave(); },
        setTablePrefix(ev) {
            this.tablePrefix = (ev && ev.target) ? ev.target.value : '';
            this._recomputeAllFieldsPrefixStrip();     // prefix 改了重算 strip 按钮可见性
            this._scheduleSave();     // 2026-06-16:跟 model/controller setter 一致,改了触发 autosave(原漏)
        },
        // 2026-05-21:字段 key 前缀 strip 按钮(行 key 前 icon)
        //   semantic = raw str_replace(prefix, '', key),不补 '_';prefix 由 user 在 attrs.prefix 自己定
        //   (e.g. prefix='user_' + key='user_avatar' → 'avatar');不要替 user 兜底加 '_'
        _isFieldPrefixStrippable(f) {
            if (!f || f.name_readonly) return false;
            const p = (this.tablePrefix || '');
            if (!p) return false;
            if (!f.key || !f.key.includes(p)) return false;
            const stripped = f.key.replace(p, '');
            if (!stripped) return false;             // 全 strip 空 → 不允许(剩下没法当 key)
            if (stripped === f.key) return false;    // 没变化(不该发生,includes 已 guard)
            return /^[a-z][a-z0-9_]*$/.test(stripped);     // strip 后仍 snake_case
        },
        _recomputeFieldPrefixStrip(f) {
            if (!f) return;
            f.prefix_strippable = this._isFieldPrefixStrippable(f);
            // 2026-05-21:不可 strip 的行也渲按钮(visibility:hidden 占位),保所有行 key 列宽度齐
            //   CSP build 拒 !var,派生两个 class / disabled flag 给模板
            f.prefix_strip_btn_class = f.prefix_strippable
                ? 'p-designer-fields__row-btn p-designer-fields__row-btn--strip'
                : 'p-designer-fields__row-btn p-designer-fields__row-btn--strip is-placeholder';
            f.prefix_strip_disabled = ! f.prefix_strippable;
        },
        _recomputeAllFieldsPrefixStrip() {
            (this.fields || []).forEach(f => this._recomputeFieldPrefixStrip(f));
        },
        // 2026-05-21:一键 AI 拼写检查所有字段 key — 后端 spellCheckFields,前端写 spell_warning 到每行,不纠正
        async aiSpellCheckFields() {
            if (this.spellChecking) return;
            const candidates = (this.fields || []).filter(f => f && f.key && !f.row_readonly);
            if (candidates.length === 0) {
                this._toast('没有可检查的字段', 'info');
                return;
            }
            const inputs = candidates.map(f => f.key);
            this.spellChecking = true;
            try {
                const data = await this._post(this.translateEndpoint, {
                    scene: 'spell_check',
                    inputs,
                });
                const results = (data && data.results) || [];
                let warnCount = 0;
                // 先清旧 warning(再跑一次相当于重置)
                this.fields.forEach(f => { if (f) this._setFieldSpellWarning(f, null); });
                results.forEach((r, i) => {
                    const f = candidates[i];
                    if (!f) return;
                    if (r && r.valid && r.spelled_correctly === false) {
                        this._setFieldSpellWarning(f, r.suggestion || '', r.reason || '');
                        warnCount++;
                    }
                });
                if (warnCount === 0) {
                    this._toast('全部字段拼写无明显问题 ✓', 'success');
                } else {
                    this._toast(`发现 ${warnCount} 个拼写疑问（看字段名右侧 ⚠ icon）`, 'warning');
                }
            } catch (e) {
                if (e.code === 'AI_NOT_CONFIGURED') {
                    this._toast('AI 未配置（SCAFFOLD_AI_API_KEY），无法检查', 'danger');
                } else {
                    this._toast('拼写检查失败：' + (e.message || e.code), 'danger');
                }
            } finally {
                this.spellChecking = false;
            }
        },
        // 2026-05-21:set/clear 字段拼写检查结果 — suggestion + reason 单独存,popover/title attr 都能用
        _setFieldSpellWarning(f, suggestion, reason) {
            if (!f) return;
            const isClear = (suggestion === null && (reason === null || reason === undefined));
            if (isClear) {
                f.spell_warning = '';
                f.spell_suggestion = '';
                f.spell_reason = '';
                f.has_spell_warning = false;
                f.spell_warn_class = 'p-designer-fields__spell-warn is-placeholder';
                return;
            }
            f.spell_suggestion = suggestion || '';
            f.spell_reason = reason || '';
            const sug = f.spell_suggestion ? ' → ' + f.spell_suggestion : '';
            const why = f.spell_reason ? '(' + f.spell_reason + ')' : '';
            f.spell_warning = ('拼写疑问：' + f.key + sug + ' ' + why).trim();
            f.has_spell_warning = true;
            f.spell_warn_class = 'p-designer-fields__spell-warn';
        },
        get spellCheckBtnLabel() { return this.spellChecking ? '检查中…' : '拼写检查'; },
        get advColsBtnLabel() { return this.showAdvancedCols ? '⊖ 精度/format' : '⊕ 精度/format'; },
        toggleAdvancedCols() { this.showAdvancedCols = !this.showAdvancedCols; },
        // 只自动「开」不自动「关」:本表任一字段的 precision / format 有内容即显示两列;
        // 用户手动开过(showAdvancedCols=true)后不再干预
        _autoDetectAdvancedCols() {
            if (this.showAdvancedCols) return;
            this.showAdvancedCols = (this.fields || []).some((f) => {
                const p = f.precision, m = f.format;
                return (p !== null && p !== undefined && String(p) !== '') || (m !== null && m !== undefined && String(m) !== '');
            });
        },
        // 2026-05-21:点 ⚠ icon → 就近 popover 显示 suggestion + reason(替原生 title hover / 右下 toast)
        showSpellWarning(ev) {
            const tr = ev?.target?.closest?.('tr');
            const rk = tr?.dataset?.rk;
            if (!rk) return;
            const f = (this.fields || []).find(x => x.__rowId === rk);
            if (!f || !f.has_spell_warning) return;
            const iconEl = ev?.target;
            const rect = iconEl?.getBoundingClientRect?.() || { right: 0, bottom: 0, left: 0 };
            // 锚 ⚠ 下方 4px,左对 icon 左缘;page 滚动加回 scrollX/Y
            this._spellPopoverX = Math.round(rect.left + (window.scrollX || window.pageXOffset || 0));
            this._spellPopoverY = Math.round(rect.bottom + (window.scrollY || window.pageYOffset || 0) + 4);
            this._spellPopoverRowId = f.__rowId;
            this.spellPopoverField = f.key;
            this.spellPopoverSuggestion = f.spell_suggestion || '';
            this.spellPopoverReason = f.spell_reason || '';
            this.spellPopoverOpen = true;
        },
        closeSpellPopover() { this.spellPopoverOpen = false; },
        // 2026-05-21:一键采纳 AI 建议 — 走 setFieldKey 同 rename 流(renameHints + enum group 同步)
        acceptSpellSuggestion() {
            const sug = (this.spellPopoverSuggestion || '').trim();
            if (!sug) return;
            const f = (this.fields || []).find(x => x.__rowId === this._spellPopoverRowId);
            if (!f) return;
            if (sug === f.key) { this.closeSpellPopover(); return; }
            if (!/^[a-z][a-z0-9_]*$/.test(sug)) {
                this._toast('建议格式非法：' + sug, 'warning');
                return;
            }
            if (this.fields.some(other => other !== f && other.key === sug)) {
                this._toast('字段 key 冲突：' + sug, 'warning');
                return;
            }
            const origKey = f._orig_key || f.key;
            this.renameHints[origKey] = sug;
            f.rename_from = origKey;
            f.key = sug;
            const eg = this.enumGroups.find(g => g.field === origKey);
            if (eg) eg.field = sug;
            this._recomputeFieldEnum(f);
            this._recomputeFieldPrefixStrip(f);
            this._setFieldSpellWarning(f, null);     // 采纳即作废 warning
            this._scheduleSave();
            this.closeSpellPopover();
            this._toast('已采纳建议：' + sug, 'success');
        },
        // 一键去掉字段前缀 — 跟 setFieldKey 同 rename 流程(renameHints + rename_from + enum group 同步)
        stripFieldPrefix(ev) {
            const idx = this._findIdxByEvent(ev);
            if (idx < 0) return;
            const f = this.fields[idx];
            if (!f || !this._isFieldPrefixStrippable(f)) return;
            const newKey = f.key.replace(this.tablePrefix, '');
            if (this.fields.some(other => other !== f && other.key === newKey)) {
                this._toast('字段 key 冲突：' + newKey, 'warning');
                return;
            }
            const origKey = f._orig_key || f.key;
            this.renameHints[origKey] = newKey;
            f.rename_from = origKey;
            f.key = newKey;
            const eg = this.enumGroups.find(g => g.field === origKey);
            if (eg) eg.field = newKey;
            this._recomputeFieldEnum(f);
            this._recomputeAllFieldsPrefixStrip();     // 改完看是否还有可 strip 的(自己 + 其他行)
            this._setFieldSpellWarning(f, null);       // key 改了之前的拼写检查作废
            this._scheduleSave();
            this._toast('字段已重命名：' + newKey, 'success');
        },
        // plan 19 v11:Model / Controller class 编辑(走 _scheduleSave debounced)
        setTableModelClass(ev) {
            this.tableModelClass = (ev && ev.target) ? ev.target.value : '';
            this._scheduleSave();
        },
        setTableCtrlClass(ev) {
            this.tableCtrlClass = (ev && ev.target) ? ev.target.value : '';
            this._scheduleSave();
        },
        // chip toggle:从 ev.target.dataset.app 读 app key,切换 add / remove
        toggleTableCtrlApp(ev) {
            const key = ev?.target?.closest?.('[data-app]')?.dataset?.app;
            if (!key) return;
            const i = this.tableCtrlApps.indexOf(key);
            if (i >= 0) this.tableCtrlApps.splice(i, 1);
            else this.tableCtrlApps.push(key);
            this._scheduleSave();
        },
        // v11.5:单字段索引行 × 删除(实际是把 fields[i].index 改成 'none'),primary 禁删
        // v11.6:加二次确认(scaffoldConfirm Promise API)
        async removeSingleIndex(ev) {
            const tr = ev?.target?.closest?.('tr');
            const field = tr?.dataset?.field;
            if (!field) return;
            const idx = this.fields.findIndex(f => f && f.key === field);
            if (idx < 0) return;
            const f = this.fields[idx];
            if (f.index === 'primary') {
                this._toast('primary 主键不可删', 'warning');
                return;
            }
            const ok = await window.scaffoldConfirm({
                title: '删除索引',
                message: '确认删除「' + field + '」的单字段索引？',
                confirmLabel: '删除',
                danger: true,
            });
            if (!ok) return;
            f.index = 'none';
            this._scheduleSave();
        },
        toggleTableCtrlResource(ev) {
            const key = ev?.target?.closest?.('[data-app]')?.dataset?.app;
            if (!key) return;
            const i = this.tableCtrlResources.indexOf(key);
            if (i >= 0) this.tableCtrlResources.splice(i, 1);
            else this.tableCtrlResources.push(key);
            this._scheduleSave();
        },
        // plan 19 v11 + CSP build:模板 :class 拒 method call / ternary,
        // 走 flat object + 单层属性访问。blade 端 `chipClass.app_admin` 形式读。
        // 注意:必须为 _allApps 里每个 key 都填值(active='is-active' / inactive=''),
        //       否则 inactive 时是 undefined,CSP evaluator 会 warn(虽然渲染仍正确)。
        get chipClass() {
            const out = {};
            (this._allApps || []).forEach(a => {
                out['app_' + a] = this.tableCtrlApps.indexOf(a) >= 0 ? 'is-active' : '';
                out['res_' + a] = this.tableCtrlResources.indexOf(a) >= 0 ? 'is-active' : '';
            });
            return out;
        },
        setBatchInput(ev)  { this.batchInput  = (ev && ev.target) ? ev.target.value : ''; this._saveBatchDraft(); },
        setBatchAfter(ev)  { this.batchAfter  = (ev && ev.target) ? ev.target.value : ''; this._saveBatchDraft(); },
        toggleBatchLenient(ev) { this.batchLenient = (ev && ev.target) ? !!ev.target.checked : !this.batchLenient; this._saveBatchDraft(); },
        setNewTableKey(ev)    { this.newTableKey    = (ev && ev.target) ? ev.target.value : ''; },
        setNewTableName(ev)   { this.newTableName   = (ev && ev.target) ? ev.target.value : ''; },
        setNewTableDesc(ev)   { this.newTableDesc   = (ev && ev.target) ? ev.target.value : ''; },
        setNewTablePrefix(ev) { this.newTablePrefix = (ev && ev.target) ? ev.target.value : ''; },     // v8 D4
        setAddFieldKey(ev)     { this.addFieldKey     = (ev && ev.target) ? ev.target.value : ''; },
        setAddFieldName(ev)    { this.addFieldName    = (ev && ev.target) ? ev.target.value : ''; },
        setAddFieldType(ev)    { this.addFieldType    = (ev && ev.target) ? ev.target.value : ''; },
        setAddFieldSize(ev)    { this.addFieldSize    = (ev && ev.target) ? ev.target.value : ''; },
        setAddFieldDefault(ev) { this.addFieldDefault = (ev && ev.target) ? ev.target.value : ''; },
        setAddFieldIndex(ev)   { this.addFieldIndex   = (ev && ev.target) ? ev.target.value : 'none'; },
        setAddFieldComment(ev) { this.addFieldComment = (ev && ev.target) ? ev.target.value : ''; },
        setAddFieldAfter(ev)   { this.addFieldAfter   = (ev && ev.target) ? ev.target.value : ''; },
        toggleAddFieldNullable(ev) { this.addFieldNullable = (ev && ev.target) ? !!ev.target.checked : !this.addFieldNullable; },
        toggleAddFieldUnsigned(ev) { this.addFieldUnsigned = (ev && ev.target) ? !!ev.target.checked : !this.addFieldUnsigned; },
        // 派生:可作为"插入在...之后"目标的字段(排除 readonly tail:deleted_at/created_at/updated_at)
        // CSP build 模板不能拼字符串,这里直接预算 label 文案;selected flag 避开 v12.5.4 alpine async 预选问题
        _insertAfterOptions(selected) {
            const tail = ['deleted_at', 'created_at', 'updated_at'];
            return (this.fields || [])
                .filter(f => f && f.key && !(f.row_readonly && tail.indexOf(f.key) >= 0))
                .map(f => ({ key: f.key, label: '在 ' + f.key + ' 之后', selected: f.key === selected }));
        },
        get addFieldAfterOptions() { return this._insertAfterOptions(this.addFieldAfter || ''); },
        get batchAfterOptions()    { return this._insertAfterOptions(this.batchAfter || ''); },
        // CSP build 不允许 x-text 写三元,搬这里
        get translateBtnLabel()    { return this.translating ? '翻译中…' : '翻译并追加'; },
        // 2026-05-21:enum group AI 翻译按钮 label(同 translating flag,文案不同)
        get aiBtnLabel()           { return this.translating ? '翻译中…' : 'AI 翻译'; },
        // 2026-05-21:user 手输过 idx name 标 touched,toggle 字段时不再自动覆盖
        multiIdxNameTouched: false,
        setMultiIdxName(ev)   {
            this.multiIdxName = (ev && ev.target) ? ev.target.value : '';
            this.multiIdxNameTouched = true;
        },
        setMultiIdxType(ev)   { this.multiIdxType   = (ev && ev.target) ? ev.target.value : 'index'; },
        // v11.7:multiIdxFields 改 array + chip toggle(支持 multi-select,无需手输 + 逗号)
        toggleMultiIdxField(ev) {
            const key = ev?.target?.closest?.('[data-field]')?.dataset?.field;
            if (!key) return;
            const i = this.multiIdxFields.indexOf(key);
            if (i >= 0) this.multiIdxFields.splice(i, 1);
            else this.multiIdxFields.push(key);
            // 2026-05-21:user 没手输过 idx name → 自动以 字段名_join + _idx 填充,避免每次手敲
            if (! this.multiIdxNameTouched) {
                this.multiIdxName = this.multiIdxFields.length > 0
                    ? this.multiIdxFields.join('_') + '_idx'
                    : '';
            }
        },
        // chip class map(CSP build :class 拒动态 key 表达式 → 走预算 prop access)
        get multiIdxFieldChips() {
            return (this.fields || [])
                .filter(f => f && f.key)     // 包含 readonly,允许 created_at 等加多字段索引
                .map(f => ({
                    __rowId: 'midxchip_' + f.key,
                    field: f.key,
                    chip_class: this.multiIdxFields.indexOf(f.key) >= 0 ? 'is-active' : '',
                }));
        },

        // 2026-05-23:改名 popover + 行 ⋯ 菜单一并删除
        //   改名走 key 列行内 input → setFieldKey → rename_hint 自动塞,renameColumn migration
        //   同样保数据。删除走行末直接 × 按钮(can_remove 守护),不再需要 popover wrapper。
        //   移除:renameOpen/renameIdx/renameFrom/renameTo state,startRenameFromEvent/cancelRename/
        //   confirmRename/toggleRowMenu/closeRowMenu 函数,_rowMenuOpen 派生字段。


        // —— 删除 ——
        // v11.6:统一加二次确认(scaffoldConfirm),danger 风格
        async removeFieldFromEvent(ev) {
            const idx = this._findIdxByEvent(ev);
            if (idx < 0) return;
            const f = this.fields[idx];
            // row_readonly 语义是"行内 attr 不可改"(system timestamps),不等于"不能整行删";
            // 删除守护以 can_remove 为单一真源,跟 shapeField + view x-show 判定一致(只拦 id)
            if (!f || !f.can_remove) return;
            const ok = await window.scaffoldConfirm({
                title: '删除字段',
                message: '确认删除字段「' + f.key + '」?\n该字段及其所有 attrs / 索引绑定都会丢失。',
                confirmLabel: '删除',
                danger: true,
            });
            if (!ok) return;
            const delKey  = f.key;
            const origKey = f._orig_key || f.key;
            this.fields.splice(idx, 1);
            // 清理依赖该字段的状态,否则保存后留下孤儿 enum / 悬空索引引用(migrate 会在已删列上
            // 建索引 → SQL 报错)/ 无效改名 hint(2026-06-09 修;改名 setFieldKey 早就同步了 enum,
            // 删除这条路径之前漏了)。
            this.enumGroups = this.enumGroups.filter(g => g.field !== delKey);
            this.multiIndexes = this.multiIndexes
                .map(m => ({
                    ...m,
                    fields_str: (m.fields_str || '').split(/[,，\s]+/).filter(s => s && s !== delKey).join(', '),
                }))
                .filter(m => (m.fields_str || '').trim() !== '');     // 字段被删空的索引整条丢弃
            if (Object.prototype.hasOwnProperty.call(this.renameHints, origKey)) {
                delete this.renameHints[origKey];
            }
            this._recomputeMoveFlags();     // 删行后相邻行的上下移边界变了
            // 注意:不再调 this.closeRowMenu() —— 行 ⋯ 菜单 2026-05-23 已删(连同该方法),
            // 但这条调用漏删了,会抛 "closeRowMenu is not a function" 且发生在 _scheduleSave 之前
            // → 删字段抛错、保存没跑、reload 后字段又回来(2026-06-09 修)。
            this._scheduleSave();
        },

        // v6.2 round 3:打开 modal 后聚焦第一个 input(setTimeout 让 x-show transition 完成)
        _focusEl(id) {
            setTimeout(() => { const el = document.getElementById(id); if (el) el.focus(); }, 60);
        },

        // v6.2 round 7:删表
        openDeleteTable() {
            this.deleteTableOpen = true;
            this.deleteTableConfirm = '';
            this._focusEl('del-confirm');
        },
        cancelDeleteTable() {
            if (!this.deleteTableOpen) return;
            this.deleteTableOpen = false;
            this.deleteTableConfirm = '';
        },
        setDeleteTableConfirm(ev) { this.deleteTableConfirm = ev?.target?.value || ''; },
        async confirmDeleteTable() {
            if (!this.deleteTableCanConfirm) return;
            if (!this.deleteTableEndpoint) { this._toast('未选表，无法删', 'warning'); return; }
            this.deletingTable = true;
            try {
                const data = await this._post(this.deleteTableEndpoint, { confirm_key: this.deleteTableConfirm }, { method: 'DELETE' });
                this._toast('已删表 yaml 节点。' + (data.note || ''), 'success');
                this.cancelDeleteTable();
                setTimeout(() => { window.location.href = data.redirect_url || '/scaffold/db/designer'; }, 800);
            } catch (e) {
                this._toast('删表失败：' + (e.message || e.code), 'danger');
            } finally {
                this.deletingTable = false;
            }
        },

        // #4 新建 schema(模块)
        openNewSchema() {
            this.newSchemaOpen = true;
            this.newSchemaKey = '';
            this.newSchemaName = '';
            this.newSchemaDesc = '';
            this._focusEl('newschema-key');
        },
        cancelNewSchema() {
            this.newSchemaOpen = false;
            this.newSchemaKey = '';
            this.newSchemaName = '';
            this.newSchemaDesc = '';
        },
        setNewSchemaKey(ev)  { this.newSchemaKey  = ev?.target?.value || ''; },
        setNewSchemaName(ev) { this.newSchemaName = ev?.target?.value || ''; },
        setNewSchemaDesc(ev) { this.newSchemaDesc = ev?.target?.value || ''; },
        async confirmNewSchema() {
            const key = (this.newSchemaKey || '').trim();
            const name = (this.newSchemaName || '').trim();
            if (!key) { this._toast('模块名必填', 'warning'); return; }
            if (!/^[A-Z][A-Za-z0-9]*$/.test(key)) {
                this._toast('模块名须 PascalCase（大写字母开头）', 'warning');
                return;
            }
            if (!name) { this._toast('显示名必填', 'warning'); return; }
            if (!this.createSchemaEndpoint) { this._toast('createSchema 端点未配置', 'warning'); return; }
            this.creatingSchema = true;
            try {
                const data = await this._post(this.createSchemaEndpoint, {
                    schema: key,
                    name: name,
                    desc: (this.newSchemaDesc || '').trim(),
                });
                this._toast('已创建 schema:' + key, 'success');
                this.cancelNewSchema();
                setTimeout(() => { window.location.href = data.redirect_url; }, 600);
            } catch (e) {
                this._toast('创建失败：' + (e.message || e.code), 'danger');
            } finally {
                this.creatingSchema = false;
            }
        },

        // —— 草稿态 schema 改名 / 删除 ——
        // ⋯ 菜单 toggle(同时关其它打开的)
        toggleSchemaMenu(ev) {
            const key = ev?.currentTarget?.dataset?.schema || '';
            this.schemaMenuOpenKey = this.schemaMenuOpenKey === key ? '' : key;
            ev?.stopPropagation?.();
            this._syncSchemaMenuDom();
        },
        closeSchemaMenu() { this.schemaMenuOpenKey = ''; this._syncSchemaMenuDom(); },
        // Alpine CSP build x-show method 内 this.$el = component root,不是 popover;
        // 模板 method('literal') 也拒(陷阱 #9)。绕开 — DOM 直操作:遍历所有 popover,
        // 按 closest 卡的 data-schema-key 跟 schemaMenuOpenKey 比较 set display。
        _syncSchemaMenuDom() {
            const openKey = this.schemaMenuOpenKey;
            document.querySelectorAll('.p-designer-card__menu-popover').forEach(pop => {
                const card = pop.closest('.p-designer-card');
                if (!card) return;
                pop.style.display = (card.dataset.schemaKey === openKey) ? 'flex' : 'none';
            });
        },

        // 重命名
        openRenameSchema(ev) {
            const key = ev?.currentTarget?.dataset?.schema || '';
            if (!key) return;
            this.schemaMenuOpenKey = '';
            this._syncSchemaMenuDom();
            this.renameSchemaCurrentKey = key;
            this.renameSchemaNewKey = key;
            this.renameSchemaOpen = true;
            this._focusEl('renameschema-newkey');
        },
        cancelRenameSchema() {
            this.renameSchemaOpen = false;
            this.renameSchemaCurrentKey = '';
            this.renameSchemaNewKey = '';
        },
        setRenameSchemaNewKey(ev) { this.renameSchemaNewKey = ev?.target?.value || ''; },
        async confirmRenameSchema() {
            if (this.renamingSchema) return;
            const oldKey = this.renameSchemaCurrentKey;
            const newKey = (this.renameSchemaNewKey || '').trim();
            if (!newKey) { this._toast('新模块名必填', 'warning'); return; }
            if (!/^[A-Z][A-Za-z0-9]*$/.test(newKey)) {
                this._toast('新模块名须 PascalCase（大写字母开头）', 'warning');
                return;
            }
            if (newKey === oldKey) { this.cancelRenameSchema(); return; }
            this.renamingSchema = true;
            try {
                const url = '/scaffold/db/designer/schemas/' + encodeURIComponent(oldKey);
                const data = await this._post(url, { new_name: newKey }, { method: 'PUT' });
                this._toast('已改名：' + oldKey + ' → ' + newKey, 'success');
                this.cancelRenameSchema();
                setTimeout(() => { window.location.href = data.redirect_url || '/scaffold/db/designer'; }, 600);
            } catch (e) {
                this._toast('改名失败：' + (e.message || e.code), 'danger');
            } finally {
                this.renamingSchema = false;
            }
        },

        // 表 key 改名(show 页;锁定表不挂入口)。成功后 reload 到 ?table=新key。
        openRenameTable() {
            this.renameTableCurrentKey = this.tableKey;
            this.renameTableNewKey = this.tableKey;
            this.renameTableOpen = true;
        },
        cancelRenameTable() {
            this.renameTableOpen = false;
            this.renameTableCurrentKey = '';
            this.renameTableNewKey = '';
        },
        setRenameTableNewKey(ev) { this.renameTableNewKey = (ev && ev.target) ? ev.target.value : ''; },
        async confirmRenameTable() {
            if (this.renamingTable) return;
            const oldKey = this.renameTableCurrentKey;
            const newKey = (this.renameTableNewKey || '').trim();
            if (!newKey) { this._toast('新表 key 必填', 'warning'); return; }
            if (!/^[a-z][a-z0-9_]*$/.test(newKey)) {
                this._toast('新表 key 须 snake_case（小写字母开头，小写字母 / 数字 / 下划线）', 'warning');
                return;
            }
            if (newKey === oldKey) { this.cancelRenameTable(); return; }
            this.renamingTable = true;
            try {
                const data = await this._post(this.renameTableEndpoint, { new_key: newKey }, { method: 'PUT' });
                this._toast('已改名：' + oldKey + ' → ' + newKey, 'success');
                // 2026-07-04 闭环:有 migration 的表改名返回 note(自动生成的 rename migration / 失败兜底提示)
                if (data.note) this._toast(data.note, data.migration_file ? 'info' : 'warning');
                this.cancelRenameTable();
                setTimeout(() => { window.location.href = data.redirect_url || ('/scaffold/db/designer/' + this.schema); }, data.note ? 2200 : 600);
            } catch (e) {
                this._toast('改名失败：' + (e.message || e.code), 'danger');
            } finally {
                this.renamingTable = false;
            }
        },

        // 删除
        openDeleteSchema(ev) {
            const key = ev?.currentTarget?.dataset?.schema || '';
            if (!key) return;
            this.schemaMenuOpenKey = '';
            this._syncSchemaMenuDom();
            this.deleteSchemaCurrentKey = key;
            this.deleteSchemaConfirm = '';
            this.deleteSchemaOpen = true;
            this._focusEl('deleteschema-confirm');
        },
        cancelDeleteSchema() {
            this.deleteSchemaOpen = false;
            this.deleteSchemaCurrentKey = '';
            this.deleteSchemaConfirm = '';
        },
        setDeleteSchemaConfirm(ev) { this.deleteSchemaConfirm = ev?.target?.value || ''; },
        async confirmDeleteSchema() {
            if (!this.deleteSchemaCanConfirm) return;
            const key = this.deleteSchemaCurrentKey;
            this.deletingSchema = true;
            try {
                const url = '/scaffold/db/designer/schemas/' + encodeURIComponent(key);
                const data = await this._post(url, { confirm_key: key }, { method: 'DELETE' });
                this._toast('已删：' + key, 'success');
                this.cancelDeleteSchema();
                setTimeout(() => { window.location.href = data.redirect_url || '/scaffold/db/designer'; }, 600);
            } catch (e) {
                this._toast('删除失败：' + (e.message || e.code), 'danger');
            } finally {
                this.deletingSchema = false;
            }
        },

        // —— 新建表 ——
        openNewTable() {
            this.newTableOpen = true;
            this.newTableKey = '';
            this.newTableName = '';
            this.newTableDesc = '';
            this.newTablePrefix = '';
            this._focusEl('newtable-key');
        },
        cancelNewTable() {
            if (!this.newTableOpen) return;     // plan-37 后审 P1:visible 守护
            this.newTableOpen = false;
            this.newTableKey = '';
            this.newTableName = '';
            this.newTableDesc = '';
            this.newTablePrefix = '';
        },
        async confirmNewTable() {
            if (this.creatingTable) return;
            const key = (this.newTableKey || '').trim();
            const name = (this.newTableName || '').trim();
            if (!key) { this._toast('表 key 必填', 'warning'); return; }
            if (!name) { this._toast('显示名必填', 'warning'); return; }
            if (!/^[a-z][a-z0-9_]*$/.test(key)) {
                this._toast('表 key 必须 snake_case（小写字母开头，字母数字下划线）', 'warning');
                return;
            }
            this.creatingTable = true;
            try {
                const data = await this._post(this.createTableEndpoint, {
                    table_key: key,
                    name: name,
                    desc: (this.newTableDesc || '').trim(),
                    prefix: (this.newTablePrefix || '').trim(),     // v8 D4
                });
                this._toast('表已创建，跳转中…', 'success');
                window.location.href = data.redirect_url;
            } catch (e) {
                this._toast('创建失败：' + (e.message || e.code), 'danger');
            } finally {
                this.creatingTable = false;
            }
        },

        // v12.5:字段默认值在有 enum 时改 select(保证值的正确性)
        //   每个 field f 派生:has_enum / no_enum / enum_options[{key, label}]
        //   CSP build 不允许 `!f.has_enum`,故拆 2 个独立属性
        _recomputeFieldEnum(f) {
            if (!f) return;
            // v12.5.3:string-normalize default(server yaml 可能 default:1 number,option.value 总是 string)
            f.default_str = (f.default == null) ? '' : String(f.default);
            const g = this.enumGroups.find(x => x.field === f.key);
            // v12.5.1:option value 用 it.value(数字 ID,实际存 DB)而非 it.key(英文常量名)
            //          只显示 value 已就绪的项(value 空 = 用户还在编辑,skip)
            const items = g ? g.items.filter(it => it && it.value !== '' && it.value != null) : [];
            if (items.length > 0) {
                f.has_enum = true;
                f.no_enum = false;
                f.enum_options = items.map(it => {
                    const zh = it.label_zh || it.key || '';
                    const v = String(it.value);
                    return {
                        value: v,
                        label: zh ? (v + ' · ' + zh) : v,
                        // v12.5.4:Alpine <template x-for> 异步插 option,select :value 失效;改用 option :selected
                        selected: v === f.default_str,
                    };
                });
                f.empty_selected = (f.default_str === '');
            } else {
                f.has_enum = false;
                f.no_enum = true;
                f.enum_options = [];
                f.empty_selected = false;
            }
        },
        _recomputeAllFieldsEnum() {
            (this.fields || []).forEach(f => this._recomputeFieldEnum(f));
        },

        // —— F36 枚举 CRUD(per field enum entries)——
        _findEnumEntry(ev) {
            const tr = ev?.target?.closest?.('tr');
            const erk = tr?.dataset?.erk;
            if (!erk) return { gi: -1, ri: -1 };
            for (let gi = 0; gi < this.enumGroups.length; gi++) {
                const ri = this.enumGroups[gi].items.findIndex(r => r.__rowId === erk);
                if (ri >= 0) return { gi, ri };
            }
            return { gi: -1, ri: -1 };
        },
        _setEnumAttr(ev, attr) {
            const { gi, ri } = this._findEnumEntry(ev);
            if (gi < 0) return;
            this.enumGroups[gi].items[ri][attr] = ev?.target?.value || '';
            this._scheduleSave();
        },
        // 2026-05-21:enum group 级 AI 翻译 — 扫该 group 所有 key='' + label_zh!='' 的 row,
        // 一次性 POST translate scene=enums,按 input index 把 output 填回 key + label_en。
        async aiTranslateEnumGroup(ev) {
            if (this.translating) return;     // 复用 fields 翻译 in-flight flag,防并发
            const groupEl = ev?.target?.closest?.('[data-egroup]');
            const field = groupEl?.dataset?.egroup;
            if (!field) return;
            const g = (this.enumGroups || []).find(x => x.field === field);
            if (!g) return;
            // 选取待翻译 row:key 空 + label_zh 非空(只翻有中文 hint 的)
            const pending = (g.items || [])
                .map((r, i) => ({ row: r, idx: i }))
                .filter(p => (!p.row.key || p.row.key === '') && p.row.label_zh && p.row.label_zh.trim() !== '');
            if (pending.length === 0) {
                this._toast('没有需要翻译的项（每行 key 已填或中文标签为空）', 'info');
                return;
            }
            const inputs = pending.map(p => p.row.label_zh.trim());

            this.translating = true;
            try {
                const data = await this._post(this.translateEndpoint, {
                    scene: 'enums',
                    field: field,
                    inputs: inputs,
                });
                const results = (data && data.results) || [];
                let okCount = 0;
                let failCount = 0;
                results.forEach((r, i) => {
                    const p = pending[i];
                    if (!p) return;
                    if (r.valid && r.output) {
                        g.items[p.idx].key = r.output;
                        if (r.label_en) g.items[p.idx].label_en = r.label_en;
                        okCount++;
                    } else {
                        failCount++;
                    }
                });
                this._recomputeAllFieldsEnum();
                this._scheduleSave();
                if (okCount > 0 && failCount === 0) {
                    this._toast('翻译完成 ' + okCount + ' 条', 'success');
                } else if (okCount > 0 && failCount > 0) {
                    this._toast('翻译完成 ' + okCount + ' 条，' + failCount + ' 条失败（看具体 row.reason）', 'warning');
                } else {
                    this._toast('翻译全部失败，请检查中文标签或重试', 'danger');
                }
            } catch (e) {
                if (e.code === 'AI_NOT_CONFIGURED') {
                    this._toast('AI 未配置（SCAFFOLD_AI_API_KEY），无法翻译', 'danger');
                } else {
                    this._toast('翻译失败：' + (e.message || e.code), 'danger');
                }
            } finally {
                this.translating = false;
            }
        },
        // 2026-05-21:enum 行级 AI 重译 — 不论 key 是否已填,都用 label_zh 重新翻译覆盖 key + label_en。
        // 跟 group 级 aiTranslateEnumGroup 共享 translating in-flight flag(防并发),
        // 但选取 row 一律走当前行(不像 group 级只挑 key='' + label_zh!='' 的 pending 集)。
        async aiTranslateEnumRow(ev) {
            if (this.translating) return;
            const tr = ev?.target?.closest?.('tr');
            const erk = tr?.dataset?.erk;
            const field = ev?.target?.closest?.('[data-egroup]')?.dataset?.egroup;
            if (!field || !erk) return;
            const g = (this.enumGroups || []).find(x => x.field === field);
            if (!g) return;
            const ri = g.items.findIndex(r => r.__rowId === erk);
            if (ri < 0) return;
            const row = g.items[ri];
            const zh = (row.label_zh || '').trim();
            if (!zh) {
                this._toast('请先填中文标签再翻译', 'warning');
                return;
            }
            this.translating = true;
            try {
                const data = await this._post(this.translateEndpoint, {
                    scene: 'enums',
                    field: field,
                    inputs: [zh],
                });
                const r = (data && data.results && data.results[0]) || null;
                if (r && r.valid && r.output) {
                    row.key = r.output;
                    if (r.label_en) row.label_en = r.label_en;
                    this._recomputeAllFieldsEnum();
                    this._scheduleSave();
                    this._toast('已翻译：' + r.output, 'success');
                } else {
                    this._toast('翻译失败：' + ((r && r.reason) || '未知原因'), 'danger');
                }
            } catch (e) {
                if (e.code === 'AI_NOT_CONFIGURED') {
                    this._toast('AI 未配置（SCAFFOLD_AI_API_KEY），无法翻译', 'danger');
                } else {
                    this._toast('翻译失败：' + (e.message || e.code), 'danger');
                }
            } finally {
                this.translating = false;
            }
        },
        // v12.5.1:option value 改用 it.value 后,value/label_zh/key 任意变都要 recompute
        setEnumKey(ev)      { this._setEnumAttr(ev, 'key'); this._recomputeAllFieldsEnum(); },
        setEnumValue(ev)    { this._setEnumAttr(ev, 'value'); this._recomputeAllFieldsEnum(); },
        setEnumLabelEn(ev)  { this._setEnumAttr(ev, 'label_en'); },
        setEnumLabelZh(ev)  { this._setEnumAttr(ev, 'label_zh'); this._recomputeAllFieldsEnum(); },
        addEnumItem(ev) {
            // button data-group="<field>"
            const field = ev?.target?.closest?.('[data-egroup]')?.dataset?.egroup;
            if (!field) return;
            const g = this.enumGroups.find(x => x.field === field);
            if (!g) return;
            const id = field + ':new:' + Date.now();
            g.items.push({
                __rowId: id,
                key: '',
                value: '',
                label_en: '',
                label_zh: '',
            });
            this._recomputeAllFieldsEnum();
            this._scheduleSave();
        },
        async removeEnumItem(ev) {
            const { gi, ri } = this._findEnumEntry(ev);
            if (gi < 0) return;
            const item = this.enumGroups[gi].items[ri];
            const label = (item && item.key) ? ('「' + item.key + '」') : '此项';
            const ok = await window.scaffoldConfirm({
                title: '删除枚举项',
                message: '确认删除' + label + '?',
                confirmLabel: '删除',
                danger: true,
            });
            if (!ok) return;
            this.enumGroups[gi].items.splice(ri, 1);
            this._recomputeAllFieldsEnum();
            this._scheduleSave();
        },
        // F37 加 enum group(新枚举)
        openAddEnumGroup() {
            this.newEnumGroupOpen = true;
            this.newEnumGroupField = '';
            this._focusEl('newenum-field');
        },
        cancelAddEnumGroup() {
            if (!this.newEnumGroupOpen) return;
            this.newEnumGroupOpen = false;
            this.newEnumGroupField = '';
        },
        setNewEnumGroupField(ev) {
            this.newEnumGroupField = ev?.target?.value || '';
        },
        confirmAddEnumGroup() {
            const field = (this.newEnumGroupField || '').trim();
            if (!field) { this._toast('请选字段', 'warning'); return; }
            if (this.enumGroups.some(g => g.field === field)) { this._toast('该字段已有 enum', 'warning'); return; }
            // 推一个空 group + 一个空 entry(让 user 直接编辑,不用再点"+加项")
            this.enumGroups.push({
                field: field,
                items: [{
                    __rowId: field + ':init:' + Date.now(),
                    key: '', value: '', label_en: '', label_zh: '',
                }],
            });
            this._recomputeAllFieldsEnum();
            this._toast('已加枚举：' + field, 'success');
            this.cancelAddEnumGroup();
            // 注:此时 group items[0].key 是空,save 时 server skip 整 group;user 填 key 后再次 save 才落 yaml
        },
        async removeEnumGroup(ev) {
            const field = ev?.target?.closest?.('[data-egroup]')?.dataset?.egroup;
            if (!field) return;
            const ok = await window.scaffoldConfirm({
                title: '删除枚举组',
                message: '确认删除枚举组「' + field + '」?\n该组所有 entries 都会丢失。',
                confirmLabel: '删除',
                danger: true,
            });
            if (!ok) return;
            const idx = this.enumGroups.findIndex(g => g.field === field);
            if (idx < 0) return;
            this.enumGroups.splice(idx, 1);
            this._recomputeAllFieldsEnum();
            this._scheduleSave();
            this._toast('已删枚举：' + field, 'info');
        },

        // —— F30 多字段索引 CRUD(单字段在字段表 index column 改)——
        openAddMultiIndex() {
            this.multiIdxOpen = true;
            this.multiIdxName = '';
            this.multiIdxNameTouched = false;   // 2026-05-21:每次开 modal reset touched 让自动填充生效
            this.multiIdxType = 'index';
            this.multiIdxFields = [];     // v11.7
            this._focusEl('midx-name');
        },
        cancelAddMultiIndex() {
            if (!this.multiIdxOpen) return;
            this.multiIdxOpen = false;
            this.multiIdxName = '';
            this.multiIdxNameTouched = false;
            this.multiIdxFields = [];     // v11.7
        },
        confirmAddMultiIndex() {
            const name = (this.multiIdxName || '').trim();
            const type = (this.multiIdxType || 'index').trim();
            // v11.7:fields 从 chip toggle 数组直接拿,无需 split / missing 校验(chip 来自实际字段)
            const fields = (this.multiIdxFields || []).slice();
            if (!name) { this._toast('索引名必填', 'warning'); return; }
            if (!/^[a-z][a-z0-9_]*$/.test(name)) {
                this._toast('索引名必须 snake_case', 'warning');
                return;
            }
            if (!['primary', 'unique', 'index'].includes(type)) { this._toast('type 必须 primary/unique/index', 'warning'); return; }
            if (fields.length < 2) { this._toast('多字段索引至少选 2 个字段', 'warning'); return; }
            // 校验 name 不冲突(跟现有 multi + 单字段 index name 都查)
            const allKeys = this.fields.map(f => f.key);
            if (this.multiIndexes.some(m => m.name === name)) { this._toast('索引名冲突：' + name, 'warning'); return; }
            if (allKeys.includes(name) && this.fields.find(f => f.key === name && f.index && f.index !== 'none')) {
                this._toast('索引名冲突单字段 index（' + name + '）', 'warning'); return;
            }
            this.multiIndexes.push({
                __rowId: name + ':' + Date.now(),
                name: name,
                type: type,
                fields_str: fields.join(', '),
            });
            this._scheduleSave();
            this._toast('已加索引：' + name, 'success');
            this.cancelAddMultiIndex();
        },
        async removeMultiIndex(ev) {
            const tr = ev?.target?.closest?.('tr');
            const rk = tr?.dataset?.midx;
            if (!rk) return;
            const idx = this.multiIndexes.findIndex(m => m.__rowId === rk);
            if (idx < 0) return;
            const name = this.multiIndexes[idx].name;
            const ok = await window.scaffoldConfirm({
                title: '删除多字段索引',
                message: '确认删除多字段索引「' + name + '」?',
                confirmLabel: '删除',
                danger: true,
            });
            if (!ok) return;
            this.multiIndexes.splice(idx, 1);
            this._scheduleSave();
            this._toast('已删索引：' + name, 'info');
        },
        setMultiIndexType(ev) {
            // 行内改 type:从 closest tr.data-midx 反查 multi index
            const tr = ev?.target?.closest?.('tr');
            const rk = tr?.dataset?.midx;
            if (!rk) return;
            const idx = this.multiIndexes.findIndex(m => m.__rowId === rk);
            if (idx < 0) return;
            this.multiIndexes[idx].type = ev.target.value;
            this._scheduleSave();
        },
        setMultiIndexFields(ev) {
            // 行内改 fields(逗号分隔)
            const tr = ev?.target?.closest?.('tr');
            const rk = tr?.dataset?.midx;
            if (!rk) return;
            const idx = this.multiIndexes.findIndex(m => m.__rowId === rk);
            if (idx < 0) return;
            this.multiIndexes[idx].fields_str = ev.target.value;
            this._scheduleSave();
        },

        // —— Migration 历史 drill-down ——
        // view 上 <tr :data-file="m.file"> 加 button x-on:click="viewMigration",从 closest('tr') 反查 filename
        async viewMigration(ev) {
            if (this.loadingHistory) return;
            const tr = ev?.target?.closest?.('tr');
            const filename = tr?.dataset?.file;
            if (!filename) return;
            this.loadingHistory = true;
            try {
                const data = await this._get(this.migrationContentBase + '?file=' + encodeURIComponent(filename));
                this.historyView = {
                    has_content: true,
                    file_name: data.filename || filename,
                    php_code: data.php_code || '',
                    php_html: this._highlightPhp(data.php_code || ''),     // #2
                };
                window.dispatchEvent(new CustomEvent('open-drawer', {
                    detail: { name: 'designer-migration-view', trigger: ev?.target || null },
                }));
            } catch (e) {
                this._toast('读取失败：' + (e.message || e.code), 'danger');
            } finally {
                this.loadingHistory = false;
            }
        },

        // 2026-05-21 C+ 方案:删 migration 文件 — 从二连 window.confirm 升级为 modal + checkbox 一次决定。
        // 1) openDeleteMigration:button click → 打开 modal,捕获 file 名
        // 2) modal 内勾选 checkbox 选是否清表 baseline(C+ 高级选项,默认 off)
        // 3) submitDeleteMigration:打 DELETE API,完成 reload
        openDeleteMigration(ev) {
            const btn = ev?.target?.closest('button');
            const file = btn?.dataset?.file;
            if (!file || !this.deleteMigrationEndpointTpl) return;
            this.deleteMigrationFile = file;
            this.deleteMigrationClearBaseline = false;     // 每次开 modal 重置 checkbox(默认安全)
            this.deletingMigration = false;
            this.deleteMigrationOpen = true;
        },
        cancelDeleteMigration() {
            if (!this.deleteMigrationOpen) return;
            this.deleteMigrationOpen = false;
            this.deleteMigrationFile = '';
            this.deleteMigrationClearBaseline = false;
        },
        toggleDeleteMigrationBaseline(ev) {
            this.deleteMigrationClearBaseline = !!ev?.target?.checked;
        },
        async submitDeleteMigration() {
            if (this.deletingMigration) return;
            const file = this.deleteMigrationFile;
            if (!file || !this.deleteMigrationEndpointTpl) return;
            const clearBaseline = !!this.deleteMigrationClearBaseline;
            const stem = file.replace(/\.php$/, '');     // URL 不带 .php(nginx 拦 fastcgi)
            const url = this.deleteMigrationEndpointTpl.replace('FILENAMESTEM', encodeURIComponent(stem));
            this.deletingMigration = true;
            try {
                const data = await this._post(url, {
                    clear_baseline: clearBaseline,
                    table_key: this.tableKey || '',
                }, { method: 'DELETE' });
                const okMsg = data?.baseline_cleared
                    ? '已删除 ' + file + ' + 清表 baseline ✓'
                    : '已删除 ' + file;
                this._toast(okMsg, 'success');
                this.deleteMigrationOpen = false;
                setTimeout(() => window.location.reload(), 400);
            } catch (e) {
                this._toast('删除失败：' + (e.message || e.code), 'danger');
                this.deletingMigration = false;
            }
        },

        // plan-49 —— 合并 migration 历史(compact)——
        async openCompactMigrations() {
            if (!this.tableKey) return;
            // reset state
            this.compactOpen = true;
            this.compactLoading = true;
            this.compactPreviewLoaded = false;
            this.compactBlocked = false;
            this.compactBlockedReason = '';
            this.compactBlockedMsg = '';
            this.compactCreateFile = '';
            this.compactUpdateFiles = [];
            this.compactPreviewPhp = '';
            this.compactDrift = [];
            this.compactGitPushed = [];
            this.compactCleanDb = false;
            this.compactForceAck = false;
            try {
                const data = await this._post(this.compactPreviewEndpoint, { table: this.tableKey });
                this.compactCreateFile = data.create_file || '';
                this.compactUpdateFiles = data.update_files || [];
                this.compactPreviewPhp = data.preview_php || '';
                this.compactDrift = data.schema_drift || [];
                this.compactGitPushed = data.git_pushed || [];
                this.compactPreviewLoaded = true;
            } catch (e) {
                this.compactBlocked = true;
                this.compactBlockedReason = e.code === 'COMPACT_BLOCKED' ? (e.detail?.reason || 'blocked') : (e.code || 'error');
                this.compactBlockedMsg = e.message || '未知错误';
            } finally {
                this.compactLoading = false;
            }
        },
        cancelCompact() {
            if (this.compactRunning) return;     // 跑着不让关
            this.compactOpen = false;
        },
        toggleCompactCleanDb(ev) {
            this.compactCleanDb = ev && ev.target ? !!ev.target.checked : !this.compactCleanDb;
        },
        toggleCompactForceAck(ev) {
            this.compactForceAck = ev && ev.target ? !!ev.target.checked : !this.compactForceAck;
        },
        async confirmCompact() {
            if (this.compactRunning || this.compactBlocked || !this.compactPreviewLoaded) return;
            // 已 push 时,必须勾过「未在服务器部署」确认框才放行(button 已 disable,这里再兜一层)
            if (this.compactGitPushed.length > 0 && !this.compactForceAck) return;
            this.compactRunning = true;
            try {
                const data = await this._post(this.compactExecuteEndpoint, {
                    table: this.tableKey,
                    clean_db: this.compactCleanDb,
                    force: this.compactForceAck,     // 仅已 push + 人工确认未部署时为 true,绕过后端 git_pushed 兜底
                });
                let msg = '合并完成：1 create + ' + (data.deleted?.length || 0) + ' update → 1 create';
                if (data.db_cleaned) msg += '（清 migrations 表 ' + data.db_cleaned + ' 条）';
                this._toast(msg, 'success');
                this.compactOpen = false;
                setTimeout(() => window.location.reload(), 600);
            } catch (e) {
                this._toast('合并失败：' + (e.message || e.code), 'danger');
                this.compactRunning = false;
            }
        },

        // —— 加单字段(无 AI 依赖)——
        openAddField() {
            this.addFieldOpen = true;
            // 表设了 prefix 就预填进字段 key,省一次手敲;用户可改可删
            this.addFieldKey      = this.tablePrefix || '';
            this.addFieldName     = '';
            this.addFieldType     = 'varchar';
            this.addFieldSize     = '64';
            this.addFieldDefault  = '';
            this.addFieldIndex    = 'none';
            this.addFieldNullable = false;
            this.addFieldUnsigned = false;
            this.addFieldComment  = '';
            // 默认插入位置:最后一个可插入字段之后(即 tail readonly 字段之前)
            const opts = this.addFieldAfterOptions;
            this.addFieldAfter = opts.length ? opts[opts.length - 1].key : '';
            this._focusEl('addfield-key');
        },
        cancelAddField() {
            if (!this.addFieldOpen) return;     // plan-37 后审 P1:visible 守护
            this.addFieldOpen     = false;
            this.addFieldKey      = '';
            this.addFieldName     = '';
            this.addFieldDefault  = '';
            this.addFieldComment  = '';
            this.addFieldNullable = false;
            this.addFieldUnsigned = false;
            this.addFieldIndex    = 'none';
            this.addFieldAfter    = '';
        },
        confirmAddField() {
            const key  = (this.addFieldKey || '').trim();
            const name = (this.addFieldName || '').trim();
            const type = (this.addFieldType || 'varchar').trim();
            const size = (this.addFieldSize || '').trim();
            const def  = (this.addFieldDefault || '').trim();
            const cmt  = (this.addFieldComment || '').trim();
            if (!key) { this._toast('字段 key 必填', 'warning'); return; }
            if (!/^[a-z][a-z0-9_]*$/.test(key)) {
                this._toast('字段 key 必须 snake_case（小写字母开头，字母数字下划线）', 'warning');
                return;
            }
            if (this.fields.some(f => f && f.key === key)) {
                this._toast('字段 key 已存在：' + key, 'warning');
                return;
            }
            // 插入位置:user 选的"在 X 之后"(默认是最后一个非 tail 字段);兜底用 tail 之前
            let insertAt = -1;
            if (this.addFieldAfter) {
                const idx = this.fields.findIndex(f => f && f.key === this.addFieldAfter);
                if (idx >= 0) insertAt = idx + 1;
            }
            if (insertAt < 0) {
                insertAt = this.fields.findIndex(f => f && f.row_readonly && f.key !== 'id');
                if (insertAt < 0) insertAt = this.fields.length;
            }
            // plan 19 v8 C4:新字段 shape 必须跟 SchemaLoader::shapeField 对齐,
            // 漏掉 derived(unsigned_disabled / can_rename / row_title 等)→ Alpine CSP 模板访问 f.X 找不到 → warn
            const newField = {
                __rowId: key,                  // session 内 stable id,跟 saveModule 重建后 reload 派生的 __rowId 一致
                key: key,
                _orig_key: key,                // F39 rename 锚点
                name: name || null,
                type: type,
                size: size && /^\d+$/.test(size) ? parseInt(size, 10) : null,
                default: def !== '' ? def : null,
                required: !this.addFieldNullable,
                unsigned: !!this.addFieldUnsigned,
                nullable: !!this.addFieldNullable,
                index: this.addFieldIndex || 'none',
                comment: cmt !== '' ? cmt : null,
                row_readonly: false,
                name_readonly: false,
                row_class: '',
                index_disabled: false,
                _rowMenuOpen: false,
                size_class: '',
                size_title: '',
                default_class: '',
                default_title: '',
                // C4 补全 derived(跟 batch-add 那一处 + PHP shapeField 对齐)
                // 2026-05-22:unsigned_disabled / precision_disabled 必须根据 type 派生,
                // 否则新加 bigint 字段表里 unsigned col 视觉 disabled、decimal 字段 precision 不可编辑
                unsigned_disabled: ! ['bigint', 'int', 'tinyint', 'smallint', 'mediumint', 'decimal', 'float', 'double'].includes(type),
                precision: null,
                precision_disabled: ! ['decimal', 'float', 'double'].includes(type),
                format: null,
                // 2026-05-22:user 在 addField modal 勾了 nullable/unsigned 必须设 dirty,否则
                // _buildFieldEntry 只在 dirty=true 才把 required/unsigned 发到 server,save 后
                // yaml 不写 unsigned/required,reload 默认 false 又消失(user 反馈"还要手勾一次")
                _nullable_dirty: !!this.addFieldNullable,
                _unsigned_dirty: !!this.addFieldUnsigned,
                can_rename: true,
                can_remove: true,
                row_menu_disabled: false,
                row_title: '',
                // v12.5 enum 派生(新字段默认无 enum)
                has_enum: false,
                no_enum: true,
                enum_options: [],
                default_str: '',     // v12.5.3 select 用 string-normalized default
                prefix_strippable: false,     // 2026-05-21:_recomputeFieldPrefixStrip 跟 PHP shape 对齐
                prefix_strip_btn_class: 'p-designer-fields__row-btn p-designer-fields__row-btn--strip is-placeholder',
                prefix_strip_disabled: true,
                // 2026-05-21:拼写检查默认无 warning(后续 aiSpellCheckFields 写入)
                spell_warning: '',
                spell_suggestion: '',
                spell_reason: '',
                spell_warn_class: 'p-designer-fields__spell-warn is-placeholder',
                has_spell_warning: false,
            };
            this._revalidateSize(newField);
            this._revalidateDefault(newField);
            this.fields.splice(insertAt, 0, newField);
            this._autoDetectAdvancedCols();                // 新字段带精度/format 时自动展开两列
            this._recomputeFieldPrefixStrip(newField);     // 新字段可能就带 prefix(addFieldKey 预填了 prefix)
            this._recomputeMoveFlags();                    // 新行 + 相邻行的上下移边界
            this._scheduleSave();
            this._toast('已加字段：' + key, 'success');
            this.cancelAddField();
        },

        // —— 批量加字段 ——
        // 批量翻译草稿持久化(localStorage):防刷新/误关丢失;按 schema+table 隔离 key
        _batchDraftKey() { return 'scaffold:designer:batch-draft:' + (this.schema || '') + ':' + (this.tableKey || ''); },
        _loadBatchDraft() {
            try { const raw = localStorage.getItem(this._batchDraftKey()); return raw ? JSON.parse(raw) : null; }
            catch (e) { return null; }
        },
        _saveBatchDraft() {
            if (!this.tableKey) return;
            const previewArr = Array.isArray(this.translatePreviewResults) ? this.translatePreviewResults : [];
            const draft = {
                input: this.batchInput || '',
                after: this.batchAfter || '',
                lenient: !!this.batchLenient,
                preview: previewArr.length ? previewArr : null,
            };
            // 全空就当用户已清,不留垃圾
            if (!draft.input && !draft.preview) { this._clearBatchDraft(); return; }
            try { localStorage.setItem(this._batchDraftKey(), JSON.stringify(draft)); } catch (e) {}
            this.hasBatchDraft = true;
        },
        _clearBatchDraft() {
            try { localStorage.removeItem(this._batchDraftKey()); } catch (e) {}
            this.hasBatchDraft = false;
        },
        // 仅探测草稿(不弹 modal),设红点标志位让"+ 批量加 (AI)"按钮显示提醒
        _detectBatchDraft() {
            const d = this._loadBatchDraft();
            if (!d) { this.hasBatchDraft = false; return; }
            const hasInput = !!(d.input && d.input.trim());
            const hasPreview = Array.isArray(d.preview) && d.preview.length > 0;
            this.hasBatchDraft = hasInput || hasPreview;
        },

        openBatch() {
            const draft = this._loadBatchDraft() || {};
            // 有已翻译预览 → 直接打开预览 modal(更前进的状态,优先恢复)
            if (draft.preview && Array.isArray(draft.preview) && draft.preview.length) {
                this.batchInput = draft.input || '';
                this.batchAfter = draft.after || '';
                this.batchLenient = !!draft.lenient;
                this.translatePreviewResults = draft.preview;
                this.batchOpen = false;
                this.translatePreviewOpen = true;
                this._toast('已恢复上次翻译结果（取消才会丢）', 'info');
                return;
            }
            // 否则恢复 batch input modal
            this.batchOpen = true;
            this.batchInput = draft.input || '';
            this.batchLenient = !!draft.lenient;
            if (draft.after) {
                this.batchAfter = draft.after;
            } else {
                const opts = this.batchAfterOptions;
                this.batchAfter = opts.length ? opts[opts.length - 1].key : '';
            }
            if (draft.input) this._toast('已恢复上次输入草稿', 'info');
            this._focusEl('batch-input');
        },
        closeBatch() {
            // 仅关 modal:input/after/lenient 全留,草稿不清。
            // 用户原话:"只要没创建成功,不管有没有翻译,再次打开都恢复,直到创建成功,或手动删除"
            // → 关 modal 算"暂时离开"而非"取消",刷新/重开后仍要能继续。
            // 手动删除 = 用户把 textarea 输入全清(_saveBatchDraft 检测 input/preview 都空自动清掉)。
            if (!this.batchOpen) return;     // plan-37 后审 P1:visible 守护
            this.batchOpen = false;
        },
        get batchDraftTitle() { return this.hasBatchDraft ? '上次有未完成的批量翻译草稿,点击恢复' : '批量从中文字段名生成 key(用 AI 翻译,需配 SCAFFOLD_AI_API_KEY)'; },
        // plan-35 B3:save 失败时给重试按钮用,Alpine CSP build 不能直接 === 比较
        get isSaveError() { return this.savingState === 'error'; },
        async translateAndAdd() {
            if (this.translating) return;     // 防重复点击
            const raw = (this.batchInput || '').trim();
            if (!raw) { this.closeBatch(); return; }
            // 2026-05-21 bug:原 split [\s,，\n] 把空格当分隔符 → "岗位 ID" 误拆成 "岗位" + "ID"
            // (modal 文案明说"逗号或换行分隔"),只允许英中逗号 + 换行,trim 每项首尾空格。
            const items = raw.split(/[,，\n]+/).map(s => s.trim()).filter(s => s.length > 0);

            const prefix = (this.tablePrefix || '').replace(/_$/, '');
            const existing = this.fields.map(f => f.key);

            this.translating = true;
            try {
                const data = await this._post(this.translateEndpoint, {
                    scene: 'fields',
                    table: this.tableKey,
                    prefix: prefix,
                    existing_fields: existing,
                    inputs: items,
                    lenient: !!this.batchLenient,
                });
                const results = (data && data.results) || [];
                // #1 v6.3:不再直接 append,弹预览 modal 让 user 编辑 / 取消每条
                this.translatePreviewResults = results.map((r, i) => ({
                    __idx: 'tr_' + i,
                    input: r.input || '',
                    key: r.valid ? (r.output || '') : '',
                    // 2026-06-20:注释默认留空 —— 注释默认即跟中文名一致(migration 用中文名写 ->comment),
                    //   不重复预填;user 想单独加注释可在预览里手填。中文名走下方 confirm 的 r.input。
                    comment: '',
                    type: r.valid ? (r.type || 'varchar') : 'varchar',
                    size: r.valid && r.size != null ? String(r.size) : '',     // size 文本框值
                    include: Boolean(r.valid && r.output),
                    status_label: r.valid
                        ? '已翻译'
                        : ('失败' + (r.reason ? ':' + r.reason : '')),
                    status_class: r.valid ? 'is-ok' : 'is-fail',
                }));
                // 关 batch modal 但保留 batchInput + batchAfter:
                //   batchInput → "上一步"按钮回来时还在
                //   batchAfter → 预览 modal confirm 时要读
                this.batchOpen = false;
                this.translatePreviewOpen = true;
                this._saveBatchDraft();     // 翻译结果一并存草稿,防 preview 阶段刷新丢失
            } catch (e) {
                if (e.code === 'AI_NOT_CONFIGURED') {
                    this._toast('AI 未配置（SCAFFOLD_AI_API_KEY），无法翻译', 'danger');
                } else {
                    this._toast('翻译失败：' + (e.message || e.code), 'danger');
                }
            } finally {
                this.translating = false;
            }
        },

        // #1 v6.3:批量翻译预览 modal 状态 + 操作
        translatePreviewOpen: false,
        translatePreviewResults: [],
        cancelTranslatePreview() {
            // 仅关 preview modal:preview results / batchAfter / batchLenient 全留。
            // 跟 closeBatch 同样语义,关 modal != 放弃;只在 confirmTranslateAppend 成功时才清场。
            if (!this.translatePreviewOpen) return;     // plan-37 后审 P1:visible 守护
            this.translatePreviewOpen = false;
        },
        // 预览 → 上一步:回到 batch input modal 修订,保留 input/after/lenient,丢 preview(重新翻)
        backToBatchInput() {
            this.translatePreviewOpen = false;
            this.translatePreviewResults = [];
            this.batchOpen = true;
            // batchInput / batchAfter / batchLenient 保留(用户可继续在原输入上加/删/改)
            this._saveBatchDraft();     // 重写 draft:preview 部分清空,input 部分还在
            this._focusEl('batch-input');
        },
        _findTrItem(ev) {
            const tr = ev?.target?.closest?.('tr[data-tridx]');
            const idx = tr?.dataset?.tridx;
            return idx ? this.translatePreviewResults.find(r => r.__idx === idx) : null;
        },
        setTranslateItemInclude(ev) {
            const r = this._findTrItem(ev);
            if (r) { r.include = !!ev?.target?.checked; this._saveBatchDraft(); }
        },
        setTranslateItemKey(ev) {
            const r = this._findTrItem(ev);
            if (r) { r.key = ev?.target?.value || ''; this._saveBatchDraft(); }
        },
        setTranslateItemComment(ev) {
            const r = this._findTrItem(ev);
            if (r) { r.comment = ev?.target?.value || ''; this._saveBatchDraft(); }
        },
        setTranslateItemType(ev) {
            const r = this._findTrItem(ev);
            if (!r) return;
            r.type = ev?.target?.value || 'varchar';
            // 非 varchar/char 类型清空 size 文本框,反之兜个默认值
            if (r.type === 'varchar' || r.type === 'char') {
                if (!r.size) r.size = '64';
            } else {
                r.size = '';
            }
            this._saveBatchDraft();
        },
        setTranslateItemSize(ev) {
            const r = this._findTrItem(ev);
            if (r) { r.size = ev?.target?.value || ''; this._saveBatchDraft(); }
        },
        get translateIncludeCount() {
            return this.translatePreviewResults.filter(r => r.include && r.key).length;
        },
        confirmTranslateAppend() {
            // 用户在 batch modal 选的"在 X 之后"位置;没选就退回旧逻辑(tail readonly 之前)
            let insertAt = -1;
            if (this.batchAfter) {
                const idx = this.fields.findIndex(f => f && f.key === this.batchAfter);
                if (idx >= 0) insertAt = idx + 1;
            }
            if (insertAt < 0) {
                insertAt = this.fields.findIndex(f => f && f.row_readonly);
                if (insertAt < 0) insertAt = this.fields.length;
            }
            const checked = this.translatePreviewResults.filter(r => r.include && r.key);
            if (checked.length === 0) {
                this._toast('没选项要追加', 'warning');
                return;
            }
            const additions = checked.map(r => {
                const t = r.type || 'varchar';
                const sizeIsApplicable = (t === 'varchar' || t === 'char');
                const sz = sizeIsApplicable && r.size && /^\d+$/.test(r.size) ? parseInt(r.size, 10) : null;
                // 2026-06-16:unsigned 跟 confirmAddField 派生对齐 —— numeric 类型默认 unsigned(同 PHP shapeField
                // 默认)且可编辑。原写死 unsigned:false + unsigned_disabled:true → 新加 int/bigint 无符号不可改、
                // 且 reload 后被 PHP 默认 true 自动勾上(前后不一致)。
                const isNumeric = ['bigint', 'int', 'tinyint', 'smallint', 'mediumint', 'decimal', 'float', 'double'].includes(t);
                return {
                    __rowId: r.key,
                    key: r.key,
                    name: r.input || null,     // 中文名 = 原文(原取 r.comment,但注释现默认空,改取 input 才不丢中文名)
                    type: t,
                    size: sizeIsApplicable ? (sz || 64) : null,
                    min_size: null,
                    default: null, required: true, unsigned: isNumeric, nullable: false,
                    index: 'none', comment: r.comment || null,
                    row_readonly: false, name_readonly: false, row_class: '',
                    index_disabled: false, _rowMenuOpen: false,
                    size_class: '', size_title: '',
                    default_class: '', default_title: '',
                    can_rename: true, can_remove: true, row_menu_disabled: false,
                    unsigned_disabled: !isNumeric, _nullable_dirty: false, _unsigned_dirty: false,
                    precision: null, precision_disabled: !(t === 'decimal' || t === 'double' || t === 'float'),
                    format: null,
                    row_title: '',
                    has_enum: false, no_enum: true, enum_options: [], default_str: '',
                    prefix_strippable: false,     // 2026-05-21:批量加完一并 recompute(下面 _recomputeAll)
                    prefix_strip_btn_class: 'p-designer-fields__row-btn p-designer-fields__row-btn--strip is-placeholder',
                    prefix_strip_disabled: true,
                    spell_warning: '', spell_suggestion: '', spell_reason: '', spell_warn_class: 'p-designer-fields__spell-warn is-placeholder', has_spell_warning: false,
                };
            });
            this.fields.splice(insertAt, 0, ...additions);
            this._autoDetectAdvancedCols();                // 批量字段带精度/format 时自动展开两列
            this._recomputeAllFieldsPrefixStrip();     // 批量加字段后一次性算
            this._recomputeMoveFlags();                // 批量加字段后重算上下移边界
            this._scheduleSave();
            this._toast('已追加 ' + additions.length + ' 个字段', 'success');
            // 创建成功 → 真清场(关两个 modal + 清 batchInput/after/lenient + 清 localStorage 草稿)
            this.translatePreviewOpen = false;
            this.translatePreviewResults = [];
            this.batchOpen = false;
            this.batchInput = '';
            this.batchAfter = '';
            this.batchLenient = false;
            this._clearBatchDraft();
            // 2026-05-21 bug:fields.splice(大数组) + 多 modal flag 同步切换,Alpine $watch on
            // _isAnyModalOpen getter 在批量 reactive flush 时 lastValue 跟踪错乱,callback 不触发
            // → body scroll lock 不 pop → 页面卡住不能滚动,需手动刷新。
            // 兜底:nextTick 后查 modal 全关 + body 还 locked 时,显式 pop 一次。
            this.$nextTick(() => {
                if (!this._isAnyModalOpen
                    && document.body.classList.contains('is-modal-open')
                    && window.scaffoldBodyLock) {
                    window.scaffoldBodyLock.pop();
                }
            });
        },

        // —— preview drawer ——
        async openPreview(event) {
            const trigger = (event && (event.currentTarget || event.target)) || null;
            // 先 flush 未 save 的改动,再拉 preview
            if (this._saveTimer) { clearTimeout(this._saveTimer); this._saveTimer = null; await this._flushSave(); }
            // plan 19 v8 C2:如果 flush 失败 → toast 已弹,不要再走 preview fetch 弹第二条
            if (this.savingState === 'error') return;
            try {
                const data = await this._get(this.previewEndpoint);
                if (data && data.is_empty) {
                    this._toast('没有变更，无 migration 可生成', 'info');
                    return;
                }
                // 只看当前选中的表(其它表的 drift 留给用户切到那个表再处理,不混杂)
                const currentKey = this.tableKey;
                const firstTable = (currentKey && data.tables && data.tables[currentKey]) ? data.tables[currentKey] : null;
                if (!firstTable) {
                    this._toast('当前表无变更（其它表可能有，切过去看）', 'info');
                    return;
                }
                const mig = firstTable.migration_file || null;
                const rawWarnings = firstTable.warnings || [];
                // plan-41 §三 A:reverse-dep warnings 拆分到 dep_auto / dep_manual 分组,
                // 其它 warning(DROP_COLUMN / DEFAULT_REMOVED / TYPE_NARROWING ...)走原数组
                const warnings = [];
                const depAuto = []; const depManual = [];
                const _parseDepHit = (hit) => {
                    // hit format: "path:line  snippet" — 抓 path 段 + line
                    const m = /^([^:]+\.(?:php|yaml)):(\d+)\s+(.*)$/.exec(hit || '');
                    if (m) return { path: m[1], line: m[2], snippet: m[3] };
                    return { path: hit || '', line: '', snippet: '' };
                };
                rawWarnings.forEach(w => {
                    const isDep = (w.code === 'REVERSE_DEP_DROP' || w.code === 'REVERSE_DEP_RENAME');
                    if (isDep && w.dep_hit) {
                        const parsed = _parseDepHit(w.dep_hit);
                        const op = w.code === 'REVERSE_DEP_DROP' ? 'drop' : 'rename';
                        // CSP build Alpine 不支持 view 内三元 / === / 拼接,派生 op_label + loc 字段
                        const item = {
                            field: w.dep_field || '',
                            op: op,
                            op_label: op === 'drop' ? '删字段后仍被引用' : '改名后旧名仍被引用',
                            path: parsed.path,
                            line: parsed.line,
                            loc: parsed.line ? (parsed.path + ':' + parsed.line) : parsed.path,
                            snippet: parsed.snippet,
                            vim_cmd: parsed.line ? ('vim +' + parsed.line + ' ' + parsed.path) : ('vim ' + parsed.path),
                        };
                        if (w.dep_kind === 'auto') depAuto.push(item); else depManual.push(item);
                        return;
                    }
                    warnings.push({
                        level_class: w.level === 'high' ? 'is-high' : 'is-medium',
                        label: '[' + (w.level || 'info') + '] ' + (w.msg || ''),
                    });
                });
                // v6.2 round 1: 字段/索引变化转 git diff 风格(modify 拆 -old / +new 两行)
                // text 字段保留旧名 'label' 以减少 view 改动面;每条对应 diff 一行
                const _fmtFull = (d) => {
                    if (!d) return '';
                    const parts = [];
                    let head = d.type || '?';
                    if (d.size != null) head += '(' + d.size + ')';
                    parts.push(head);
                    if (d.default != null && d.default !== '') parts.push('default=' + JSON.stringify(d.default));
                    if (d.required === false || d.nullable === true) parts.push('nullable');
                    if (d.unsigned === true) parts.push('unsigned');
                    if (d.unique === true) parts.push('unique');
                    if (d.comment) parts.push('comment=' + JSON.stringify(d.comment));
                    return parts.join('  ');
                };
                // CSP build Alpine 不支持 :class="'is-op-' + c.op" 拼接,这里直接派生完整 class 名
                const _opCls = { add: 'is-op-add', drop: 'is-op-drop', rename: 'is-op-rename', modify: 'is-op-modify' };
                const fieldChanges = [];
                (firstTable?.field_changes || []).forEach(ch => {
                    if (ch.op === 'add') {
                        fieldChanges.push({ op: 'add', prefix: '+', cls: _opCls.add, label: ch.field + '  ' + _fmtFull(ch.definition) });
                    } else if (ch.op === 'drop') {
                        fieldChanges.push({ op: 'drop', prefix: '-', cls: _opCls.drop, label: ch.field + '  ' + _fmtFull(ch.definition) });
                    } else if (ch.op === 'rename') {
                        fieldChanges.push({ op: 'rename', prefix: 'R', cls: _opCls.rename, label: ch.from + ' → ' + ch.to });
                    } else if (ch.op === 'modify') {
                        // git diff 风格:整个字段定义旧版用 - 行,新版用 + 行,user 一眼看到改了啥
                        fieldChanges.push({ op: 'drop', prefix: '-', cls: _opCls.drop, label: ch.field + '  ' + _fmtFull(ch.before) });
                        fieldChanges.push({ op: 'add', prefix: '+', cls: _opCls.add, label: ch.field + '  ' + _fmtFull(ch.after) });
                    }
                });
                const _fmtIdx = (ch) => {
                    const fields = Array.isArray(ch.fields) ? ch.fields.join(', ') : (ch.fields || '');
                    return (ch.name || '?') + '  (' + (ch.type || 'index') + ')  [' + fields + ']';
                };
                const indexChanges = [];
                (firstTable?.index_changes || []).forEach(ch => {
                    if (ch.op === 'add') {
                        indexChanges.push({ op: 'add', prefix: '+', cls: _opCls.add, label: _fmtIdx(ch) });
                    } else if (ch.op === 'drop') {
                        indexChanges.push({ op: 'drop', prefix: '-', cls: _opCls.drop, label: _fmtIdx(ch) });
                    } else if (ch.op === 'modify') {
                        // modify:如果 service 返回了 before/after 拆两行,否则单行兜底
                        if (ch.before) indexChanges.push({ op: 'drop', prefix: '-', cls: _opCls.drop, label: _fmtIdx({ name: ch.name, type: ch.before.type, fields: ch.before.fields }) });
                        if (ch.after)  indexChanges.push({ op: 'add', prefix: '+', cls: _opCls.add, label: _fmtIdx({ name: ch.name, type: ch.after.type,  fields: ch.after.fields  }) });
                        if (!ch.before && !ch.after) indexChanges.push({ op: 'modify', prefix: '~', cls: _opCls.modify, label: ch.name });
                    }
                });
                const phpSrc = mig ? mig.php_source : '';
                // 2026-05-21 fix:支持 baseline_drift 状态(server 检 baseline 缺失 + DB 已存在表 → 拒生成 migration)
                //   预览抽屉以前对此状态没特殊处理:无 file / 空 changes → drawer 显空。
                //   现在 frontend 派生 baseline_drift flag + has_migration flag,blade 端隐藏 file block / 显红 banner。
                const isBaselineDrift = (firstTable?.status === 'baseline_drift');
                this.preview = {
                    has_preview: true,
                    has_warnings: warnings.length > 0,
                    // Round 2 P2:baseline 缺失 → 显示首次 migrate 提示 banner
                    baseline_missing: !!data.baseline_missing,
                    // 2026-05-21:baseline_drift → 红色严重 banner(跟 baseline_missing 的蓝色 info banner 区分)
                    baseline_drift: isBaselineDrift,
                    baseline_drift_table: isBaselineDrift ? this.tableKey : '',
                    baseline_drift_schema: isBaselineDrift ? this.schema : '',
                    summary: firstTable ? [firstTable.summary_text] : [],
                    warnings: warnings,
                    // plan-41 §三 A:reverse-dep 分组 AUTO(*Trait.php / Enums = moo:fresh 重写)/ MANUAL(需手清)
                    dep_auto: depAuto,
                    dep_manual: depManual,
                    has_dep_auto: depAuto.length > 0,
                    has_dep_manual: depManual.length > 0,
                    file_name: mig ? mig.filename : '',
                    has_migration: !!mig,                    // 无 file 时模板隐藏整段 file block
                    php_code: phpSrc,
                    php_html: this._highlightPhp(phpSrc),     // #2 染色后 HTML
                    field_changes: fieldChanges,
                    has_field_changes: fieldChanges.length > 0,
                    index_changes: indexChanges,
                    has_index_changes: indexChanges.length > 0,
                };
                window.dispatchEvent(new CustomEvent('open-drawer', {
                    detail: { name: 'designer-preview', trigger: trigger },
                }));
            } catch (e) {
                if (e.code === 'SUSPECTED_RENAMES') {
                    this._toast('可能存在改名，请用"改名"按钮明确标注后再生成', 'warning');
                } else {
                    this._toast('preview 失败：' + (e.message || e.code), 'danger');
                }
            }
        },
        closePreview() {
            window.dispatchEvent(new CustomEvent('close-drawer', {
                detail: { name: 'designer-preview' },
            }));
        },
        toggleCode() { this.codeExpanded = !this.codeExpanded; },
        get codeCollapsed() { return !this.codeExpanded; },
        get migratingIdle() { return !this.migrating; },
        toggleYamlRaw() { this.yamlRawOpen = !this.yamlRawOpen; },

        // 复制到剪贴板 helper(用 navigator.clipboard,失败兜底 textarea select)
        async _copyToClipboard(text, successMsg) {
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(text || '');
                } else {
                    // 非 https / 旧浏览器兜底
                    const ta = document.createElement('textarea');
                    ta.value = text || '';
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                }
                this._toast(successMsg || '已复制', 'success');
            } catch (e) {
                this._toast('复制失败：' + e.message, 'danger');
            }
        },
        copyPreviewPhp() {
            if (this.preview) this._copyToClipboard(this.preview.php_code, '已复制 migration PHP');
        },
        // plan-41 §三 A · CSP fix:CSP build 禁 obj.prop 点链访问 in x-text/x-html/x-for(item,i),
        // 派生顶级 getter。x-show / x-for 不带 idx 在 obj.prop 上凑合工作,但为统一全部走 getter。
        get hasDepManual() { return !!(this.preview && this.preview.has_dep_manual); },
        get hasDepAuto()   { return !!(this.preview && this.preview.has_dep_auto); },
        get depManual()    { return (this.preview && this.preview.dep_manual) || []; },
        get depAuto()      { return (this.preview && this.preview.dep_auto) || []; },
        // plan-41 §三 A v2:preview drawer 其它 6 处 obj.prop(baseline_drift / field_changes /
        // index_changes / file_name / php_html / warnings)CSP fail,派生顶级 getter
        get baselineDriftTable()  { return (this.preview && this.preview.baseline_drift_table) || ''; },
        get baselineDriftSchema() { return (this.preview && this.preview.baseline_drift_schema) || ''; },
        get previewSummary()      { return (this.preview && this.preview.summary) || []; },
        get previewFieldChanges() { return (this.preview && this.preview.field_changes) || []; },
        get previewIndexChanges() { return (this.preview && this.preview.index_changes) || []; },
        get previewFileName()     { return (this.preview && this.preview.file_name) || ''; },
        get previewPhpHtml()      { return (this.preview && this.preview.php_html) || ''; },
        get previewWarnings()     { return (this.preview && this.preview.warnings) || []; },
        // 2026-05-23:baseline_missing + baseline_drift 之前 banner 同时显示导致措辞矛盾,
        // 改成 drift banner 仅在"真 drift"(snapshot 存在但表段缺)时显示。CSP 不允许模板写 `&&!`,
        // 派生进 getter
        get showBaselineDriftBanner() {
            return !!(this.preview && this.preview.baseline_drift && !this.preview.baseline_missing);
        },
        // plan-41 §三 A:复制全部 manual reverse-dep 的 vim 命令到剪贴板,user paste 到终端逐条跳行
        copyDepGrepCmd() {
            if (!this.preview) return;
            const lines = (this.preview.dep_manual || []).map(d => d.vim_cmd).filter(Boolean);
            if (!lines.length) { this._toast('无需手清依赖', 'info'); return; }
            this._copyToClipboard(lines.join('\n'), '已复制 ' + lines.length + ' 条 vim 命令（MANUAL 反向依赖）');
        },
        // #2 v6.3:简易 PHP 语法染色(escape + keyword + variable;字符串/注释跳过避免 class 属性二次匹配)
        _highlightPhp(src) {
            if (!src) return '';
            let html = String(src)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            const kws = ['use', 'return', 'new', 'function', 'class', 'public', 'private', 'protected',
                'static', 'extends', 'implements', 'namespace', 'declare', 'strict_types',
                'if', 'else', 'elseif', 'foreach', 'as', 'self', 'this', 'void', 'string',
                'int', 'bool', 'array', 'true', 'false', 'null', 'try', 'catch', 'throw', 'finally'];
            const kwRe = new RegExp('\\b(' + kws.join('|') + ')\\b', 'g');
            html = html.replace(kwRe, '<span class="ph-kw">$1</span>');
            html = html.replace(/\$[a-zA-Z_][a-zA-Z0-9_]*/g, m => '<span class="ph-var">' + m + '</span>');
            return html;
        },
        // plan 39:copyCommitMessage 已删除 — GUI 不再有 commit_message
        // v6.2 round 6:复制整个 yaml 原文(从 section data-yaml-raw 取)
        copyYamlRaw(ev) {
            const section = ev?.target?.closest?.('section[data-yaml-raw]');
            const raw = section?.dataset?.yamlRaw;
            if (raw) this._copyToClipboard(raw, '已复制 yaml 原文（' + raw.length + ' 字节）');
        },

        // plan 39:GUI 不再做 git commit,只写 migration 文件 + 推 baseline 快照
        // 开发者手动 git add yaml + migration 自己 commit
        async confirmMigrate() {
            if (this.migrating) return;
            this.migrating = true;
            try {
                const data = await this._post(this.migrateEndpoint, {
                    only_table: this.tableKey,     // 只生成当前表的 migration,不连其它表 drift 一起改
                });
                const files = data.files_written || [];
                this._toast('migration 已生成 ' + files.length + ' 个文件，记得自己 commit', 'success');
                this.closePreview();
                scaffoldReload(800);
            } catch (e) {
                if (e.code === 'EMPTY_DIFF') {
                    this._toast('无变更，跳过', 'info');
                } else {
                    this._toast('migrate 失败：' + (e.message || e.code), 'danger');
                }
            } finally {
                this.migrating = false;
            }
        },
    }));

});
