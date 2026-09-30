'use strict';
const assert = require('assert/strict');
const fs = require('fs');
const vm = require('vm');
const path = require('path');
const root = path.join(__dirname, '../..');
const context = { window: {}, Date };
vm.runInNewContext(fs.readFileSync(path.join(root, 'public/javascript/api-debug-history.js'), 'utf8'), context);
let passed = 0;
function check(value, expected) { assert.deepEqual(value, expected); passed++; }
const original = {
    method: 'GET', status: 200, uri: '/login?TOKEN=abc&name=a%20b',
    full_url: 'https://example.test/login?user%5Bpassword%5D=pw&access_token=abc&access_token=def&name=a%20b',
    headers: { Authorization: 'Bearer abc', Accept: 'application/json' },
    url_params: { user: { password: 'pw' }, name: 'a b' }, body_params: { 'login.password': 'pw' }
};
const sanitized = context.window.ScaffoldDebugHistory.sanitizeEntry(original);
check(sanitized.full_url, 'https://example.test/login?user%5Bpassword%5D=***&access_token=***&access_token=***&name=a%20b');
check(sanitized.uri, '/login?TOKEN=***&name=a%20b');
check(sanitized.headers.Authorization, '***'); check(sanitized.headers.Accept, 'application/json');
check(sanitized.url_params.user.password, '***'); check(sanitized.body_params['login.password'], '***');
check(original.headers.Authorization, 'Bearer abc'); check(original.url_params.user.password, 'pw');
let stored = JSON.stringify([original]);
context.localStorage = { getItem: () => stored, setItem: (_, value) => { stored = value; }, removeItem: () => { stored = '[]'; } };
context.cfg = { currentApp: 'admin' };
const source = fs.readFileSync(path.join(root, 'public/javascript/pages/api-request-index.js'), 'utf8');
// 执行页面真实历史存取函数，验证旧记录迁移和新增记录均不会保留 URL 中的秘密。
const start = source.indexOf("        var HISTORY_KEY_PREFIX =");
const end = source.indexOf('        function statusToneClass', start);
vm.runInNewContext(source.slice(start, end) + '\nwindow.readHistory = readHistory;', context);
context.window.readHistory();
check(JSON.parse(stored)[0].full_url, sanitized.full_url);
context.window.recordApiHistoryEntry(original);
check(JSON.parse(stored).length, 1); check(JSON.parse(stored)[0].count, 2);
check(stored.includes('Bearer abc'), false); check(stored.includes('=def'), false);
const view = fs.readFileSync(path.join(root, 'src/Http/Views/api/request.blade.php'), 'utf8');
check(view.indexOf('javascript/api-debug-history.js') < view.indexOf('javascript/pages/api-request-index.js'), true);
console.log('API 调试历史 URL/参数/头脱敏及旧记录回写（' + passed + ' passed）');
