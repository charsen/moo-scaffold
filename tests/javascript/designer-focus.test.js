'use strict';
const assert = require('assert/strict');
const fs = require('fs');
const vm = require('vm');
const path = require('path');

let init, factory, pendingFocus;
const trigger = {};
const nameInput = {};
const document = {
    activeElement: trigger,
    addEventListener: (_, fn) => { init = fn; },
    getElementById: id => id === 'newschema-key' ? keyInput : null,
};
const keyInput = { focus: () => { document.activeElement = keyInput; } };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../public/javascript/designer.js'), 'utf8'), {
    document,
    Alpine: { data: (_, fn) => { factory = fn; } },
    setTimeout: fn => { pendingFocus = fn; },
});
init();
const designer = factory();
let passed = 0;
function check(actual, expected) { assert.equal(actual, expected); passed++; }

// 没有主动交互时，弹窗仍自动聚焦第一个输入框。
designer.openNewSchema();
check(document.activeElement, trigger);
pendingFocus();
check(document.activeElement, keyInput);

// 复现 E2E：60ms 内填完 key 并移到显示名，定时器不得抢回焦点。
document.activeElement = trigger;
designer.openNewSchema();
document.activeElement = keyInput;
designer.setNewSchemaKey({ target: { value: 'Example' } });
document.activeElement = nameInput;
pendingFocus();
check(document.activeElement, nameInput);
designer.setNewSchemaName({ target: { value: '显示名称' } });
check(designer.newSchemaKey, 'Example');
check(designer.newSchemaName, '显示名称');

// 元素已经移除时，延迟回调可以直接结束。
document.activeElement = trigger;
designer._focusEl('missing-input');
pendingFocus();
check(document.activeElement, trigger);

console.log('designer 弹窗自动聚焦与主动输入竞争（' + passed + ' passed）');
