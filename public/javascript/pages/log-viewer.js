/* Scaffold 与原生 Log Viewer 之间的页面桥接：只处理登录失效提示和只读菜单。 */
(function () {
    'use strict';

    var config = window.ScaffoldLogViewer;
    var NativeXHR = window.XMLHttpRequest;
    if (!config || !NativeXHR) return;

    var apiPath = String(config.apiPath || '').replace(/\/+$/, '');
    var activeRequests = new Set();
    var expired = false;

    function isLogApi(url) {
        if (!apiPath.startsWith('/')) return false;
        try {
            var target = new URL(url, window.location.href);
            return target.origin === window.location.origin
                && (target.pathname === apiPath || target.pathname.startsWith(apiPath + '/'));
        } catch (_error) {
            return false;
        }
    }

    function showExpired() {
        if (expired) return;
        expired = true;
        var panel = document.getElementById('scaffold-log-viewer-auth');
        var login = document.getElementById('scaffold-log-viewer-login');
        if (panel && login) {
            var url = new URL(config.loginPath, window.location.origin);
            url.searchParams.set('redirect', window.location.pathname + window.location.search + window.location.hash);
            login.href = url.pathname + url.search + url.hash;
            panel.hidden = false;
            var viewer = document.getElementById('log-viewer');
            if (viewer) viewer.inert = true;
            login.focus();
        }
        activeRequests.forEach(function (request) { request.abort(); });
        activeRequests.clear();
    }

    class ScaffoldXHR extends NativeXHR {
        constructor() {
            super();
            this.scaffoldLogApi = false;
            this.addEventListener('loadend', () => {
                activeRequests.delete(this);
                if (this.scaffoldLogApi && this.status === 401
                    && this.getResponseHeader('X-Scaffold-Auth') === 'required') {
                    showExpired();
                }
            });
        }

        open() {
            this.scaffoldLogApi = isLogApi(arguments[1]);
            return super.open(...arguments);
        }

        send() {
            // 已过期后的新请求仍交给原生 XHR 完成。OPENED 尚未 send 的
            // XHR 直接 abort 不发结束事件，会令 Axios Promise 一直悬空。
            if (this.scaffoldLogApi && !expired) activeRequests.add(this);
            return super.send(...arguments);
        }
    }

    window.XMLHttpRequest = ScaffoldXHR;

    if (!config.readonly) return;

    // v3.24 的菜单还有 "Clearing..." / "Index cleared" 等 v-show 状态文本。
    // 这些文本会同时留在 textContent 里，因此只对原始动作标签的 span 作精确匹配。
    var clearLabels = new Set(['Clear index', 'Clear indices', 'Clear indices for all files']);
    var root = document.getElementById('log-viewer');
    if (!root) return;

    var pendingMenus = new Set();
    var scheduled = false;

    function collectMenus(node, includeDescendants) {
        if (!node) return;
        var element = node.nodeType === 1 ? node : node.parentElement;
        if (!element) return;
        var item = element.closest('[role="menuitem"]');
        if (item) pendingMenus.add(item);
        if (includeDescendants) {
            element.querySelectorAll('[role="menuitem"]').forEach(function (menu) {
                pendingMenus.add(menu);
            });
        }
    }

    function hideClearActions() {
        scheduled = false;
        pendingMenus.forEach(function (item) {
            if (!root.contains(item)) return;
            var button = item.matches('button') ? item : item.querySelector('button');
            if (!button) return;
            var isClear = Array.from(button.querySelectorAll('span')).some(function (span) {
                return clearLabels.has(span.textContent.replace(/\s+/g, ' ').trim());
            });
            if (isClear && button.style.display !== 'none') {
                button.hidden = true;
                button.style.display = 'none';
            }
        });
        pendingMenus.clear();
    }

    collectMenus(root, true);
    hideClearActions();
    new MutationObserver(function (records) {
        records.forEach(function (record) {
            // 更新菜单文字/style 时只处理所属菜单；新增内容只扫描新增子树。
            collectMenus(record.target, false);
            if (record.type === 'childList') {
                record.addedNodes.forEach(function (node) { collectMenus(node, true); });
            }
        });
        if (pendingMenus.size && !scheduled) {
            scheduled = true;
            queueMicrotask(hideClearActions);
        }
    }).observe(root, {
        childList: true,
        characterData: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['style'],
    });
}());
