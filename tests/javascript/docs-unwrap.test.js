/**
 * `docs-editor.js` / `docs-home.js` 的**解包接线守卫**（统一 JSON 信封 · 第 2 项 · 阶段 2）。
 *
 * 为什么值得单独守：这两个文件是 DocsController 全部 6 个 JSON 端点在前端的**唯一**消费者，
 * 而它们的失效方式**全是静默的** —— PHP 侧一条都测不到：
 *   1. 成功载荷漏解包（照旧读 `res.html`）⇒ 预览整块空白，**不报错**；
 *   2. `res.redirect` 漏解包 ⇒ 删除后 `window.location.href = undefined`，页面卡住不动；
 *   3. 搜索漏解包 ⇒ `data.results` 变 undefined ⇒ 永远显示"没有匹配"；
 *   4. 错误文案仍按旧形态读 `xhr.responseJSON.error`（字符串）⇒ 新信封下 `error` 是**对象**，
 *      toast 直接喷 `[object Object]`。
 *
 * 四条的共同点：**只有真人在浏览器里点到才会发现**，任何 PHP 测试都覆盖不到。
 *
 * 所以本文件守两层：
 *   1. **行为层** —— 借用 `api.js` 的真实实现，证明「解包后确实取到载荷 / 不解包就取不到」；
 *   2. **形态层** —— 源码扫描，证明每个成功回调都接了解包层、且旧读法已清零
 *      （否则行为层照样绿，而接线被改了回去）。
 *
 * ⚠ 形态层是**按文件内容**扫的，注释也算数 —— 所以被扫的文件里，注释也别写出旧读法的字面量。
 *   本项开发中就因此红了两次：一次是 `DocsController` 的 docblock 里写了裸 JSON 出口的字面写法
 *   （被 `JsonEnvelopeTest` 的结构不变式扫红），一次是这里的旧顶层读法。
 */
'use strict';

const path = require('path');
const fs = require('fs');

const JS_DIR = path.join(__dirname, '..', '..', 'public', 'javascript');
const EDITOR_PATH = path.join(JS_DIR, 'pages', 'docs-editor.js');
const HOME_PATH = path.join(JS_DIR, 'pages', 'docs-home.js');

const editorSrc = fs.readFileSync(EDITOR_PATH, 'utf8');
const homeSrc = fs.readFileSync(HOME_PATH, 'utf8');

// `api.js` 是 IIFE、入参就是 `window`，node 下必须先补这个全局再 require（同 designer-unwrap 的 shim）。
global.window = {};
require(path.join(JS_DIR, 'api.js'));
const Api = global.window.ScaffoldApi;

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

const count = (src, re) => (src.match(re) || []).length;

console.log('== 行为层：解包后取得到、不解包取不到 ==');

// ⚠ 两种回调拿到的东西**不是同一种**，这是最容易写错的一处：
//   `success: function (res)` 的 res 是**已解析的 body**（不是 jqXHR）；
//   `error: function (xhr)` 的 xhr 才是 jqXHR（解包层从 .responseJSON 读）。
// 所以成功路径必须用「裸 body」形态验，只测 jqXHR 形态会漏掉真实入参。
const previewEnvelope = { ok: true, data: { html: '<p>x</p>' } };
eq('preview（真实成功入参=裸 body）：经 data() 取到 html', Api.data(previewEnvelope).html, '<p>x</p>');
eq('preview：照旧直读顶层 html 会拿到 undefined（=预览空白且不报错）', previewEnvelope.html, undefined);
eq('delete（裸 body）：经 data() 取到 redirect', Api.data({ ok: true, data: { redirect: '/x' } }).redirect, '/x');
eq('search（裸 body）：经 data() 取到 results', (Api.data({ ok: true, data: { results: [1] } }).results || []).length, 1);
eq('picker（裸 body）：经 data() 取到 endpoints', Array.isArray(Api.data({ ok: true, data: { endpoints: [], tables: [] } }).endpoints), true);

// 迁移期旧响应（裸数据，无 ok 键）也必须照样能取 —— 后端按控制器灰度迁移时会有这个窗口
eq('迁移期旧形态：裸数据原样透出', (Api.data({ results: [1, 2] }).results || []).length, 2);

// jqXHR 形态（错误回调）也走一遍，证明两种入参形状都吃
eq('jqXHR 形态同样取得到', Api.data({ status: 200, responseJSON: { ok: true, data: { html: 'y' } } }).html, 'y');

// 失败体：新信封的 error 是对象，旧写法 `xhr.responseJSON.error || '默认'` 会把对象当文案
const failEnvelope = { status: 422, responseJSON: { ok: false, error: { code: 'UNKNOWN_SOURCE', msg: '未知文档源 [ghost]。', detail: [] } } };
eq('失败：errorText 取到 msg（旧写法会 toast 出 [object Object]）', Api.errorText(failEnvelope, '保存失败'), '未知文档源 [ghost]。');
eq('失败：旧读法确实会拿到对象（所以必须走 errorText）', typeof failEnvelope.responseJSON.error, 'object');
eq('失败：顶层字符串 error 的读法已删 ⇒ 回退到调用方文案（让"还有人产旧形态"可见）',
    Api.errorText({ status: 422, responseJSON: { error: '炸了' } }, '保存失败'), '保存失败');

console.log('== 形态层：旧读法必须清零 ==');

for (const [name, src] of [['docs-editor.js', editorSrc], ['docs-home.js', homeSrc]]) {
    // `responseJSON` 只在"自己解析错误体"时出现 —— 全站已收敛到 ScaffoldApi
    eq(name + '：responseJSON 直读清零', count(src, /responseJSON/g), 0);
    // 旧顶层载荷直读（解包漏一处的典型症状）
    eq(name + '：res.html / res.redirect / res.changed 直读清零', count(src, /\bres\.(html|redirect|changed)\b/g), 0);
    // 旧写法 `data.results` 只在 render 的**形参**里合法；顶层直读已清零
    eq(name + '：旧 {error:"…"} 提取写法清零', count(src, /xhr\.responseJSON|json\.error \|\|/g), 0);
}

console.log('== 形态层：每个载荷读取点都接了解包层 ==');

// docs-editor.js 读了 3 处载荷（preview / delete / picker）；save 成功不看 body，故不计
eq('docs-editor.js：ScaffoldApi.data( 恰好 3 处（preview / delete / picker）', count(editorSrc, /window\.ScaffoldApi\.data\(/g), 3);
eq('docs-editor.js：preview 解包（含 `|| {}` 保留对空响应的容忍）', editorSrc.includes('var payload = window.ScaffoldApi.data(res) || {};'), true);
eq('docs-editor.js：delete 解包 redirect', editorSrc.includes('window.location.href = window.ScaffoldApi.data(res).redirect'), true);
eq('docs-editor.js：picker 解包 catalog', editorSrc.includes('catalog = window.ScaffoldApi.data(res)'), true);
// 2 处错误回调（save / delete）
eq('docs-editor.js：errorText 恰好 2 处（save / delete）', count(editorSrc, /window\.ScaffoldApi\.errorText\(/g), 2);

// docs-home.js 读了 2 处载荷（reorder / search）
eq('docs-home.js：ScaffoldApi.data( 恰好 2 处（reorder / search）', count(homeSrc, /window\.ScaffoldApi\.data\(/g), 2);
eq('docs-home.js：reorder 解包 changed', homeSrc.includes('var payload = window.ScaffoldApi.data(res) || {};'), true);
eq('docs-home.js：search 把**载荷**交给 render，而不是整个信封', homeSrc.includes('render(window.ScaffoldApi.data(res), q);'), true);
eq('docs-home.js：errorText 恰好 1 处（reorder）', count(homeSrc, /window\.ScaffoldApi\.errorText\(/g), 1);

console.log('\n' + (failures.length === 0 ? '✅ ALL PASS' : '❌ ' + failures.length + ' FAILED') + '（' + pass + ' passed）');
process.exit(failures.length === 0 ? 0 : 1);
