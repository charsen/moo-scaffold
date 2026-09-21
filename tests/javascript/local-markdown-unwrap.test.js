/**
 * `pages/local-markdown-editor.js` 的**解包接线守卫**（统一 JSON 信封 · 第 2 项 · 阶段 2）。
 *
 * 为什么值得单独守：它是 PlansController / ReleaseRecordsController 那批「显式保存 + 版本校验」
 * 端点在前端的**唯一**消费者，而它的失效方式**全是静默的** —— PHP 侧一条都测不到：
 *   1. `data.version` 漏解包 ⇒ `version` 变 `undefined`，**本次保存看着成功**，
 *      下一次保存带着空 version 去撞后端的 409 冲突 —— 症状与根因隔了一次交互，极难归因；
 *   2. `data.html` 漏解包 ⇒ 预览整块空白，**不报错**；
 *   3. 拿 `isOk()` 去判 `payload.error` ⇒ 见下，这是**本文件独有的反向陷阱**。
 *
 * ⚠ **反向陷阱（本文件独有，别照抄 docs-unwrap 的写法）**：
 *   `{ok:true, data:{html, error}}` 里的 `error` 是 **frontmatter 领域字段**
 *   （预览成功、正文已渲染，只是 frontmatter 有问题要就地提示），**不是**失败信号。
 *   用 `isOk()` 判它 = 把「成功但有 frontmatter 提示」当失败吞掉 —— 所以这里反过来钉
 *   「本文件里 `isOk(` 必须 0 处」。
 *
 * 所以本文件守两层（同 `docs-unwrap.test.js` 的两层法）：
 *   1. **行为层** —— 借用 `api.js` 的真实实现，证明「解包后确实取到载荷 / 不解包就取不到」；
 *   2. **形态层** —— 源码扫描（**先剥注释**，理由见下），证明成功回调都接了解包层、
 *      且旧顶层读法已清零。
 *
 * 形态层的**最强一条**：本文件里 `responseJSON` 必须 **0 处**。
 *   2026-09-21 收口前，`.fail` 分支直读 `xhr.responseJSON` 取框架校验袋 —— 那是全仓最后一个
 *   绕开解包层的 body 读法，当时的守卫把它钉成「恰好 1 处（框架校验袋例外）」。现在改走
 *   `ScaffoldApi.pick(xhr)`（它读 `responseJSON`、拿不到再试 `JSON.parse(responseText)`、
 *   失败返回 `null` 不抛），于是这条断言从「1 处且合法」升级成「0 处」——
 *   不必再让后人逐个判断那一处是否合法。
 *
 * ⚠ 与 `docs-unwrap.test.js` 的差异：那个文件的形态层是**按原始文件内容**扫的，注释也算数。
 *   本文件的注释**故意**写出了旧读法来警告后人（`取 data.version 而不是 result.version`、
 *   `别用 isOk() 判它`）—— 那些警告比「注释里别出现旧字面量」的洁癖更有价值。
 *   所以这里先 `stripComments()` 再扫，并额外断言「剥注释没把代码也剥掉」。
 */
'use strict';

const path = require('path');
const fs = require('fs');

const JS_DIR = path.join(__dirname, '..', '..', 'public', 'javascript');
const PAGE_PATH = path.join(JS_DIR, 'pages', 'local-markdown-editor.js');

const rawSrc = fs.readFileSync(PAGE_PATH, 'utf8');

/**
 * 剥掉块注释与行注释。
 *
 * ⚠ 不做「字符串里出现 `//`」（如 `'http://…'`）的状态机处理 —— 本文件没有这种字面量。
 *   若将来加了，这里必须先补状态机，否则会把代码后半行一起吃掉、让形态层静默放行。
 *   为此下面有两条「剥注释自身可信」的断言兜底。
 */
function stripComments(src) {
    return src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/[^\n]*/g, '$1');
}

const src = stripComments(rawSrc);

// 同域的姊妹预览消费者：用来钉「两侧兜底一致」，避免只有一边被改（见形态层末两条）。
const editorSrc = stripComments(fs.readFileSync(path.join(JS_DIR, 'pages', 'docs-editor.js'), 'utf8'));

// `api.js` 是 IIFE、入参就是 `window`，node 下必须先补这个全局再 require（同 docs-unwrap 的 shim）。
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

const count = (haystack, re) => (haystack.match(re) || []).length;

console.log('== 剥注释自身可信（否则形态层会静默放行）==');
eq('剥注释确实动过原文（不是空操作）', src.length < rawSrc.length, true);
eq('代码没被剥掉：精确接线字符串仍在', src.includes('window.ScaffoldApi.data(result).version'), true);
eq('代码没被剥掉：校验袋读法仍在', src.includes('window.ScaffoldApi.pick(xhr)'), true);
eq('注释确实被剥掉：警告语里的 result.version 不再出现', count(src, /而不是 `result\.version`/g), 0);

console.log('== 行为层：解包后取得到、不解包取不到 ==');
// ⚠ 两种回调拿到的东西**不是同一种**：`.done(function (result))` 的 result 是**已解析 body**，
//   `.fail(function (xhr))` 的 xhr 才是 jqXHR。成功路径必须用「裸 body」形态验。
const saveEnvelope = { ok: true, data: { version: 'abc123' } };
eq('save（真实成功入参=裸 body）：经 data() 取到 version', Api.data(saveEnvelope).version, 'abc123');
eq('save：照旧直读顶层 version 会拿到 undefined（=下次保存撞 409 的静默坏法）', saveEnvelope.version, undefined);

const previewEnvelope = { ok: true, data: { html: '<p>x</p>', error: 'frontmatter 缺 group' } };
eq('preview（裸 body）：经 data() 取到 html', Api.data(previewEnvelope).html, '<p>x</p>');
eq('preview：直读顶层 html 会拿到 undefined（=预览空白且不报错）', previewEnvelope.html, undefined);

// ⚠ 本文件的反向陷阱：`payload.error` 是领域字段，不能拿 isOk() 判
eq('preview：成功信封 + frontmatter error 字段 ⇒ isOk() 仍为 true（所以绝不能用它判）',
    Api.isOk(previewEnvelope), true);
eq('preview：那个 error 就是领域字段本身（交给 frontmatterError 展示）',
    Api.data(previewEnvelope).error, 'frontmatter 缺 group');

// jqXHR 形态（错误回调）也走一遍，证明两种入参形状都吃
eq('jqXHR 形态同样取得到', Api.data({ status: 200, responseJSON: { ok: true, data: { version: 'z' } } }).version, 'z');

console.log('== 行为层：失败文案的三种来源 ==');
// ① 新信封（控制器产）：error 是对象，旧写法 `xhr.responseJSON.error || '默认'` 会把对象当文案
eq('信封失败：errorText 取到 msg',
    Api.errorText({ status: 422, responseJSON: { ok: false, error: { code: 'SAVE_FAILED', msg: '保存炸了', detail: [] } } }, '兜底'),
    '保存炸了');
eq('信封失败：旧读法确实会拿到对象（所以必须走 errorText）',
    typeof { ok: false, error: { msg: 'x' } }.error, 'object');
// ② 框架层 `{message}`：本文件的 409 冲突 / 403 / 422 全走这条（LocalMarkdownEditor 用 abort_*）
eq('框架层 {message}（409 冲突等）：errorText 取到 message',
    Api.errorText({ status: 409, responseJSON: { message: '文件已被修改，请重新打开后再编辑。' } }, '兜底'),
    '文件已被修改，请重新打开后再编辑。');
// ③ 表单校验袋 `{message, errors}`：字段级文案由页面自己读 `errors.content[0]`，
//    但**必须经 `pick()` 取 body**（形态层钉死 0 处直读 `responseJSON`）
const validationBag = { status: 422, responseJSON: { message: 'The given data was invalid.', errors: { content: ['正文不能为空'] } } };
eq('校验袋：errorText 取到框架 message',
    Api.errorText(validationBag, '兜底'), 'The given data was invalid.');
eq('校验袋：字段级文案仍可由页面自己读到（经 pick 取，不是直读 jqXHR）',
    Api.pick(validationBag).errors.content[0], '正文不能为空');
// ④ 什么都没有 → 回退到调用方文案（让「又有人产旧形态」可见）
eq('无 body：回退到调用方文案', Api.errorText({ status: 0 }, '保存失败，请重试，当前输入已保留。'), '保存失败，请重试，当前输入已保留。');

console.log('== 行为层：校验袋走 pick 的收益（直读 responseJSON 会漏掉的情形）==');
// jQuery 在 body 非 JSON / 空 body 时不给 responseJSON，只留 responseText；
// 直读 `xhr.responseJSON || {}` 会把「拿不到 body」和「body 里没有 errors」混成一件事。
const textOnlyXhr = { status: 422, responseText: '{"message":"x","errors":{"content":["正文不能为空"]}}' };
eq('responseJSON 缺失时：直读拿到 undefined', textOnlyXhr.responseJSON, undefined);
eq('responseJSON 缺失时：pick 靠 responseText 兜回 body，校验袋仍能取到',
    Api.pick(textOnlyXhr).errors.content[0], '正文不能为空');
eq('body 完全不可解析时：pick 返回 null（不抛），调用方 `|| {}` 即安全',
    Api.pick({ status: 500, responseText: '<html>502 Bad Gateway</html>' }), null);

console.log('== 形态层：旧读法必须清零 ==');
eq('result.version / result.html / result.error 顶层直读清零',
    count(src, /\bresult\.(version|html|error)\b/g), 0);
eq('isOk( 必须 0 处（payload.error 是领域字段，不是失败信号）', count(src, /\bisOk\(/g), 0);

console.log('== 形态层：每个载荷读取点都接了解包层 ==');
// 读了 2 处载荷（preview 的 html/error、save 的 version）；fail 分支读的是错误体，不算载荷
eq('ScaffoldApi.data( 恰好 2 处（preview / save）', count(src, /window\.ScaffoldApi\.data\(/g), 2);
eq('preview 解包：整包交给 payload，再取领域字段', src.includes('var payload = window.ScaffoldApi.data(result) || {};'), true);
eq('preview 取领域字段 error（不是失败信号）', src.includes('frontmatterError.hidden = !payload.error;'), true);
eq('preview 取 html', src.includes("preview.innerHTML = payload.html || '';"), true);
// 与 docs-editor.js 同款兜底，两个预览消费者必须一致：
//   ① `data()` 在 `pick()` 拿不到 body 时**原样返回 `null`** ⇒ 不兜底就是 TypeError；
//   ② `payload.html` 为 undefined 时直进 `innerHTML` 会渲染出字符串 "undefined"。
// 当前服务端契约下走不到（preview 恒回 `ok:true` + 对象载荷），属**防御性对齐**、不是修 bug ——
// 但 e2e 断 `pageerror` 为空，一旦将来可达就是硬失败，所以两侧都钉住。
eq('两个预览消费者都给 data() 结果兜底（本文件 + docs-editor.js）',
    /window\.ScaffoldApi\.data\(\w+\) \|\| \{\};/.test(src)
    && editorSrc.includes('var payload = window.ScaffoldApi.data(res) || {};'), true);
eq('两个预览消费者都兜底 html（否则会渲染出字符串 "undefined"）',
    src.includes("payload.html || ''")
    && editorSrc.includes("$preview.html(payload.html || '');"), true);
eq('save 解包：直接取 data.version（漏这层就带空 version 撞 409）',
    src.includes('version = window.ScaffoldApi.data(result).version;'), true);
eq('ScaffoldApi.errorText( 恰好 1 处（save 的 fail）', count(src, /window\.ScaffoldApi\.errorText\(/g), 1);
eq('ScaffoldApi.pick( 恰好 1 处（save 的 fail 取校验袋）', count(src, /window\.ScaffoldApi\.pick\(/g), 1);
// ⚠ 本文件**不许**直读 `xhr.responseJSON` —— body 一律经 `pick()` 取。
//   2026-09-21 收口：此前这里是全仓最后一个直读点，守卫曾把它钉成「恰好 1 处（框架校验袋例外）」；
//   现在改成 0 处 —— 解包层已是唯一读法，不必再让后人逐个判断那 1 处是否合法。
eq('responseJSON 必须 0 处（body 一律经解包层取，不许直读 jqXHR）', count(src, /responseJSON/g), 0);

console.log('\n' + (failures.length === 0 ? '✅ ALL PASS' : '❌ ' + failures.length + ' FAILED') + '（' + pass + ' passed）');
process.exit(failures.length === 0 ? 0 : 1);
