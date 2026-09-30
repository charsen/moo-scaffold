'use strict';
const assert = require('assert/strict');
const fs = require('fs');
const vm = require('vm');
const path = require('path');
let init, factory;
const timers = new Map(); let timerId = 0;
const context = {
    document: { addEventListener: (_, fn) => { init = fn; } },
    Alpine: { data: (_, fn) => { factory = fn; } },
    setTimeout: (fn, delay) => { timers.set(++timerId, { fn, delay }); return timerId; },
    clearTimeout: id => timers.delete(id), Date, Promise
};
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../public/javascript/designer.js'), 'utf8'), context);
init();
let passed = 0;
function check(value, expected) { assert.deepEqual(value, expected); passed++; }
const tick = () => new Promise(resolve => setImmediate(resolve));
function runTimer(delay) {
    const entry = [...timers.entries()].find(([, timer]) => timer.delay === delay);
    assert.ok(entry, 'expected timer ' + delay);
    timers.delete(entry[0]); entry[1].fn();
}
function fixture() {
    timers.clear();
    const d = factory(), requests = [], notices = [];
    d.saveEndpoint = '/save'; d.previewEndpoint = '/preview'; d.migrateEndpoint = '/migrate';
    d.edit = 'A'; d._buildSavePayload = () => ({ edit: d.edit });
    d._post = (url, payload) => new Promise((resolve, reject) => requests.push({ url, payload, resolve, reject }));
    d._toast = (message, tone) => notices.push({ message, tone });
    return { d, requests, notices };
}
(async () => {
    let { d, requests } = fixture();
    d._scheduleSave(); runTimer(500);
    d.edit = 'B'; d._scheduleSave(); d.edit = 'C'; d._scheduleSave();
    check(requests.length, 1);
    const barrier = d._ensureSaved();
    requests[0].resolve({}); await tick();
    check(requests.length, 2); check(requests[1].payload.edit, 'C');
    check(d._isDirty, true); check(d.savingState, 'saving');
    requests[1].resolve({}); check(await barrier, true);
    check(d._isDirty, false); check(d.savingState, 'saved');

    ({ d, requests } = fixture()); let previews = 0;
    d._get = async () => { previews++; return { is_empty: true }; };
    d._scheduleSave(); runTimer(500);
    const preview = d.openPreview();
    check(previews, 0); requests[0].resolve({}); await preview; check(previews, 1);

    ({ d, requests } = fixture()); previews = 0;
    d._get = async () => { previews++; return { is_empty: true }; };
    d._scheduleSave(); const retryPreview = d.openPreview();
    requests[0].reject({ status: 500, message: 'temporary' }); await tick();
    check(previews, 0); d.edit = 'B'; d._scheduleSave();
    runTimer(1000); await tick();
    check(requests[1].payload.edit, 'B'); check(previews, 0);
    requests[1].reject({ status: 422, message: 'invalid' }); await retryPreview;
    check(d.savingState, 'error'); check(d._isDirty, true); check(previews, 0);
    check(d._shouldRetrySave({ status: 522 }), false);

    ({ d, requests } = fixture());
    const manual = d.saveNow();
    requests[0].reject({ status: 0 }); await tick(); runTimer(1000); await tick();
    check(requests.length, 2);
    requests[1].resolve({ warnings: ['文件已保存，缓存未刷新'] }); await manual;
    check(d.saveStatusText, '已保存 · 缓存刷新失败'); check(d._isDirty, false);

    ({ d, requests } = fixture());
    d._previewRevision = 0; d._scheduleSave();
    const migrate = d.confirmMigrate();
    check(requests.length, 1); requests[0].resolve({}); await migrate;
    check(requests.length, 1); check(d.migrating, false);
    console.log('designer 保存串行、预览屏障、重试和部分成功反馈（' + passed + ' passed）');
})().catch(error => { console.error(error); process.exitCode = 1; });
