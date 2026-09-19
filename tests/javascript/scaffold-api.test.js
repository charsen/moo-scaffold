/**
 * `public/javascript/api.js`（统一响应解包层）的行为守卫。
 *
 * 为什么单独一个 node 脚本、不进 Playwright：
 *   本层是**纯函数**，没有 DOM / 网络依赖，用 e2e 验它是杀鸡用牛刀（还要起宿主 + 录登录态）。
 *   而它又是后端信封迁移期**唯一**的兼容保障 —— 一行的优先级写反，全站 toast 都在撒谎，
 *   所以必须有一个能在 CI / 无宿主环境跑起来、秒级返回的守卫。
 *
 * 覆盖面：迁移期间新旧**全部**响应形态（新信封 / 裸数据 / {error} / {message} / {_proxy_status}
 * / 无 status 的已解析 json / responseText 兜底），确认双形态兼容、迁移可灰度进行。
 *
 * 跑法：`npm run test:js`（或直接 `node tests/javascript/scaffold-api.test.js`，退出码即结果）
 */
'use strict';

const path = require('path');

// api.js 是给浏览器用的 IIFE（挂到 window）—— 这里造一个最小 window 把它 require 进来。
global.window = {};
require(path.join(__dirname, '..', '..', 'public', 'javascript', 'api.js'));
const A = global.window.ScaffoldApi;

let pass = 0;
const failures = [];

function eq(label, got, want) {
    const g = JSON.stringify(got);
    const w = JSON.stringify(want);
    if (g === w) {
        pass += 1;
        console.log('  ok    ' + label);
    } else {
        failures.push(label);
        console.log('  FAIL  ' + label + '  | 期望 ' + w + '，实得 ' + g);
    }
}

// 模拟 jQuery jqXHR（.status + 已解析的 .responseJSON）
const jq = (status, body) => ({ status, responseJSON: body });
const jqText = (status, text) => ({ status, responseText: text });

console.log('== 新信封（统一后的目标形态）==');
eq('isOk 成功', A.isOk(jq(200, { ok: true, data: { a: 1 } })), true);
eq('data 取 data 层', A.data(jq(200, { ok: true, data: { a: 1 } })), { a: 1 });
eq('isOk 失败', A.isOk(jq(422, { ok: false, error: { code: 'X', msg: 'm', detail: [] } })), false);
eq('errorText 取 error.msg', A.errorText(jq(422, { ok: false, error: { code: 'X', msg: 'm', detail: [] } })), 'm');
eq('errorCode 取 error.code', A.errorCode(jq(422, { ok: false, error: { code: 'X', msg: 'm', detail: [] } })), 'X');

console.log('== 旧形态（迁移期间并存 —— 双形态兼容的核心保证）==');
eq('裸数据 = 成功', A.isOk(jq(200, { q: 'x', results: [], truncated: false })), true);
eq('裸数据 data 原样透出', A.data(jq(200, { q: 'x', results: [] })).q, 'x');
eq('{ok:true,redirect} 成功', A.isOk(jq(200, { ok: true, redirect: '/x' })), true);
eq('{error:"字符串"} 失败', A.isOk(jq(422, { error: '炸了' })), false);
eq('errorText 取字符串 error', A.errorText(jq(422, { error: '炸了' })), '炸了');
eq('{message} + 500 失败（body 无成败标记，只能靠状态码判）', A.isOk(jq(500, { message: 'boom' })), false);
eq('errorText 取 message', A.errorText(jq(500, { message: 'boom' })), 'boom');
eq('{status:"ok"} 成功', A.isOk(jq(200, { status: 'ok' })), true);
eq('{html,error:null} 成功（error 为 null 不算失败）', A.isOk(jq(200, { html: '<p>x</p>', error: null })), true);

console.log('== 2xx 上的 error 是领域字段，不是失败 ==');
// 本地 Markdown 预览：PlansController.php:77 / ReleaseRecordsController.php:78
//   返回 {html, error:<frontmatter 警告|null>} + 200 —— 预览成功，只是 frontmatter 有问题
eq('{html,error:"警告"} + 200 仍是成功（预览已渲染）',
    A.isOk(jq(200, { html: '<p>x</p>', error: 'frontmatter 缺 order' })), true);
eq('无状态码时才用 error 键判失败（保守兜底）', A.isOk({ error: '炸了' }), false);
eq('{error:"…"} + 403 靠状态码判失败', A.isOk({ status: 403, responseJSON: { error: '只有 admin' } }), false);

eq('{_proxy_status:403} 失败（HTTP 恒 200）', A.isOk(jq(200, { _proxy_status: 403, message: 'no' })), false);
eq('{_proxy_status:200} 成功', A.isOk(jq(200, { _proxy_status: 200, data: 1 })), true);
eq('errorCode 用 _proxy_status 兜底', A.errorCode(jq(200, { _proxy_status: 403, message: 'no' })), 'HTTP_403');

console.log('== 只拿到已解析 json（无 status）==');
eq('json: 新信封失败', A.isOk({ ok: false, error: { code: 'X', msg: 'm' } }), false);
eq('json: {message} 无标记 → 视成功（调用方必须自己给 status）', A.isOk({ message: 'm' }), true);

console.log('== errorText 取值优先级 ==');
eq('有服务端文案时 body 优先于 fallback', A.errorText(jq(422, { error: '字段重复' }), '保存失败'), '字段重复');
eq('body 空 + 502 + fallback → 用 fallback', A.errorText(jq(502, {}), '保存失败'), '保存失败');
eq('body 空 + 502 无 fallback → 用 HTTP 状态码', A.errorText(jq(502, {})), 'HTTP 502');
eq('null + fallback → 用 fallback', A.errorText(null, '保存失败'), '保存失败');

console.log('== errorCode 与 errorText 的刻意不对称 ==');
eq('code 无 error.code 时状态码压过 fallback（fallback 是调用方入参，返回它零信息量）',
    A.errorCode(jq(500, { message: 'x' }), 'SAVE_FAILED'), 'HTTP_500');
eq('code 走 _proxy_status 时也用不了 fallback', A.errorCode(jq(200, { _proxy_status: 403 }), 'SAVE_FAILED'), 'HTTP_403');

console.log('== 健壮性 ==');
eq('responseText 能解析', A.data(jqText(200, '{"ok":true,"data":{"k":9}}')).k, 9);
eq('responseText 非 JSON 不抛异常', A.data(jqText(200, '<html>')), null);
eq('pick(undefined) 不抛异常', A.pick(undefined), null);
eq('toError 形状统一', A.toError(jq(422, { ok: false, error: { code: 'C', msg: 'M', detail: [] } })), { code: 'C', msg: 'M', http: 422 });

console.log('\n' + (failures.length === 0 ? '✅ ALL PASS' : '❌ ' + failures.length + ' FAILED') + '（' + pass + ' passed）');
process.exit(failures.length === 0 ? 0 : 1);
