/**
 * `npm run test:js` 的入口 —— 跑 `tests/javascript/` 下所有 `*.test.js`。
 *
 * 为什么要一个 runner 而不是在 package.json 里手写文件列表：
 *   列表会**悄悄腐烂** —— 新加一个守卫却忘了加进脚本，它就永远不跑，而 CI 照样全绿。
 *   守卫"没在跑"比"守卫失败"危险得多，所以这里用**自动发现**：只要文件放进目录就一定被执行。
 *
 * 每个文件开**子进程**跑：守卫脚本自己是可执行的（跑完 process.exit），
 * 隔离后单个文件的退出码不会带崩其余文件，且能逐个报出是哪个文件红。
 *
 * ⚠ **必须打印断言总数**（2026-09-21 补）：本 runner 原先把每个文件的原样输出转发到 stdout，
 *   于是屏幕上**最后一行**的「（N passed）」是**最后一个文件自己的**计数（按文件名排序，
 *   恒为 `scaffold-api.test.js`）—— 看起来像总数，实际不是。这个坑已经让人把总数报错过
 *   至少两次（`NOTES.md` 与工作日志里各留过一条「3 个守卫文件 62 断言」的错记录，
 *   而 62 只是 scaffold-api 自己的数）。
 *   ⇒ 现在把子进程输出收回来、逐个解析「（N passed）」再求和，报出**唯一的总数**；
 *   某个文件的计数解析不出来时**显式告警**（格式变了 = runner 不再读得到它，属静默失效）。
 *   代价：输出不再实时流式（改为跑完一个转发一个）—— 这些文件都是秒级，可接受。
 */
'use strict';

const { readdirSync } = require('fs');
const { spawnSync } = require('child_process');
const { join } = require('path');

const dir = __dirname;
const files = readdirSync(dir)
    .filter((f) => f.endsWith('.test.js'))
    .sort();

if (files.length === 0) {
    console.error('✗ tests/javascript/ 下没有 *.test.js —— 一个都没跑，比失败更可疑');
    process.exit(1);
}

const failed = [];
const unparsed = [];
let totalPassed = 0;

for (const f of files) {
    console.log('\n▶ ' + f);
    const r = spawnSync(process.execPath, [join(dir, f)], { encoding: 'utf8' });
    const out = (r.stdout || '') + (r.stderr || '');
    process.stdout.write(out);

    const m = out.match(/（(\d+) passed）/);
    if (m) {
        totalPassed += Number(m[1]);
    } else {
        unparsed.push(f);
    }
    if (r.status !== 0) {
        failed.push(f);
    }
}

console.log('');
if (failed.length === 0) {
    console.log('✅ ' + files.length + ' 个守卫文件全绿（共 ' + totalPassed + ' 断言）');
    if (unparsed.length > 0) {
        console.log('⚠ ' + unparsed.length + ' 个文件的断言数没解析出来（输出格式变了？总数因此偏小）：'
            + unparsed.join(', '));
    }
    process.exit(0);
}
console.log('❌ ' + failed.length + '/' + files.length + ' 个守卫文件有失败：' + failed.join(', '));
process.exit(1);
