// ==============================================================================
// 页面级脚本: api/request 调试主页（侧栏切换 + 参数面板加载 + 本机最近调试历史）
// 消费 view: api/request.blade.php
// 配置注入: window.ScaffoldRequestIndex = {
//     routes: { apiParam },
//     currentApp, currentAppName
// }
// 依赖: jQuery, pages/api-request.js (getScopedCacheKey / getDebugHostScope /
//      getDebugClientId / restoreAuthToken / Process / 发送按钮等已在此文件挂全局)
// ==============================================================================

(function () {
    var cfg = window.ScaffoldRequestIndex || {};
    var routes = cfg.routes || {};

    // -------- 基础控制 --------

    function setResponsePane(pane) {
        $('.api-debug-response-tab').removeClass('is-active');
        $('.api-debug-response-pane').removeClass('is-active');
        $('.api-debug-response-tab[data-pane="' + pane + '"]').addClass('is-active');
        $('.api-debug-response-pane[data-pane="' + pane + '"]').addClass('is-active');
    }

    // -------- 拉参数表 --------

    function getParams(folder, controller, action, method, options) {
        options = options || {};
        var $activeLink = $('#aside_container .side-tree__item.is-active .side-tree__item-link');
        document.title = (cfg.currentAppName || cfg.currentApp || '')
                       + ' - '
                       + ($activeLink.data('module') || '')
                       + ' - '
                       + ($activeLink.data('api-name') || $.trim($activeLink.text()));

        $.ajax({
            type: 'GET',
            url: routes.apiParam,
            data: {
                app: cfg.currentApp,
                f: folder,
                c: controller,
                a: action,
                host_scope: window.getDebugHostScope && window.getDebugHostScope(),
                client_id: window.getDebugClientId && window.getDebugClientId()
            },
            dataType: 'html',
            success: function (result) {
                // 异步期间用户可能已切到别的 tab —— 此前无条件写 #left_container,会把当前
                // active tab 的实时表单 DOM 覆盖成「这个迟到响应所属」接口的参数(#send 早有
                // sendingTabId 守卫,getParams 当时漏了,2026-06-10 补)。归属 tab 已不 active →
                // 把参数 DOM 暂存进该 tab 的 detached storage,切回时 _restore 还原,绝不碰 live DOM。
                var ownerTabId = options.tabId || null;
                var tabs = window.ScaffoldDebugTabs;
                var stillActive = !ownerTabId || !tabs || tabs.activeTabId === ownerTabId;
                if (!stillActive) {
                    tabs.stashLeftDomHtml(ownerTabId, result);
                    return;
                }
                $('#left_container').html(result);
                setResponsePane('body');
                $('#header').html('等待响应头……');
                $('#json_format').html('准备发送请求……');
                // 2026-05-23 user 反馈 bug:切换接口时上一接口的表单预览残留(DOM 内容 + tab 还在),
                // 清掉 tab/main/output DOM + 内部 state(scaffoldClearFormPreview 由 api-request.js 暴露)
                if (typeof window.scaffoldClearFormPreview === 'function') {
                    window.scaffoldClearFormPreview();
                }
                $('#result_method').html($('#send_method').val());
                $('#result_uri').html(($('#host').val() || '') + ($('#uri').val() || ''));

                window.restoreAuthToken && window.restoreAuthToken();

                if (typeof options.onParamsReady === 'function') {
                    options.onParamsReady();
                }

                if (options.skipAutoSend) return;

                // index / authenticate / logout / 所有 GET 自动发起请求,其它方法等用户手动点 Send
                var check = new RegExp(/^(index|authenticate|logout)[\_\w]*$/);
                if (check.test(action) || method == 'GET') {
                    $('#send').trigger('click');
                }
            },
            error: function () {
                // 加载失败:图标 + 文案 + 重试按钮(重试直接重发本次 getParams,不用回左栏重点接口)
                var $fail = $(
                    '<div class="api-load-fail">' +
                    '<svg class="api-load-fail__icon" xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>' +
                    '<p class="api-load-fail__text">参数表加载失败</p>' +
                    '<button type="button" class="api-load-fail__retry">重试</button>' +
                    '</div>'
                );
                $fail.find('.api-load-fail__retry').on('click', function () {
                    $('#left_container').html('<p class="api-debug-loading">loading...</p>');
                    getParams(folder, controller, action, method, options);
                });
                $('#left_container').empty().append($fail);
            }
        });
    }

    // 暴露给 view 入口脚本（条件初始化）
    window.scaffoldRequestIndexGetParams = getParams;

    // =========================================================================
    // plan-50 ScaffoldDebugTabs — 多接口 tabs
    //   - left_container 子节点 DOM 交换(参数编辑值零丢失)
    //   - response panel 走 state(lastResponseJson/Headers/Meta)按需 Process 重渲
    //   - sidebar 点接口 → open or switch(已开过则切到已存在 tab)
    //   - 软上限 10 个 tab,关闭 × 自动切到上一个
    // =========================================================================
    var ScaffoldDebugTabs = {
        tabs: [],              // [{id, app, f, c, a, m, url, label, leftDom, lastResponseJson, lastHeaders, lastMeta, formPreviewState, sentAt}]
        activeTabId: null,
        MAX_TABS: 10,

        _tabId: function (meta) {
            return (cfg.currentApp || '') + '::' + meta.f + '::' + meta.c + '::' + meta.a;
        },

        // opts(可选,历史回填用):{ skipAutoSend, afterReady } —— afterReady 在参数就绪后回调,
        // 用于回填 method/uri/params;new tab 走 getParams 成功后,existing tab 走 switch 之后。
        openOrSwitch: function (meta, opts) {
            opts = opts || {};
            var id = this._tabId(meta);
            var existing = this.tabs.find(function (t) { return t.id === id; });
            if (existing) {
                this.switch(id);
                if (typeof opts.afterReady === 'function') opts.afterReady();
                return;
            }

            if (this.tabs.length >= this.MAX_TABS) {
                this._toast('已达 ' + this.MAX_TABS + ' 个 tab 上限，右键 tab 可批量关闭后再开', 'warning');
                return;
            }

            // capture current tab(if any)before getting new tab's params
            if (this.activeTabId) this._captureCurrent();

            // create new tab in pending state(待 getParams 返回后填 DOM)
            var tab = {
                id: id, app: cfg.currentApp, f: meta.f, c: meta.c, a: meta.a, m: meta.m,
                url: meta.url, label: meta.apiName || meta.a, module: meta.module || '',
                leftDom: null,                   // detached <div> holding params children;loaded after ajax
                lastResponseJson: null,
                lastHeaders: '',
                lastMeta: null,                  // { status, uri, elapsed, size, time, method }
                formPreviewState: { matrix: [], values: {}, cascaderOpenId: null },
                responsePane: 'body',            // body / headers / form-preview
                hasSent: false,
            };
            this.tabs.push(tab);
            this.activeTabId = id;
            this._renderTabsBar();
            this._syncSidebarHighlight();

            // reset live DOM to loading state
            window.history.pushState({}, 0, meta.url);
            $('#left_container').html('<p class="api-debug-loading">loading...</p>');
            this._resetResponseDom();
            setResponsePane('body');

            // fetch params(沿用原 getParams;opts 透传给历史回填:跳过自动发送 + 参数就绪回调)
            // tabId:本次加载归属的 tab,success 里据它判断是否仍 active(防迟到响应串台)
            getParams(meta.f, meta.c, meta.a, meta.m, {
                skipAutoSend: opts.skipAutoSend,
                onParamsReady: opts.afterReady,
                tabId: id
            });
        },

        switch: function (id) {
            if (this.activeTabId === id) return;
            if (this.activeTabId) this._captureCurrent();
            var newTab = this.tabs.find(function (t) { return t.id === id; });
            if (!newTab) return;
            this._restore(newTab);
            this.activeTabId = id;
            this._renderTabsBar();
            this._syncSidebarHighlight();
            if (newTab.url) window.history.pushState({}, 0, newTab.url);
        },

        close: function (id) {
            var idx = this.tabs.findIndex(function (t) { return t.id === id; });
            if (idx < 0) return;
            var isActive = (this.activeTabId === id);
            if (isActive) {
                // 选下一个 tab:优先右侧,再左侧
                var next = this.tabs[idx + 1] || this.tabs[idx - 1] || null;
                this.tabs.splice(idx, 1);
                if (next) {
                    this._restore(next);
                    this.activeTabId = next.id;
                    if (next.url) window.history.pushState({}, 0, next.url);
                } else {
                    this.activeTabId = null;
                    this._showEmpty();
                }
            } else {
                this.tabs.splice(idx, 1);
            }
            this._renderTabsBar();
            this._syncSidebarHighlight();
        },

        // 批量关闭:ids = 要关的 tab id 列表;keepId = 关完后要 active 的 tab(可空)
        // 一次性改数组 + 单次 restore/render,避免逐个 close 的 active 抖动
        _closeMany: function (ids, keepId) {
            if (!ids.length) return;
            var idSet = {};
            ids.forEach(function (id) { idSet[id] = true; });
            var activeClosed = !!idSet[this.activeTabId];
            this.tabs = this.tabs.filter(function (t) { return !idSet[t.id]; });
            if (activeClosed) {
                var keep = null;
                if (keepId) keep = this.tabs.find(function (t) { return t.id === keepId; });
                if (!keep) keep = this.tabs[this.tabs.length - 1] || null;
                if (keep) {
                    this._restore(keep);
                    this.activeTabId = keep.id;
                    if (keep.url) window.history.pushState({}, 0, keep.url);
                } else {
                    this.activeTabId = null;
                    this._showEmpty();
                }
            }
            this._renderTabsBar();
            this._syncSidebarHighlight();
        },

        closeAll: function () {
            if (!this.tabs.length) return;
            this.tabs = [];
            this.activeTabId = null;
            this._showEmpty();
            this._renderTabsBar();
            this._syncSidebarHighlight();
        },

        // === 右键菜单(关闭 / 关闭其他 / 关闭右侧 / 关闭所有)===

        openContextMenu: function (id, x, y) {
            this._closeContextMenu();
            var self = this;
            var idx = this.tabs.findIndex(function (t) { return t.id === id; });
            if (idx < 0) return;
            var total = this.tabs.length;

            var items = [
                { action: 'close',  label: '关闭',     enabled: true },
                { action: 'others', label: '关闭其他', enabled: total > 1 },
                { action: 'right',  label: '关闭右侧', enabled: idx < total - 1 },
                { action: 'all',    label: '关闭所有', enabled: true, danger: true, divider: true },
            ];

            var $menu = $('<div class="api-debug-tab-menu" id="api_debug_tab_menu" role="menu"></div>');
            items.forEach(function (it) {
                if (it.divider) $('<div class="api-debug-tab-menu__divider"></div>').appendTo($menu);
                var $it = $('<button type="button" class="api-debug-tab-menu__item" role="menuitem"></button>')
                    .text(it.label)
                    .attr('data-action', it.action);
                if (it.danger) $it.addClass('is-danger');
                if (!it.enabled) $it.addClass('is-disabled').prop('disabled', true);
                $it.appendTo($menu);
            });
            $('body').append($menu);

            // 定位:超出视口右/下边界时翻向左/上
            var mw = $menu.outerWidth();
            var mh = $menu.outerHeight();
            var px = (x + mw > window.innerWidth) ? Math.max(4, x - mw) : x;
            var py = (y + mh > window.innerHeight) ? Math.max(4, y - mh) : y;
            $menu.css({ left: px + 'px', top: py + 'px' });

            $menu.on('click', '.api-debug-tab-menu__item', function (e) {
                e.stopPropagation();
                if ($(this).hasClass('is-disabled')) return;
                var action = $(this).data('action');
                self._closeContextMenu();
                self._runMenuAction(action, id);
            });

            // 关闭触发:外部按下 / 右键别处 / Esc / 滚动 / resize
            // setTimeout 0 避开触发本菜单的同一次事件冒泡
            setTimeout(function () {
                $(document).on('mousedown.tabmenu', function (e) {
                    if (!$(e.target).closest('#api_debug_tab_menu').length) self._closeContextMenu();
                });
                $(document).on('keydown.tabmenu', function (e) {
                    if (e.key === 'Escape') self._closeContextMenu();
                });
                $(document).on('contextmenu.tabmenu', function (e) {
                    if (!$(e.target).closest('.api-debug-tabs__item').length) self._closeContextMenu();
                });
                $(window).on('scroll.tabmenu resize.tabmenu', function () { self._closeContextMenu(); });
            }, 0);
        },

        _closeContextMenu: function () {
            $('#api_debug_tab_menu').remove();
            $(document).off('.tabmenu');
            $(window).off('.tabmenu');
        },

        _runMenuAction: function (action, id) {
            var idx = this.tabs.findIndex(function (t) { return t.id === id; });
            if (idx < 0) return;
            if (action === 'close') {
                this.close(id);
            } else if (action === 'others') {
                var others = this.tabs.filter(function (t) { return t.id !== id; }).map(function (t) { return t.id; });
                this._closeMany(others, id);
            } else if (action === 'right') {
                var right = this.tabs.slice(idx + 1).map(function (t) { return t.id; });
                this._closeMany(right, id);
            } else if (action === 'all') {
                this.closeAll();
            }
        },

        // === 内部 ===

        // 迟到的 getParams 响应落到「已切走」的归属 tab:把参数 HTML 收进该 tab 的 detached
        // storage(等价 _captureCurrent 的产物),下次 switch/restore 到它时正常还原。
        stashLeftDomHtml: function (tabId, html) {
            var t = this.tabs.find(function (x) { return x.id === tabId; });
            if (!t) return;
            var storage = document.createElement('div');
            storage.innerHTML = html;
            t.leftDom = storage;
        },

        _captureCurrent: function () {
            var t = this.tabs.find(function (tt) { return tt.id === ScaffoldDebugTabs.activeTabId; });
            if (!t) return;
            // 把 #left_container 的所有子节点 detach 到 storage div
            var storage = document.createElement('div');
            var lc = document.getElementById('left_container');
            if (lc) {
                while (lc.firstChild) storage.appendChild(lc.firstChild);
            }
            t.leftDom = storage;
            // response state 已经在 send success / form preview render 时直接更新进 tab,这里只 capture pane
            var $activePane = $('.api-debug-response-tab.is-active');
            t.responsePane = $activePane.data('pane') || 'body';
            // 表单预览的用户输入值也快照进 tab —— _restore 时作为 overrideValues 重渲,
            // 避免切 tab 丢失预览面板里填的值(2026-06-09 修)。
            if (window.scaffoldGetFormPreviewValues) {
                t.formPreviewState = { values: window.scaffoldGetFormPreviewValues() };
            }
        },

        _restore: function (t) {
            var lc = document.getElementById('left_container');
            if (lc) {
                lc.innerHTML = '';
                if (t.leftDom) {
                    while (t.leftDom.firstChild) lc.appendChild(t.leftDom.firstChild);
                } else {
                    lc.innerHTML = '<p class="api-debug-loading">loading...</p>';
                }
            }
            // result bar
            if (t.lastMeta) {
                $('#result_status').html(t.lastMeta.status || '');
                $('#result_method').html(t.lastMeta.method || '');
                $('#result_uri').html(t.lastMeta.uri || '');
                if (t.lastMeta.elapsed || t.lastMeta.size || t.lastMeta.time) {
                    $('#result_elapsed').text(t.lastMeta.elapsed || '—');
                    $('#result_size').text(t.lastMeta.size || '—');
                    $('#result_time').text(t.lastMeta.time || '—');
                    $('#result_meta').removeAttr('hidden');
                } else {
                    $('#result_meta').attr('hidden', 'hidden');
                }
            } else {
                $('#result_status').html('WAIT');
                $('#result_method').html('...');
                $('#result_uri').html('发送请求后将在这里展示实际地址');
                $('#result_meta').attr('hidden', 'hidden');
            }
            // response body / header
            if (t.lastResponseJson !== null) {
                try { Process({ id: 'json_format', data: t.lastResponseJson }); }
                catch (e) { $('#json_format').text('<re-render err>'); }
            } else {
                $('#json_format').html('准备发送请求……');
            }
            $('#header').text(t.lastHeaders || '等待响应头……');   // headersText 是纯文本,.text() 渲染且防注入
            // form preview state restore — 先清再按需重渲(tryRenderFormPreview);第二个参是该 tab
            // 切走前用户填的预览值,优先于响应默认还原(2026-06-09 修)。
            if (window.scaffoldClearFormPreview) window.scaffoldClearFormPreview();
            if (t.lastResponseJson !== null && window.scaffoldRehydrateFormPreview) {
                window.scaffoldRehydrateFormPreview(
                    t.lastResponseJson,
                    t.formPreviewState && t.formPreviewState.values
                );
            }
            // pane 激活放在 clear/rehydrate 之后:否则先 setResponsePane('form-preview') 又被
            // clearFormPreview 摘掉 is-active → 没有 pane 激活、响应区空白(2026-06-09 修)。
            // 上次看的是 form-preview 但本次响应无 widgets(tab 已隐藏)→ 回退 body。
            var pane = t.responsePane || 'body';
            if (pane === 'form-preview' && $('#form_preview_tab').prop('hidden')) pane = 'body';
            setResponsePane(pane);
            // 只在 token 为空时补填:还原回来的 leftDom 已带着该 tab 原有 token(getParams 填的
            // 或用户手填的),无条件 restoreAuthToken 会把用户手改的 token 冲回 host 保存值(2026-06-09 修)。
            var $tok = $('#auth_token');
            if ($tok.length && ! $.trim($tok.val()) && window.restoreAuthToken) {
                window.restoreAuthToken();
            }
            // 浏览器标签标题也跟着切回的 tab 走 —— 同上,document.title 只在 getParams 设过,
            // switch / close 走 _restore 不同步 → 切 tab 后标题停在上一个接口(2026-06-11 修)。
            document.title = (cfg.currentAppName || cfg.currentApp || '') + ' - ' + (t.module || '') + ' - ' + (t.label || '');
        },

        _resetResponseDom: function () {
            $('#result_status').html('');
            $('#result_method').html('...');
            $('#result_uri').html('发送请求后将在这里展示实际地址');
            $('#result_meta').attr('hidden', 'hidden');
            $('#header').html('等待响应头……');
            $('#json_format').html('准备发送请求……');
            if (window.scaffoldClearFormPreview) window.scaffoldClearFormPreview();
        },

        _showEmpty: function () {
            $('#left_container').html('<x-scaffold::empty>'.replace(/<x-scaffold::empty>/, '<div class="api-debug-empty-state"><p>从左侧选择一个接口开始调试</p></div>'));
            this._resetResponseDom();
        },

        _renderTabsBar: function () {
            var $bar = $('#debug_tabs_bar');
            if (!$bar.length) return;
            $bar.find('.api-debug-tabs__item').remove();
            var empty = (this.tabs.length === 0);
            $bar.find('#debug_tabs_empty').prop('hidden', !empty);
            if (empty) return;
            var activeId = this.activeTabId;
            var $frag = $();
            this.tabs.forEach(function (t) {
                var isActive = (t.id === activeId);
                var $item = $('<button type="button" class="api-debug-tabs__item" role="tab"></button>')
                    .attr('data-tab-id', t.id)
                    .attr('title', t.module ? t.module + ' · ' + t.label : t.label)
                    .toggleClass('is-active', isActive);
                $('<span class="api-debug-tabs__method"></span>').text(t.m || '').addClass('is-' + (t.m || 'any').toLowerCase()).appendTo($item);
                $('<span class="api-debug-tabs__label"></span>').text(t.label).appendTo($item);
                $('<span class="api-debug-tabs__close" role="button" aria-label="关闭" tabindex="0">×</span>')
                    .attr('data-tab-close', t.id)
                    .appendTo($item);
                $frag = $frag.add($item);
            });
            $bar.append($frag);
        },

        _syncSidebarHighlight: function () {
            $('#aside_container .side-tree__item.is-active').removeClass('is-active');
            if (!this.activeTabId) return;
            var t = this.tabs.find(function (x) { return x.id === ScaffoldDebugTabs.activeTabId; });
            if (!t) return;
            // sidebar item 用 data-f/c/a 作为 selector
            $('#aside_container .side-tree__item-link').each(function () {
                if ($(this).data('f') === t.f && $(this).data('c') === t.c && $(this).data('a') === t.a) {
                    $(this).parent().addClass('is-active');
                }
            });
        },

        // params loaded(getParams ajax 成功后从原 success handler 调一次)
        notifyParamsLoaded: function () {
            // params loaded 时已经 inject 到 #left_container,我们什么都不做 — capture 会在下次 switch 时拿
            // 但要让外部回调有钩子(plan-50 后续可能要做点啥)
        },

        // send success 时回写(api-request.js 的 #send handler 在 success 里调)
        // tabId:发起请求的 tab(异步期间可能已切走 → 必须按 id 回写,不能用当前 activeTabId)
        notifySendSuccess: function (responseJson, meta, tabId) {
            var id = tabId || ScaffoldDebugTabs.activeTabId;
            var t = this.tabs.find(function (x) { return x.id === id; });
            if (!t) return;
            t.lastResponseJson = responseJson;
            t.lastMeta = meta;
            t.hasSent = true;
        },

        notifySendHeaders: function (headersText, tabId) {
            var id = tabId || ScaffoldDebugTabs.activeTabId;
            var t = this.tabs.find(function (x) { return x.id === id; });
            if (!t) return;
            t.lastHeaders = headersText;
        },

        _toast: function (msg, type) {
            // scaffold 全局 toast bridge — window.scaffoldToast(msg, type)
            // tone: 'success' | 'warning' | 'danger' | 'info' | 'neutral'
            if (window.scaffoldToast) window.scaffoldToast(msg, type || 'warning');
            else console.log('[ScaffoldDebugTabs] ' + (type || 'info') + ':', msg);
        },
    };
    window.ScaffoldDebugTabs = ScaffoldDebugTabs;

    // -------- 启动 --------

    $(function () {
        $('#right_container').removeClass('transparent');

        // 恢复上次选择的环境
        var savedHost = localStorage.getItem('scaffold_api_host');
        if (savedHost && $('#host').is('select')) {
            $('#host option').each(function () {
                if ($(this).val() === savedHost) {
                    $(this).prop('selected', true);
                }
            });
        }

        $('#host').on('change', function () {
            localStorage.setItem('scaffold_api_host', $(this).val());
            window.restoreAuthToken && window.restoreAuthToken();
        });

        // plan-50 后续:aside 顶部 app 切换 select(替代 subnav app-tabs)
        $('body').on('change', '#api_debug_app_select', function () {
            var tpl = $(this).data('route-tpl') || '';
            var k = $(this).val();
            if (tpl && k) window.location.href = tpl.replace('__APPKEY__', encodeURIComponent(k));
        });

        $('body').on('click', '.api-debug-response-tab', function () {
            setResponsePane($(this).data('pane'));
        });

        // plan-50 tabs bar click 委托
        $('body').on('click', '.api-debug-tabs__item', function (e) {
            // 排除点 close
            if ($(e.target).closest('.api-debug-tabs__close').length) {
                ScaffoldDebugTabs.close($(this).data('tab-id'));
                e.stopPropagation();
                return;
            }
            ScaffoldDebugTabs.switch($(this).data('tab-id'));
        });

        // plan-50 tabs:右键菜单(批量关闭),缓解 10 tab 上限场景
        $('body').on('contextmenu', '.api-debug-tabs__item', function (e) {
            e.preventDefault();
            ScaffoldDebugTabs.openContextMenu($(this).data('tab-id'), e.clientX, e.clientY);
        });

        setResponsePane('body');

        // plan-50:sidebar click → ScaffoldDebugTabs.openOrSwitch(meta);保留旧 getParams 调用
        // 作为内部实现,但 tab orchestration 控制重渲 / 还原 / 切换 / capture。
        $('#aside_container').on('click', '.side-tree__item-link', function (e) {
            if ($(this).data('f') == undefined) return true;
            e.preventDefault();
            ScaffoldDebugTabs.openOrSwitch({
                f: $(this).data('f'),
                c: $(this).data('c'),
                a: $(this).data('a'),
                m: $(this).data('m'),
                url: $(this).data('url'),
                module: $(this).data('module'),
                apiName: $(this).data('api-name') || $.trim($(this).text()),
            });
        });

        // -------- 最近调试记录抽屉：localStorage 历史 + 分页 + 点击回填 --------
        var HISTORY_KEY_PREFIX = 'scaffold.apiHistory.';
        var HISTORY_LIMIT = 100;
        var PAGE_SIZE = 10;
        var MASK_HEADERS = ['authorization', 'cookie', 'x-csrf-token'];
        var MASK_VALUE = '***';   // 脱敏占位:入库时替换敏感 header,回填时据此跳过(不覆盖真实 token)
        // 敏感参数键:密码 / token 类不该明文落 localStorage 历史(header 早已脱敏,参数此前漏了 →
        // 登录密码明文跨会话留存,2026-06-10 补)。回填时 skipMasked 跳过 *** 占位,用户重填。
        var MASK_PARAM_KEYS = /^(password|passwd|pwd|old_password|new_password|token|secret|api_?key|access_token|refresh_token|authorization)$/i;
        var currentHistoryPage = 1;

        function historyKey() {
            return HISTORY_KEY_PREFIX + (cfg.currentApp || 'default');
        }

        function readHistory() {
            try {
                return JSON.parse(localStorage.getItem(historyKey()) || '[]');
            } catch (e) {
                return [];
            }
        }

        function writeHistory(list) {
            try {
                localStorage.setItem(historyKey(), JSON.stringify(list));
            } catch (e) {
                try {
                    localStorage.setItem(historyKey(), JSON.stringify(list.slice(0, Math.floor(HISTORY_LIMIT / 2))));
                } catch (e2) {}
            }
        }

        function clearHistory() {
            try { localStorage.removeItem(historyKey()); } catch (e) {}
        }

        function maskHeaders(headers) {
            if (!headers) return headers;
            var out = {};
            for (var k in headers) {
                if (!Object.prototype.hasOwnProperty.call(headers, k)) continue;
                out[k] = MASK_HEADERS.indexOf(String(k).toLowerCase()) !== -1 ? MASK_VALUE : headers[k];
            }
            return out;
        }

        // 递归脱敏参数:键名命中 MASK_PARAM_KEYS → ***;嵌套对象 / 数组继续往下走。
        function maskParams(obj) {
            if (!obj || typeof obj !== 'object') return obj;
            var out = Array.isArray(obj) ? [] : {};
            for (var k in obj) {
                if (!Object.prototype.hasOwnProperty.call(obj, k)) continue;
                if (MASK_PARAM_KEYS.test(String(k))) {
                    out[k] = MASK_VALUE;
                } else if (obj[k] && typeof obj[k] === 'object') {
                    out[k] = maskParams(obj[k]);
                } else {
                    out[k] = obj[k];
                }
            }
            return out;
        }

        // 同接口(method + f/c/a)+ 同状态 视为同一条:用它去重 + 计数,避免连续调试刷屏
        function historyDedupeKey(e) {
            return [
                String(e.method || '').toUpperCase(),
                e.folder, e.controller, e.action, e.status
            ].join('|');
        }

        // 暴露给 api-request.js 在发送回调里入库
        window.recordApiHistoryEntry = function (entry) {
            if (!entry) return;
            entry.timestamp = Date.now();
            entry.headers = maskHeaders(entry.headers);
            entry.url_params = maskParams(entry.url_params);
            entry.body_params = maskParams(entry.body_params);
            var list = readHistory();
            // 命中已有同接口同状态记录 → 累加次数、移除旧位置,再把最新一次(参数/耗时/时间已刷新)置顶
            var key = historyDedupeKey(entry), dupIdx = -1;
            for (var i = 0; i < list.length; i++) {
                if (historyDedupeKey(list[i]) === key) { dupIdx = i; break; }
            }
            entry.count = dupIdx === -1 ? 1 : ((parseInt(list[dupIdx].count, 10) || 1) + 1);
            if (dupIdx !== -1) list.splice(dupIdx, 1);
            list.unshift(entry);
            if (list.length > HISTORY_LIMIT) list = list.slice(0, HISTORY_LIMIT);
            writeHistory(list);
        };

        function statusToneClass(status) {
            var s = parseInt(status, 10);
            if (s >= 200 && s < 300) return 'font-green';
            if (s === 422) return 'font-orange';
            if (s >= 400) return 'font-red';
            return 'font-muted';
        }

        function statusIcon(status) {
            var s = parseInt(status, 10);
            if (s >= 200 && s < 300) return '✓';
            if (s >= 400 && s < 500) return '⚠';
            if (s >= 500) return '✗';
            return '·';
        }

        function formatRelative(ts) {
            if (!ts) return '';
            var s = Math.floor((Date.now() - ts) / 1000);
            if (s < 60) return s + ' 秒前';
            var m = Math.floor(s / 60);
            if (m < 60) return m + ' 分钟前';
            var h = Math.floor(m / 60);
            if (h < 24) return h + ' 小时前';
            return Math.floor(h / 24) + ' 天前';
        }

        function truncate(s, n) {
            s = String(s == null ? '' : s);
            return s.length > n ? s.slice(0, n - 1) + '…' : s;
        }

        function populateRecentHistory(page) {
            var $list = $('#recent_records_list');
            var $empty = $('#recent_records_empty');
            var $pager = $('#recent_records_pager');
            var $clear = $('#recent_records_clear');
            if (!$list.length) return;

            var items = readHistory();
            $list.empty();
            if (!items.length) {
                $empty.removeAttr('hidden');
                $pager.attr('hidden', 'hidden');
                $clear.attr('hidden', 'hidden');
                return;
            }
            $empty.attr('hidden', 'hidden');
            $clear.removeAttr('hidden');

            var totalPages = Math.max(1, Math.ceil(items.length / PAGE_SIZE));
            currentHistoryPage = Math.min(Math.max(1, page || 1), totalPages);
            var start = (currentHistoryPage - 1) * PAGE_SIZE;
            var pageItems = items.slice(start, start + PAGE_SIZE);

            if (totalPages > 1) {
                $pager.removeAttr('hidden');
                $('#recent_records_pageinfo').text(currentHistoryPage + ' / ' + totalPages);
                $('#recent_records_prev').prop('disabled', currentHistoryPage <= 1);
                $('#recent_records_next').prop('disabled', currentHistoryPage >= totalPages);
            } else {
                $pager.attr('hidden', 'hidden');
            }

            pageItems.forEach(function (item, localIdx) {
                var index = start + localIdx;
                var method = String(item.method || 'GET').toUpperCase();
                var $li = $('<li class="api-debug-recent__item"></li>');
                var $btn = $('<button type="button" class="api-debug-recent__row"></button>')
                    .attr('data-history-index', index)
                    .attr('title', method + ' ' + (item.full_url || item.uri || ''));

                $btn.append($('<span class="api-debug-recent__row-method"></span>')
                    .addClass('method-badge--' + method.toLowerCase())
                    .text(method));
                $btn.append($('<span class="api-debug-recent__row-url"></span>')
                    .text(truncate(item.uri || item.full_url || '', 80)));

                var $status = $('<span class="api-debug-recent__row-status"></span>')
                    .addClass(statusToneClass(item.status));
                $status.append($('<span class="api-debug-recent__row-status-icon" aria-hidden="true"></span>')
                    .text(statusIcon(item.status)));
                $status.append($('<span></span>').text(item.status || '—'));
                $btn.append($status);

                var $meta = $('<span class="api-debug-recent__row-meta"></span>');
                var hitCount = parseInt(item.count, 10) || 1;
                if (hitCount > 1) {
                    $meta.append($('<span class="api-debug-recent__row-count"></span>')
                        .attr('title', '已调试 ' + hitCount + ' 次（同接口同状态合并）')
                        .text('×' + hitCount));
                }
                $meta.append($('<span></span>')
                    .text((item.elapsed_ms != null ? item.elapsed_ms + 'ms · ' : '') + formatRelative(item.timestamp)));
                $btn.append($meta);

                $li.append($btn);
                $list.append($li);
            });
        }

        // 把 obj 的 key/value 写回容器内已有的表单行（key 列匹配；未匹配的跳过）
        // 两种行结构都认:header 行 key 在 input.txt.key;参数行(url/body)key 在 .send-key
        // (入库时 validKey 用的键)/ .cache-key(yaml 字段名)—— 否则 url/body 参数回填会是空操作。
        // opts.skipMasked:跳过值为脱敏占位(***)的项 —— 别用 *** 覆盖 restoreAuthToken
        // 已填好的真实 token(否则回填后再发就 401)。
        function applyKvRows(selector, obj, opts) {
            if (!obj) return;
            opts = opts || {};
            var $container = $(selector);
            if (!$container.length) return;
            // 按路径解析(scaffoldResolvePathValue):bracket-key 参数(items[0][name])的值存在嵌套
            // 结构里,直接 obj[key] 取不到 → 之前这类参数回填全丢。单段 key 仍是直接取(零回归)。
            var resolve = window.scaffoldResolvePathValue || function (o, k) {
                return k && Object.prototype.hasOwnProperty.call(o, k) ? { found: true, value: o[k] } : { found: false };
            };
            $container.find('tr').each(function () {
                var $row = $(this);
                var candidates = [
                    $row.find('input.txt.key').val(),     // header
                    $row.find('input.send-key').val(),    // 参数:入库键
                    $row.find('input.cache-key').val()    // 参数:yaml 字段名
                ];
                var r = { found: false };
                for (var i = 0; i < candidates.length; i++) {
                    r = resolve(obj, candidates[i]);
                    if (r.found) break;
                }
                if (!r.found) return;
                var v = r.value;
                if (opts.skipMasked && v === MASK_VALUE) return;
                // 数组(逗号参数)→ 文本框自动 join;嵌套对象跳过,避免写成 [object Object]
                if (v !== null && typeof v === 'object' && ! Array.isArray(v)) return;
                var $cb = $row.find('input.checkbox');
                if ($cb.length && ! $cb.prop('disabled')) $cb.prop('checked', true);
                // 用 .value(而非 input.txt.value):radio 类参数值是 <select class="select value">,
                // 对齐 applyJsonToRows 的取值,否则 select 参数只勾选不填值。
                $row.find('.value').first().val(v);
            });
        }

        function applyHistoryEntry(entry) {
            if (!entry) return;
            // plan-27:sideTree 用 .side-tree__item-link
            var $link = $('#aside_container .side-tree__item-link[data-f="' + entry.folder + '"][data-c="' + entry.controller + '"][data-a="' + entry.action + '"]');
            if (!$link.length) {
                window.dispatchEvent(new CustomEvent('close-drawer', { detail: 'recent-records' }));
                alert('该接口已不在当前应用，无法回填。');
                return;
            }

            // 走统一的 tab 编排(openOrSwitch)——和侧栏点击 / 首次落地同一条路径,
            // 避免直接 getParams 绕过 tabs 导致 tab 激活错位 + live DOM 与其它 tab 串台。
            // sidebar 高亮 / pushState / 参数加载 全由 openOrSwitch 内部统一处理。
            ScaffoldDebugTabs.openOrSwitch({
                f: entry.folder, c: entry.controller, a: entry.action, m: entry.method,
                url: $link.data('url'),
                module: $link.data('module'),
                apiName: $link.data('api-name') || $.trim($link.text()),
            }, {
                skipAutoSend: true,
                afterReady: function () {
                    if (entry.method) $('#send_method').val(entry.method);
                    if (entry.host && $('#host').is('select')) {
                        $('#host option').each(function () {
                            if ($(this).val() === entry.host) $(this).prop('selected', true);
                        });
                        // host 切了要按新 host 刷新 token —— getParams 里的 restoreAuthToken 跑在设
                        // host 之前,填的是切换前那个 host 的 token;不刷新 → 回填后用错/空 token →
                        // 莫名 401(2026-06-09 修)。放在 applyKvRows 之前,skipMasked 才能保住真 token。
                        if (window.restoreAuthToken) window.restoreAuthToken();
                    }
                    if (entry.uri != null) $('#uri').val(entry.uri);

                    // headers 跳过脱敏占位(***),保留 restoreAuthToken 已填的真实 token,避免回填后 401
                    applyKvRows('#request_header', entry.headers, { skipMasked: true });
                    // 参数同 header:脱敏占位(***)跳过,不用 *** 覆盖用户重填的真实密码 / token
                    applyKvRows('#request_params', entry.url_params, { skipMasked: true });
                    applyKvRows('#request_body_params', entry.body_params, { skipMasked: true });

                    $('#result_method').html($('#send_method').val());
                    $('#result_uri').html(($('#host').val() || '') + ($('#uri').val() || ''));
                }
            });

            window.dispatchEvent(new CustomEvent('close-drawer', { detail: 'recent-records' }));
        }

        $('body').on('click', '#recent_records_trigger', function () {
            populateRecentHistory(1);
            window.dispatchEvent(new CustomEvent('open-drawer', { detail: 'recent-records' }));
        });

        $('body').on('click', '.api-debug-recent__row', function () {
            var idx = parseInt($(this).attr('data-history-index'), 10);
            var list = readHistory();
            if (!isNaN(idx) && list[idx]) applyHistoryEntry(list[idx]);
        });

        $('body').on('click', '#recent_records_prev', function () {
            if ($(this).prop('disabled')) return;
            populateRecentHistory(currentHistoryPage - 1);
        });

        $('body').on('click', '#recent_records_next', function () {
            if ($(this).prop('disabled')) return;
            populateRecentHistory(currentHistoryPage + 1);
        });

        $('body').on('click', '#recent_records_clear', function () {
            if (!readHistory().length) return;
            if (!window.confirm('确定清空当前应用的最近调试记录吗？（仅清本机，不影响云端）')) return;
            clearHistory();
            populateRecentHistory(1);
        });
    });
})();
