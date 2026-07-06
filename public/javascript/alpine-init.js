/*
 * Alpine CSP build 配套:所有 Alpine.data() component 注册
 *
 * 为什么需要这个文件:
 *   Alpine CSP build 不允许 inline `x-data="{...}"` 表达式(CSP 禁 eval / new Function()),
 *   所有组件必须通过 Alpine.data('name', factory) 提前注册,blade 里只能写 `x-data="name"`。
 *   per-instance 数据靠 `data-*` 属性 + `this.$el.dataset.xxx` 读取。
 *
 * 加载时序:
 *   alpine-csp.min.js (defer) → 触发 alpine:init → 本文件已注册的 listener 跑
 *   → Alpine.start() 接管 DOM,开始绑定 components
 *
 * Blade 模板上的 CSP 写法守则(违反会 console 报 "unable to interpret" 且 binding 静默失效):
 *   - 不允许 `===` / `!` / 三元 / 对象字面量 / `&&` 短路 / `$event.detail.x` 深属性链
 *   - `:class` / `:disabled` / `:readonly` / `:selected` / `:checked` / `x-if` / `:key` 拒 method call,
 *     只允许属性访问;判断下沉到 PHP shape / JS getter / 预算 boolean
 *   - `x-show` 单层属性访问 OK,nested 不行
 *   - `x-model` 在 textarea / 某些场景静默失败,统一改 `:value` + `x-on:input="setterName"`(无 parens)
 *   - `x-on:event="method(literal, $event)"` 这种 multi-arg method call 也拒,最稳:`x-on:event="setterName"`
 *   - x-for 行级反查:`<tr :data-rk="f.__rowId">` + setter 内 closest('tr').dataset.rk → findIndex
 *   - nested `<template x-if>` 在 `<template x-for>` 内 cloneNode 报错,改 `<g x-show>` / `<div x-show>`
 */
// plan-22: 全局 reload helper — 清 hash 防 reload 后 openByHash 误弹抽屉(QA P0)
window.scaffoldReload = function (delay) {
    var go = function () {
        try { history.replaceState(null, '', location.pathname + location.search); } catch (e) {}
        location.reload();
    };
    if (delay && delay > 0) setTimeout(go, delay); else go();
};

// 2026-05-20:统一 body scroll lock counter — drawer / modal / popover / confirm 共享
// 任意类型弹出都调 push,关闭调 pop;最后一个关闭才 unlock(防多弹层提前 unlock)
// SCSS body.is-modal-open { overflow: hidden } 配套
window.scaffoldBodyLock = {
    push: function () {
        var c = parseInt(document.body.dataset.modalLockCount || '0', 10) + 1;
        document.body.dataset.modalLockCount = String(c);
        if (c === 1) document.body.classList.add('is-modal-open');
    },
    pop: function () {
        var c = Math.max(0, parseInt(document.body.dataset.modalLockCount || '0', 10) - 1);
        document.body.dataset.modalLockCount = String(c);
        if (c === 0) document.body.classList.remove('is-modal-open');
    },
};

document.addEventListener('alpine:init', () => {

    // ─────────────────────────────────────────────────────────
    // 通用：下拉菜单（header 的设置 / 用户菜单都共用）
    // ─────────────────────────────────────────────────────────
    Alpine.data('dropdown', () => ({
        open: false,
        toggle() { this.open = !this.open; },
        close() { this.open = false; },
        get ariaExpanded() { return this.open ? 'true' : 'false'; },
    }));

    // ─────────────────────────────────────────────────────────
    // tabs 组件：active key 从 data-default-key 读取
    // ─────────────────────────────────────────────────────────
    // CSP-safe tabs(同 sideTree 套路:_root 缓存 + imperative DOM update,
    // 去 tabClass('key') / setActive('key') / isActive('key') 等 method-with-literal)
    Alpine.data('tabs', () => ({
        active: '',
        _root: null,
        init() {
            this._root = this.$el;
            this.active = this.$el.dataset.defaultKey || '';
            this._render();
        },
        // 兼容 setActive(key) 给 alpine-init 外部 JS 调用(若有)
        setActive(key) { this.active = key; this._render(); },
        // CSP-safe: 模板 @click="setActiveFromBtn",从 button[data-tab-key] 反查
        setActiveFromBtn(ev) {
            const key = ev && ev.currentTarget && ev.currentTarget.dataset.tabKey;
            if (key) { this.active = key; this._render(); }
        },
        _render() {
            if (!this._root) return;
            const active = this.active;
            this._root.querySelectorAll('[data-tab-key]').forEach(btn => {
                const isActive = btn.dataset.tabKey === active;
                btn.classList.toggle('is-active', isActive);
                btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });
            this._root.querySelectorAll('[data-tab-panel]').forEach(p => {
                p.classList.toggle('is-hidden', p.dataset.tabPanel !== active);
            });
        },
    }));

    // ─────────────────────────────────────────────────────────
    // drawer 组件：name 从 data-drawer-name 读取
    // ─────────────────────────────────────────────────────────
    Alpine.data('drawer', () => ({
        open: false,
        prevFocus: null,
        name: '',
        init() {
            this.name = this.$el.dataset.drawerName || '';
        },
        openDrawer(trigger) {
            // 优先用调用方传入的 trigger 元素（行 click 通常 activeElement 是 body 而非 row）
            this.prevFocus = trigger || document.activeElement;
            this.open = true;
            window.scaffoldBodyLock && window.scaffoldBodyLock.push();
        },
        closeDrawer() {
            const target = this.prevFocus;
            this.prevFocus = null;
            this.open = false;
            window.scaffoldBodyLock && window.scaffoldBodyLock.pop();
            if (target && typeof target.focus === 'function') {
                requestAnimationFrame(() => target.focus());
            }
        },
        // 兼容两种 detail 格式：'name'（旧）和 { name, trigger }（新，带焦点元素）
        handleOpenEvent(event) {
            const detail = event.detail;
            const name = typeof detail === 'string' ? detail : (detail && detail.name);
            if (name !== this.name) return;
            const trigger = (detail && typeof detail === 'object') ? detail.trigger : null;
            this.openDrawer(trigger);
        },
        handleCloseEvent(event) {
            const detail = event.detail;
            const name = typeof detail === 'string' ? detail : (detail && detail.name);
            if (this.open && (name === this.name || !detail)) this.closeDrawer();
        },
        handleEscape() {
            if (!this.open) return;
            // plan-22 P1-I2: Esc 在 input/textarea/select 内只让浏览器原生清值,不关 drawer
            // 防止用户在抽屉里编辑 input 时按 Esc 想清值反而丢失未保存数据(QA P1 痛点)
            const ae = document.activeElement;
            if (ae && /^(INPUT|TEXTAREA|SELECT)$/i.test(ae.tagName)) return;
            this.closeDrawer();
        },
    }));

    // ─────────────────────────────────────────────────────────
    // toast container
    // ─────────────────────────────────────────────────────────
    const TOAST_ICON_MAP = {
        success: 'check', info: 'eye', warning: 'shield', danger: 'close', neutral: 'more',
    };
    Alpine.data('toastContainer', () => ({
        toasts: [],
        handleToastEvent(event) {
            this.push(event && event.detail);
        },
        push(detail) {
            if (!detail) return;
            const id = Date.now() + Math.random();
            const tone = detail.tone || 'neutral';
            // 预算 isXxx boolean 给模板用,避免 x-if="toneIs(t, 'xxx')" — Alpine CSP build 不支持 x-if 里 method call
            const t = {
                id,
                message: detail.message || '',
                title: detail.title || null,
                tone: tone,
                toneClass: 'toast--' + tone,            // :class 直接属性访问,CSP-safe
                isSuccess: tone === 'success',
                isInfo:    tone === 'info',
                isWarning: tone === 'warning',
                isDanger:  tone === 'danger',
                isNeutral: tone === 'neutral',
                duration: typeof detail.duration === 'number' ? detail.duration : 3500,
            };
            this.toasts.push(t);
            if (t.duration > 0) {
                setTimeout(() => this.dismiss(id), t.duration);
            }
        },
        dismiss(id) {
            this.toasts = this.toasts.filter(t => t.id !== id);
        },
        // CSP-safe:button 上挂 :data-toast-id="t.id",handler 反查
        dismissFromButton(ev) {
            const id = ev && ev.currentTarget && ev.currentTarget.dataset.toastId;
            if (id) this.dismiss(parseFloat(id));     // toast id 是 number(Date.now() + random),dataset 取出来是 string
        },
        toneClass(tone) { return 'toast--' + tone; },
        iconName(tone) { return TOAST_ICON_MAP[tone] || 'more'; },
    }));

    // ─────────────────────────────────────────────────────────
    // confirm container：替代浏览器 confirm()，配合 window.scaffoldConfirm Promise API
    // 触发：window.dispatchEvent(new CustomEvent('scaffold-confirm', {detail:{message, resolve}}))
    // ─────────────────────────────────────────────────────────
    Alpine.data('confirmContainer', () => ({
        visible: false,
        title: '请确认',
        message: '',
        confirmLabel: '确认',
        cancelLabel: '取消',
        confirmClass: 'btn--primary',
        // 2026-05-22 plan-43 Batch D:danger tone — true 时 .modal-panel--danger
        tone: '',
        // plan-22 安全审计 Q4:challenge text 字段(空字符串=不需挑战,非空=用户必须输匹配文本)
        challenge: '',
        challengeLabel: '',
        challengeInput: '',
        _resolve: null,
        // CSP build:listener 拿到 event 作首参数,禁止模板里写 $event.detail
        handleEvent(event) {
            this.open(event && event.detail);
        },
        handleEscape() {
            if (this.visible) this.cancel();
        },
        // 2026-05-22 plan-43 Batch D:Enter 直接 confirm — 但跟 designer popover 一致,disabled 时不触发
        handleEnter() {
            if (!this.visible) return;
            if (this.confirmDisabled) return;
            this.confirm();
        },
        get confirmDisabled() {
            return this.challenge !== '' && this.challengeInput !== this.challenge;
        },
        // 2026-05-24 二轮 audit A2:模板原 `:class="tone === 'danger' ? ...:''"` 三元 CSP 违规,派生 getter
        get panelClass() {
            return this.tone === 'danger' ? 'modal-panel--danger' : '';
        },
        open(detail) {
            if (!detail) return;
            this.title = detail.title || '请确认';
            this.message = detail.message || '';
            this.confirmLabel = detail.confirmLabel || '确认';
            this.cancelLabel = detail.cancelLabel || '取消';
            this.confirmClass = detail.danger ? 'btn--danger' : 'btn--primary';
            // 2026-05-22:tone class 跟 confirmClass 联动(danger 走红顶 modal)
            this.tone = detail.danger ? 'danger' : '';
            this.challenge = detail.challenge || '';
            this.challengeLabel = detail.challengeLabel || '';
            this.challengeInput = '';
            this._resolve = detail.resolve || null;
            this.visible = true;
            window.scaffoldBodyLock && window.scaffoldBodyLock.push();
            // challenge 模式自动聚焦 input,让用户立刻输入
            if (this.challenge) {
                this.$nextTick(() => {
                    const el = this.$refs && this.$refs.challengeInput;
                    if (el && el.focus) el.focus();
                });
            }
        },
        confirm() {
            // 防御性二次校验:disabled 被绕过也拒绝
            if (this.confirmDisabled) return;
            this.visible = false;
            window.scaffoldBodyLock && window.scaffoldBodyLock.pop();
            if (this._resolve) this._resolve(true);
            this._resolve = null;
        },
        cancel() {
            this.visible = false;
            window.scaffoldBodyLock && window.scaffoldBodyLock.pop();
            if (this._resolve) this._resolve(false);
            this._resolve = null;
        },
    }));

    // ─────────────────────────────────────────────────────────
    // side-tree：searchIndex 从 data-search-index 读 JSON
    // ─────────────────────────────────────────────────────────
    // plan-26 T0:CSP-safe 重构 — method-call-with-literal 全去,改 imperative DOM update
    // 旧版 isGroupVisible('key', 'label') 等在 Alpine CSP build 静默失败 → groups 全空
    // 新版:toggleGroup 零参从 $event.target.closest 反查;$watch('query') 触发 _renderQuery 改 class
    //
    // bug 修复 2026-05-18(用户报"展不开/收不起"):Alpine CSP build 里 `x-on:click="toggleGroup"`
    // handler 内 this.$el = clicked button(不是 aside root!),this.$el.querySelectorAll
    // 只看 button 后代 → 0 元素,_renderCollapse 静默 noop。
    // 修:init 时把 root 引用存到 this._root,后续 query DOM 全走 _root。
    Alpine.data('sideTree', () => ({
        query: '',
        collapsed: {},
        _storageKey: '',
        _root: null,
        init() {
            // 锚定 root 元素,后续 query 不依赖 this.$el(handler 内会变成 button)
            this._root = this.$el;

            // localStorage 持久化(plan-22 P1-I3 资深用户 #3 痛点)
            this._storageKey = 'scaffold.sidetree.' + location.pathname.replace(/\/+/g, '_');
            try {
                const saved = localStorage.getItem(this._storageKey);
                if (saved) this.collapsed = JSON.parse(saved) || {};
            } catch (e) { this.collapsed = {}; }

            // 默认折叠仅在 localStorage 无记录时生效(给用户偏好让路)
            if (this._root.dataset.collapsedByDefault === '1' && Object.keys(this.collapsed).length === 0) {
                this._root.querySelectorAll('[data-group-key]').forEach(g => {
                    this.collapsed[g.dataset.groupKey] = true;
                });
            }

            // 2026-05-24:active item 的祖先 group 必须展开(覆盖 localStorage + collapsedByDefault)
            // — shared link 进来(api?f=Platform&c=Page&a=create_get)要看到当前接口高亮,
            // 不能藏在收起的菜单里。同时滚到视口中央。
            const activeItem = this._root.querySelector('.side-tree__item.is-active');
            if (activeItem) {
                let g = activeItem.parentElement && activeItem.parentElement.closest('[data-group-key]');
                while (g) {
                    delete this.collapsed[g.dataset.groupKey];
                    g = g.parentElement && g.parentElement.closest('[data-group-key]');
                }
            }

            // 初始化:把 collapsed state 渲染到 DOM(class + aria-expanded)
            this._renderCollapse();

            if (activeItem) {
                // 等 _renderCollapse 把祖先 .is-collapsed 类去掉、DOM reflow 完再滚
                requestAnimationFrame(() => {
                    activeItem.scrollIntoView({ block: 'center', behavior: 'auto' });
                });
            }
            // query 变化时,imperative 加/去 .is-hidden 类
            this.$watch('query', () => this._renderQuery());
        },
        _persist() {
            try { localStorage.setItem(this._storageKey, JSON.stringify(this.collapsed)); } catch (e) {}
        },
        // 2026-05-23:CSP-safe setter for search input(x-model.debounce 在 scaffold CSP build
        // 下不工作 — query 一直 '',_renderQuery 用空串走 anyVisible 分支不过滤)
        setQuery(ev) {
            this.query = (ev && ev.target) ? (ev.target.value || '') : '';
        },
        // CSP-safe:零参 method,从 $event 反查 closest('[data-group-key]')
        toggleGroup(ev) {
            const group = ev && ev.target && ev.target.closest('[data-group-key]');
            if (!group) return;
            const key = group.dataset.groupKey;
            this.collapsed[key] = !this.collapsed[key];
            this._persist();
            this._renderCollapse();
        },
        // 2026-05-21 A 方案:user 一键全折叠 / 全展开,override localStorage 偏好
        collapseAll() {
            if (!this._root) return;
            this.collapsed = {};
            this._root.querySelectorAll('[data-group-key]').forEach(g => {
                this.collapsed[g.dataset.groupKey] = true;
            });
            this._persist();
            this._renderCollapse();
        },
        expandAll() {
            this.collapsed = {};
            this._persist();
            this._renderCollapse();
        },
        // 把 collapsed map 反映到 DOM:.is-collapsed 类 + aria-expanded
        _renderCollapse() {
            if (! this._root) return;
            this._root.querySelectorAll('[data-group-key]').forEach(g => {
                const key = g.dataset.groupKey;
                const isCollapsed = !!this.collapsed[key];
                g.classList.toggle('is-collapsed', isCollapsed);
                const caret = g.querySelector('.side-tree__caret');
                if (caret) caret.classList.toggle('is-collapsed', isCollapsed);
                const btn = g.querySelector('.side-tree__group-head');
                if (btn) btn.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
            });
        },
        // 搜索过滤:imperative 加/去 .is-hidden,empty 节点 hidden 属性
        _renderQuery() {
            if (! this._root) return;
            const q = (this.query || '').toLowerCase().trim();
            const emptyEl = this._root.querySelector('.side-tree__empty');

            // 清空搜索 → 全部去 is-hidden,折叠态按 this.collapsed 还原(不污染用户偏好)
            if (! q) {
                this._root.querySelectorAll('[data-group-key]').forEach(g => g.classList.remove('is-hidden'));
                this._root.querySelectorAll('[data-item-label]').forEach(it => it.classList.remove('is-hidden'));
                if (emptyEl) emptyEl.setAttribute('hidden', '');
                this._renderCollapse();
                return;
            }

            let anyVisible = false;
            this._root.querySelectorAll('[data-group-key]').forEach(g => {
                const groupLabel = (g.dataset.groupLabel || '');
                // 可搜文本:item label + url + method(后两者由消费方写进 data-item-search,
                // 没有就只搜 label)—— 让按 URL / 方法 也能搜到接口。
                const items = g.querySelectorAll('[data-item-label]');
                const groupMatches = groupLabel.includes(q);
                let hasVisibleItem = false;
                items.forEach(it => {
                    const haystack = (it.dataset.itemLabel || '') + ' ' + (it.dataset.itemSearch || '');
                    const itemMatches = haystack.includes(q) || groupMatches;
                    it.classList.toggle('is-hidden', !itemMatches);
                    if (itemMatches) hasVisibleItem = true;
                });
                const groupVisible = groupMatches || hasVisibleItem;
                g.classList.toggle('is-hidden', !groupVisible);
                // 命中的 group 强制展开,否则匹配项被 .is-collapsed 折叠住、搜了也看不见
                // (默认 collapsedByDefault 时尤其致命;清空搜索时 _renderCollapse 还原,2026-06-09 修)
                if (groupVisible) {
                    g.classList.remove('is-collapsed');
                    const caret = g.querySelector('.side-tree__caret');
                    if (caret) caret.classList.remove('is-collapsed');
                    const btn = g.querySelector('.side-tree__group-head');
                    if (btn) btn.setAttribute('aria-expanded', 'true');
                    anyVisible = true;
                }
            });
            if (emptyEl) {
                if (! anyVisible) emptyEl.removeAttribute('hidden');
                else emptyEl.setAttribute('hidden', '');
            }
        },
    }));

    // ─────────────────────────────────────────────────────────
    // accounts 页：编辑 / 新增 modal + 删除确认 modal
    // ─────────────────────────────────────────────────────────
    Alpine.data('accountsPage', () => ({
        formOpen: false,
        editTarget: null,
        editing: { username: '', password: '', phone: '', role: 'admin', enabled: true, can_design_db: false },
        deleteTarget: null,
        storeBase: '',                  // 注入自 view 上 data-store-base(避免 CSP build 不允许 :action="formAction(literal)")
        init() {
            this.storeBase = this.$el?.dataset?.storeBase || '';
        },
        openCreate() {
            this.editTarget = null;
            this.editing = { username: '', password: '', phone: '', role: 'admin', enabled: true, can_design_db: false };
            if (!this.formOpen) window.scaffoldBodyLock && window.scaffoldBodyLock.push();
            this.formOpen = true;
            this.$nextTick(() => this.$refs.firstField && this.$refs.firstField.focus());
        },
        openEdit(row) {
            this.editTarget = row.username;
            this.editing = {
                username: row.username,
                password: '',
                phone: row.phone,
                role: row.role,
                enabled: row.enabled,
                can_design_db: !!row.can_design_db,
            };
            if (!this.formOpen) window.scaffoldBodyLock && window.scaffoldBodyLock.push();
            this.formOpen = true;
            this.$nextTick(() => this.$refs.pwdField && this.$refs.pwdField.focus());
        },
        openEditFromButton(event) {
            const btn = event.currentTarget || event.target;
            try {
                const row = JSON.parse(btn.dataset.row || '{}');
                this.openEdit(row);
            } catch (e) {
                console.warn('[accounts] bad data-row JSON:', e);
            }
        },
        closeForm() {
            if (this.formOpen) window.scaffoldBodyLock && window.scaffoldBodyLock.pop();
            this.formOpen = false;
        },
        // CSP-safe:接 $event,从 button[data-username] 读;原 askDelete('xxx') 字面参数 Alpine CSP 拒
        askDelete(ev) {
            const btn = ev && ev.currentTarget;
            const name = btn?.dataset?.username || '';
            if (name) {
                if (this.deleteTarget === null) window.scaffoldBodyLock && window.scaffoldBodyLock.push();
                this.deleteTarget = name;
            }
        },
        cancelDelete() {
            if (this.deleteTarget !== null) window.scaffoldBodyLock && window.scaffoldBodyLock.pop();
            this.deleteTarget = null;
        },
        handleEscape() {
            if (this.formOpen) this.closeForm();
            else if (this.deleteTarget) this.cancelDelete();
        },
        get deleteVisible() { return this.deleteTarget !== null; },
        get passwordRequired() { return !this.editTarget; },
        get modalTitle() {
            return this.editTarget ? '编辑账号：' + this.editTarget : '新增开发人员';
        },
        get passwordPlaceholder() {
            return this.editTarget ? '留空表示不修改' : '';
        },
        // CSP build 模板不允许 nested 属性访问(editing.username),predict 平铺 getter
        get editingUsername() { return this.editing?.username || ''; },
        get editingPassword() { return this.editing?.password || ''; },
        get editingPhone()    { return this.editing?.phone    || ''; },
        get editingRole()     { return this.editing?.role     || 'admin'; },
        get editingEnabled()  { return !!(this.editing?.enabled); },
        get editingCanDesignDb() { return !!(this.editing?.can_design_db); },
        // setEditing 统一 setter,view 上 input/select/checkbox 都用 x-on:input/change="setEditing",
        // 内部从 ev.target.name 反查字段(name="username" 等),CSP-safe 无 multi-arg
        setEditing(ev) {
            const name = ev?.target?.name;
            if (!name) return;
            if (!this.editing) this.editing = {};
            const val = ev.target.type === 'checkbox' ? ev.target.checked : ev.target.value;
            this.editing[name] = val;
        },
        // formAction / deleteAction 改 getter(读 storeBase + editTarget / deleteTarget),CSP-safe
        get formActionUrl() {
            return this.editTarget ? this.storeBase + '/' + this.editTarget : this.storeBase;
        },
        get deleteActionUrl() {
            return this.storeBase + '/' + this.deleteTarget + '/delete';
        },
    }));

    // ─────────────────────────────────────────────────────────
    // config 页：TOC scroll-spy（IntersectionObserver 高亮当前 section）
    // ─────────────────────────────────────────────────────────
    Alpine.data('configToc', () => ({
        activeGroup: '',
        pinnedUntil: 0,  // 点击 sidebar 后短暂"钉住"高亮，避免被滚动后的 IO 立刻覆盖
        init() {
            this.activeGroup = this.$el.dataset.flashGroup || '';

            // 点 sidebar 链接 → 立刻高亮该项，并 pin 800ms 让浏览器把锚点滚完
            // plan-31 T3:.p-config-toc-item legacy → .side-tree__item-link[data-group]
            document.querySelectorAll('.side-tree__item-link[data-group]').forEach(a => {
                a.addEventListener('click', () => {
                    const g = a.dataset.group;
                    if (g) {
                        this.activeGroup = g;
                        this.pinnedUntil = Date.now() + 800;
                    }
                });
            });

            if (!('IntersectionObserver' in window)) return;
            const sections = this.$el.querySelectorAll('[data-group-section]');
            const io = new IntersectionObserver((entries) => {
                if (Date.now() < this.pinnedUntil) return; // 用户刚点链接，IO 让位
                const visible = entries
                    .filter(e => e.isIntersecting)
                    .sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];
                if (visible) this.activeGroup = visible.target.dataset.groupSection;
            }, { rootMargin: '-30% 0px -55% 0px', threshold: [0, 0.25, 0.5, 0.75, 1] });
            sections.forEach(s => io.observe(s));

            // 页面滚到底时，IO 抓不到底部短 section（视口超出页面），手动强制最后一节
            window.addEventListener('scroll', () => {
                if (Date.now() < this.pinnedUntil) return;
                const atBottom = (window.innerHeight + window.scrollY) >= (document.body.scrollHeight - 2);
                if (atBottom && sections.length > 0) {
                    this.activeGroup = sections[sections.length - 1].dataset.groupSection;
                }
            }, { passive: true });

            // 跟 activeGroup 变化反向同步 sidebar TOC 高亮
            // plan-31 T3:toggle .is-active 在 <li>(side-tree__item),通过 <a> 反查
            this.$watch('activeGroup', (val) => {
                document.querySelectorAll('.side-tree__item-link[data-group]').forEach(a => {
                    const li = a.closest('.side-tree__item');
                    if (li) li.classList.toggle('is-active', a.dataset.group === val);
                });
            });
        },
    }));


    // ─────────────────────────────────────────────────────────
    // db designer 页(plan 19 / 1283 lines)已抽到 designer.js(plan-25)
    // 只在 db/designer/show 页 load(view 内 @section('scripts'))
    // ─────────────────────────────────────────────────────────


    // ─────────────────────────────────────────────────────────
    // plan-29:接口路由页 ACL 详情抽屉(替代独立 /scaffold/acl 页)
    //   route/index.blade.php 行 click → jQuery dispatchEvent 'set-route-detail'
    //   → 本组件 handleSet 接管,把 route + siblings 派生展示字段后挂到模板
    //   CSP 友好:所有 || / === / && 都在 JS 端预算,模板只用属性访问
    // ─────────────────────────────────────────────────────────
    Alpine.data('routeAclDetail', () => ({
        route: { uri: '', method: '', display_key: '-', display_hash: '-' },
        siblings: [],
        hasRoute: false,
        hasSiblings: false,
        hasMiddleware: false,
        hasSiblingApps: false,
        missingAcl: false,
        methodLabel: '',
        zhPath: '',
        curlCommand: '',
        transformTips: [],

        handleSet(event) {
            const d = event && event.detail ? event.detail : {};
            const r = d.route || null;
            if (!r) { this.hasRoute = false; return; }

            // plan-29 #2 B:controller 文件路径 + 行号拼接(file:line),空行号时只 file
            let ctrlFileDisplay = '';
            if (r.controller_file) {
                ctrlFileDisplay = r.controller_line ? (r.controller_file + ':' + r.controller_line) : r.controller_file;
            }

            this.route = Object.assign({}, r, {
                display_key:  r.acl_key  ? r.acl_key  : '-',
                display_hash: r.acl_hash ? r.acl_hash : '-',
                controller_file_display: ctrlFileDisplay,
            });
            this.methodLabel = Array.isArray(r.methods) ? r.methods.join(' / ') : (r.method || '');

            // 中文路径 module · controller · action(空段过滤,避免 "·  ·" 空隙)
            const segs = [r.acl_module_zh, r.acl_controller_zh, r.acl_zh_name].filter(s => s && s.length);
            this.zhPath = segs.join(' · ');

            // siblings 派生:每条预算 display_label + row_class(避开模板 ||  / 三元)
            this.siblings = (d.siblings || []).map(s => {
                const isCurrent = s.uri === r.uri && s.method === r.method;
                return Object.assign({}, s, {
                    display_label: s.acl_zh_name || s.api_name || s.action,
                    row_class: isCurrent ? 'p-route-drawer__sibling is-current' : 'p-route-drawer__sibling',
                });
            });
            this.hasSiblings = this.siblings.length > 1;  // 只有自己就不算"兄弟"
            this.hasMiddleware = Array.isArray(r.middleware) && r.middleware.length > 0;
            this.hasSiblingApps = Array.isArray(r.sibling_apps) && r.sibling_apps.length > 0;
            this.missingAcl = !r.acl_key && !r.acl_zh_name;

            // plan-29 #3 C1:前端拼 curl(避免 controller 跟 host 耦合;白名单跳过 Authorization)
            this.curlCommand = this._buildCurl(r);

            // plan-29 fix:transform_methods 提示项(路由 key / ACL target,二者跟 ACL 明文不同时才有意义)
            const tips = [];
            if (r.acl_transformed) {
                if (r.route_plain_key && r.route_plain_key !== r.acl_key) {
                    tips.push({ label: '路由 key', value: r.route_plain_key });
                }
                if (Array.isArray(r.acl_targets) && r.acl_targets.length > 0) {
                    tips.push({ label: '鉴权落到', value: r.acl_targets.join('  |  ') });
                }
            }
            this.transformTips = tips;

            this.hasRoute = true;
        },

        _buildCurl(r) {
            // r.methods 可能是 ['GET','HEAD'],取第一个有意义的
            const method = (Array.isArray(r.methods) && r.methods[0]) || r.method || 'GET';
            const host = (typeof location !== 'undefined') ? (location.protocol + '//' + location.host) : '';
            const lines = ["curl -X " + method + " '" + host + r.uri + "'"];
            if (!r.is_whitelist) {
                lines.push("  -H 'Authorization: Bearer <token>'");
            }
            const hasBody = method === 'POST' || method === 'PUT' || method === 'PATCH';
            if (hasBody) {
                lines.push("  -H 'Content-Type: application/json'");
                lines.push("  -d '{}'");
            }
            return lines.join(' \\\n');
        },

        selectSiblingFromEl(event) {
            const btn = event && event.currentTarget;
            if (!btn) return;
            const idx = parseInt(btn.getAttribute('data-sibling-idx') || '-1', 10);
            if (idx < 0 || idx >= this.siblings.length) return;
            // 复用 handleSet,保持 siblings 列表稳定,只换 route 视角
            this.handleSet({ detail: { route: this.siblings[idx], siblings: this.siblings } });
        },

        copyPlain() { this._copy(this.route.acl_key || ''); },
        copyHash()  { this._copy(this.route.acl_hash || ''); },
        copyControllerFile() { this._copy(this.route.controller_file_display || ''); },
        copyCurl()  { this._copy(this.curlCommand || ''); },
        _copy(text) {
            if (!text) return;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(() => this._toast('已复制'), () => this._toast('复制失败', 'danger'));
            } else {
                // 兜底:textarea + execCommand(老浏览器)
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed'; ta.style.left = '-9999px';
                document.body.appendChild(ta); ta.select();
                try { document.execCommand('copy'); this._toast('已复制'); }
                catch (e) { this._toast('复制失败', 'danger'); }
                finally { document.body.removeChild(ta); }
            }
        },
        // 2026-05-24 二轮 audit A1:toastContainer.push 读 `tone` 不读 `type`,
        // 旧 `type` 一直被吞,所有 ACL drawer toast 落 neutral 灰。改成 `tone`。
        _toast(msg, tone) {
            window.dispatchEvent(new CustomEvent('toast', { detail: { message: msg, tone: tone || 'success' } }));
        },
    }));

});

// ─────────────────────────────────────────────────────────
// config 页：map 类型字段编辑器（hosts 等）—— 纯原生委托事件，不走 Alpine
// CSP build 对 <template x-for> 内 :name 绑定不稳定，绕开
// ─────────────────────────────────────────────────────────
document.addEventListener('click', (event) => {
    const removeBtn = event.target.closest('[data-map-remove]');
    if (removeBtn) {
        const row = removeBtn.closest('[data-map-row]');
        if (row) row.remove();
        return;
    }

    const addBtn = event.target.closest('[data-map-add]');
    if (addBtn) {
        const editor = addBtn.closest('[data-scaffold-map]');
        if (!editor) return;
        const path = editor.dataset.mapPath || '';
        const seq = parseInt(editor.dataset.mapSeq || '0', 10) || 0;
        editor.dataset.mapSeq = String(seq + 1);
        const rid = 'r' + seq;
        const rowsBox = editor.querySelector('[data-map-rows]');
        if (!rowsBox) return;
        const fieldLabel = editor.dataset.mapFieldLabel || path;
        const rowIndex = rowsBox.querySelectorAll('[data-map-row]').length + 1;
        const tpl = document.createElement('div');
        tpl.className = 'p-config-map-row';
        tpl.setAttribute('data-map-row', '');
        const inK = document.createElement('input');
        inK.type = 'text';
        inK.className = 'p-config-input p-config-input--map-k';
        inK.name = 'fields[' + path + '][' + rid + '][k]';
        inK.placeholder = '名称';
        inK.setAttribute('aria-label', fieldLabel + ' 第 ' + rowIndex + ' 行 名称');
        const validator = editor.dataset.mapValidator || '';
        const inV = document.createElement('input');
        inV.type = validator === 'url' ? 'url' : 'text';
        inV.className = 'p-config-input p-config-input--map-v';
        inV.name = 'fields[' + path + '][' + rid + '][v]';
        inV.placeholder = validator === 'url' ? 'http(s)://...' : 'URL / 值';
        inV.setAttribute('aria-label', fieldLabel + ' 第 ' + rowIndex + ' 行 值');
        const del = document.createElement('button');
        del.type = 'button';
        del.className = 'btn btn--ghost btn--sm';
        del.setAttribute('data-map-remove', '');
        del.setAttribute('aria-label', '删除第 ' + rowIndex + ' 行');
        del.textContent = '删';
        tpl.appendChild(inK);
        tpl.appendChild(inV);
        tpl.appendChild(del);
        rowsBox.appendChild(tpl);
        inK.focus();
    }
});
