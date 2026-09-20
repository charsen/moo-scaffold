/**
 * `designer.js` 的 `_post` / `_get` / `_unwrap` 接线守卫。
 *
 * 为什么值得单独守：`_post` / `_get` 原先各抄了一份**逐字相同**的解包尾巴（set code / detail /
 * status、失败即抛、成功取 `data`）。收敛到 `ScaffoldApi` 时，最容易出的错不是"解包结果错"，
 * 而是**把抛出的 Error 上少挂一个字段** —— 调用方在别处以 `e.code` 分支（6 处）、
 * 读 `e.detail.reason`、按 `e.status` 判要不要重试。少一个字段不会立刻报错，
 * 只会让某个分支静默走错路（typ. 显示 "error" 而不是真实原因），极难归因。
 *
 * 所以本文件守两层（正交，缺一层都能被绕过）：
 *   1. **行为** —— `_unwrap` 在 4 种响应形态下抛出的 Error 必须带齐 code/detail/status/message；
 *   2. **形态** —— `_post` / `_get` 不得再各自内联解包逻辑（否则去重被人改回去，行为层照样绿）。
 */
'use strict';

const path = require('path');
const fs = require('fs');

// designer.js 是 Alpine 组件：需要 `document.addEventListener('alpine:init', …)` 与 `Alpine.data()`。
// 最小 shim 即可把它 require 进来并拿到组件对象 —— 不需要 jsdom、不需要真 Alpine。
const handlers = {};
const comps = {};
global.window = {};
global.document = { addEventListener: (ev, cb) => { handlers[ev] = cb; } };
global.Alpine = { data: (name, factory) => { comps[name] = factory; } };

const DESIGNER_PATH = path.join(__dirname, '..', '..', 'public', 'javascript', 'designer.js');
require(path.join(__dirname, '..', '..', 'public', 'javascript', 'api.js'));
require(DESIGNER_PATH);

handlers['alpine:init']();
const designer = comps['dbDesigner']();

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

// 假 fetch Response：只用到 .status 与 .json()
const res = (status, body) => ({ status, json: async () => body });
const resBadJson = (status) => ({ status, json: async () => { throw new SyntaxError('not json'); } });

/** 跑一个必然失败的 _unwrap，把抛出的 Error 取回来（没抛就返回 null）。 */
async function catchErr(promise) {
    try {
        await promise;
        return null;
    } catch (e) {
        return e;
    }
}

(async () => {
    console.log('== 成功路径：只回业务数据 ==');
    eq('新信封取 data 层', await designer._unwrap(res(200, { ok: true, data: { a: 1 } })), { a: 1 });
    eq('旧裸数据原样透出', await designer._unwrap(res(200, { q: 'x', results: [] })), { q: 'x', results: [] });
    // {ok:true,redirect}（DocsController::delete 的形态）**没有 data 键** ⇒ 原样透出整包，
    // 不是只给 redirect —— 新旧实现在这里一致，故锁住它、避免以后有人"顺手"把 ok 剥掉。
    eq('{ok:true,redirect} 无 data 键则原样透出（与旧实现一致）', await designer._unwrap(res(200, { ok: true, redirect: '/x' })), { ok: true, redirect: '/x' });
    eq('body 不是 JSON 也不抛（200 → {}）', await designer._unwrap(resBadJson(200)), {});

    console.log('== 失败路径：Error 必须带齐 code / detail / status / message ==');
    const e1 = await catchErr(designer._unwrap(res(422, {
        ok: false, error: { code: 'COMPACT_BLOCKED', msg: '有脏数据', detail: { reason: 'dirty' } },
    })));
    eq('抛出的是 Error', e1 instanceof Error, true);
    eq('e.code（6 处 e.code 分支靠它）', e1.code, 'COMPACT_BLOCKED');
    eq('e.message（各处 toast 靠它）', e1.message, '有脏数据');
    eq('e.detail 带出嵌套对象（compactBlockedReason 读 .reason）', e1.detail, { reason: 'dirty' });
    eq('e.detail.reason 可直接取到', e1.detail && e1.detail.reason, 'dirty');
    eq('e.status（_shouldRetrySave 靠它）', e1.status, 422);

    console.log('== 旧形态与降级 ==');
    const e2 = await catchErr(designer._unwrap(res(422, { error: '炸了' })));
    // 顶层字符串 `error` 的读法 2026-09-19 已从 ScaffoldApi 删除 ⇒ 文案落到 `_unwrap` 传的 fallback。
    // 这条断言是**故意**钉住降级后的行为：host 若还产 `{error:"…"}`，toast 会显示「请求失败」而不是
    // 那句内容 —— 看得见的回退，好过被静默当作正常文案。
    eq('字符串 error 不再被当文案（容忍已删）⇒ 落到 fallback', e2.message, '请求失败');
    eq('字符串 error 下 detail 为 undefined（保持原语义）', typeof e2.detail, 'undefined');
    eq('无 error 对象时 code 降级为 HTTP_<码>', e2.code, 'HTTP_422');
    eq('e.status 仍是真实状态码', e2.status, 422);

    const e3 = await catchErr(designer._unwrap(res(500, {})));
    eq('空 body 5xx：code 为 HTTP_500', e3.code, 'HTTP_500');
    eq('空 body 5xx：message 兜底为「请求失败」', e3.message, '请求失败');
    eq('空 body 5xx：_shouldRetrySave 语义 = 5xx 要重试', e3.status >= 500, true);

    const e4 = await catchErr(designer._unwrap(resBadJson(500)));
    eq('body 非 JSON 时按状态判失败，不抛解析错', e4 && e4.code, 'HTTP_500');

    console.log('== 形态不变式：去重不许被改回去 ==');
    const src = fs.readFileSync(DESIGNER_PATH, 'utf8');
    eq('_post / _get 各自只有一行 this._unwrap(res)（共 2 处）', (src.match(/return this\._unwrap\(res\);/g) || []).length, 2);
    eq('内联解包尾巴已清零（json.error ? json.error 出现 0 次）', (src.match(/json\.error \? json\.error/g) || []).length, 0);
    eq('内联失败判断已清零（if (!res.ok) 出现 0 次）', (src.match(/if \(!res\.ok\)/g) || []).length, 0);
    eq('解包只此一处实现（_unwrap 定义 1 次）', (src.match(/async _unwrap\(res\)/g) || []).length, 1);

    console.log('\n' + (failures.length === 0 ? '✅ ALL PASS' : '❌ ' + failures.length + ' FAILED') + '（' + pass + ' passed）');
    process.exit(failures.length === 0 ? 0 : 1);
})();
