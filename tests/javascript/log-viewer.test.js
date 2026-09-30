/** Log Viewer 页面桥接：原生XHR身份、限定401处理、只读动态菜单。 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const script = fs.readFileSync(path.join(__dirname, '../../public/javascript/pages/log-viewer.js'), 'utf8');
let passed = 0;
function check(label, fn) {
    fn();
    passed += 1;
    console.log('  ok    ' + label);
}

function setup(readonly = true, initialMenus = []) {
    const panel = { hidden: true };
    const login = { href: '', focusCount: 0, focus() { this.focusCount += 1; } };
    const menus = initialMenus.slice();
    let observer;
    const tasks = [];
    let rootScans = 0;

    class NativeXHR {
        static DONE = 4;
        constructor() {
            this.events = {};
            this.status = 0;
            this.headers = {};
            this.aborted = 0;
        }
        addEventListener(name, listener) { this.events[name] = listener; }
        open(...args) { this.openArgs = args; return 'native-open'; }
        send(...args) { this.sendArgs = args; return 'native-send'; }
        abort() { this.aborted += 1; return 'native-abort'; }
        getResponseHeader(name) { return this.headers[name] || null; }
        complete(status, authHeader) {
            this.status = status;
            this.headers['X-Scaffold-Auth'] = authHeader;
            this.events.loadend();
        }
    }

    const root = {
        nodeType: 1, inert: false, closest: () => null,
        querySelectorAll: () => { rootScans += 1; return menus; },
        contains: (item) => menus.includes(item),
    };
    class Observer {
        constructor(callback) { this.callback = callback; observer = this; }
        observe(target, options) { this.target = target; this.options = options; }
        trigger(records) { this.callback(records); }
    }
    const location = new URL('https://city.test/scaffold/logs?file=abc&host=local#details');
    const window = {
        XMLHttpRequest: NativeXHR,
        ScaffoldLogViewer: { apiPath: '/scaffold/logs/api', loginPath: '/scaffold/login', readonly },
        location,
    };
    const document = {
        getElementById(id) {
            return { 'scaffold-log-viewer-auth': panel, 'scaffold-log-viewer-login': login, 'log-viewer': root }[id];
        },
    };
    vm.runInNewContext(script, { window, document, URL, Set, Array, MutationObserver: Observer, queueMicrotask: (fn) => tasks.push(fn) });
    return {
        window, NativeXHR, panel, login, menus, root, tasks,
        flush() { while (tasks.length) tasks.shift()(); },
        get rootScans() { return rootScans; },
        get observer() { return observer; },
    };
}

function request(ctx, url, status, authHeader) {
    const xhr = new ctx.window.XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.send('original-body');
    xhr.complete(status, authHeader);
    return xhr;
}

function menu(label, roleOnButton = true) {
    const spans = (Array.isArray(label) ? label : [label]).map((textContent) => ({ textContent }));
    const button = {
        nodeType: 1, hidden: false, scans: 0,
        style: { display: '' },
        querySelectorAll(selector) { if (selector === 'span') this.scans += 1; return selector === 'span' ? spans : []; },
    };
    const item = roleOnButton ? Object.assign(button, { matches: () => true }) : {
        nodeType: 1, matches: () => false, querySelector: () => button,
        querySelectorAll: () => [],
    };
    item.closest = () => item;
    return { item, button };

}

check('XHR继承原型和静态常量，保留open/send参数与原生返回', () => {
    const ctx = setup();
    const xhr = new ctx.window.XMLHttpRequest();
    assert.ok(xhr instanceof ctx.NativeXHR);
    assert.equal(ctx.window.XMLHttpRequest.DONE, 4);
    assert.equal(xhr.open('POST', '/scaffold/logs/api/files', true, 'u', 'p'), 'native-open');
    assert.equal(xhr.send('original-body'), 'native-send');
    assert.deepEqual(xhr.openArgs, ['POST', '/scaffold/logs/api/files', true, 'u', 'p']);
    assert.deepEqual(xhr.sendArgs, ['original-body']);
});

check('限定同源日志API的401专用头才提示，redirect用当前页面筛选条件', () => {
    const ctx = setup();
    request(ctx, '/scaffold/logs/api/files?sort=latest', 401, 'required');
    assert.equal(ctx.panel.hidden, false);
    assert.equal(ctx.root.inert, true);
    assert.equal(ctx.login.focusCount, 1);
    const target = new URL(ctx.login.href, ctx.window.location.origin);
    assert.equal(target.pathname, '/scaffold/login');
    assert.equal(target.searchParams.get('redirect'), '/scaffold/logs?file=abc&host=local#details');
    assert.ok(!target.searchParams.get('redirect').includes('/api/'));
});

check('其他401、403、跨域或相似path不显示失效提示', () => {
    const ctx = setup();
    request(ctx, '/scaffold/logs/api/files', 401, null);
    request(ctx, '/scaffold/logs/api/files', 403, 'required');
    request(ctx, 'https://other.test/scaffold/logs/api/files', 401, 'required');
    request(ctx, '/scaffold/logs/apiary', 401, 'required');
    request(ctx, '/scaffold/api', 401, 'required');
    assert.equal(ctx.panel.hidden, true);
});

check('过期后中断已发日志XHR，新请求仍走原生send完成，其他请求不受影响', () => {
    const ctx = setup();
    const pending = new ctx.window.XMLHttpRequest();
    pending.open('GET', '/scaffold/logs/api/logs');
    pending.send();
    request(ctx, '/scaffold/logs/api/files', 401, 'required');
    assert.equal(pending.aborted, 1);
    const nextLog = new ctx.window.XMLHttpRequest();
    nextLog.open('GET', '/scaffold/logs/api/logs');
    assert.equal(nextLog.send(), 'native-send');
    assert.deepEqual(nextLog.sendArgs, []);
    nextLog.complete(401, 'required');
    assert.equal(nextLog.aborted, 0);
    assert.equal(ctx.login.focusCount, 1);
    const elsewhere = new ctx.window.XMLHttpRequest();
    elsewhere.open('GET', '/scaffold/api');
    assert.equal(elsewhere.send(), 'native-send');
});

check('只读隐藏三种维护菜单，保留下载、刷新和搜索；动态菜单继续处理', () => {
    const ctx = setup(true);
    assert.equal(ctx.observer.target, ctx.root);
    assert.equal(ctx.observer.options.subtree, true);
    const file = menu(['Clear index', 'Clearing...', 'Index cleared']);
    const folder = menu('Clear indices', false);
    const all = menu('Clear indices for all files');
    const download = menu('Download');
    const refresh = menu('Refresh file list');
    const search = menu('Search');
    const similarlyNamed = menu('Clear index settings');
    ctx.menus.push(file.item, folder.item, all.item, download.item, refresh.item, search.item, similarlyNamed.item);
    ctx.observer.trigger([{ type: 'childList', target: ctx.root, addedNodes: ctx.menus }]);
    ctx.flush();
    for (const action of [file, folder, all]) {
        assert.equal(action.button.hidden, true);
        assert.equal(action.button.style.display, 'none');
    }
    for (const action of [download, refresh, search, similarlyNamed]) assert.equal(action.button.hidden, false);
    const dynamic = menu('Clear index');
    ctx.menus.push(dynamic.item);
    ctx.observer.trigger([{ type: 'childList', target: ctx.root, addedNodes: [dynamic.item] }]);
    ctx.flush();
    assert.equal(dynamic.button.hidden, true);
});

check('菜单更新合并为一次任务，仅处理新增或更新菜单，不重扫日志树', () => {
    const old = menu('Clear index');
    const ctx = setup(true, [old.item]);
    const first = menu('Clear indices');
    const second = menu('Download');
    ctx.menus.push(first.item, second.item);
    ctx.observer.trigger([{ type: 'childList', target: ctx.root, addedNodes: [first.item] }]);
    ctx.observer.trigger([{ type: 'childList', target: ctx.root, addedNodes: [second.item] }]);
    assert.equal(ctx.tasks.length, 1);
    ctx.flush();
    assert.equal(ctx.rootScans, 1);
    assert.equal(old.button.scans, 1);
    assert.equal(first.button.hidden, true);
    assert.equal(second.button.hidden, false);
    // 上游 v-show 再次改 style，仍须恢复只读隐藏；同菜单重复记录只处理一次。
    first.button.style.display = '';
    const record = { type: 'attributes', target: first.item };
    ctx.observer.trigger([record, record]);
    ctx.flush();
    assert.equal(first.button.style.display, 'none');
    assert.equal(first.button.scans, 2);
    ctx.observer.trigger([{ type: 'childList', target: ctx.root, addedNodes: [{ nodeType: 1, closest: () => null, querySelectorAll: () => [] }] }]);
    assert.equal(ctx.tasks.length, 0);
    assert.equal(ctx.rootScans, 1);
});

check('可写状态不隐藏原生菜单，也不创建observer', () => {
    const action = menu('Clear index');
    const ctx = setup(false, [action.item]);
    assert.equal(ctx.observer, undefined);
    assert.equal(action.button.hidden, false);
});

console.log('\n✅ ALL PASS（' + passed + ' passed）');
