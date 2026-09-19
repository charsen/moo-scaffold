/**
 * `npm run test:js` 的入口 —— 跑 `tests/javascript/` 下所有 `*.test.js`。
 *
 * 为什么要一个 runner 而不是在 package.json 里手写文件列表：
 *   列表会**悄悄腐烂** —— 新加一个守卫却忘了加进脚本，它就永远不跑，而 CI 照样全绿。
 *   守卫"没在跑"比"守卫失败"危险得多，所以这里用**自动发现**：只要文件放进目录就一定被执行。
 *
 * 每个文件开**子进程**跑：守卫脚本自己是可执行的（跑完 process.exit），
 * 隔离后单个文件的退出码不会带崩其余文件，且能逐个报出是哪个文件红。
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
for (const f of files) {
    console.log('\n▶ ' + f);
    const r = spawnSync(process.execPath, [join(dir, f)], { stdio: 'inherit' });
    if (r.status !== 0) {
        failed.push(f);
    }
}

console.log('');
if (failed.length === 0) {
    console.log('✅ ' + files.length + ' 个守卫文件全绿');
    process.exit(0);
}
console.log('❌ ' + failed.length + '/' + files.length + ' 个守卫文件有失败：' + failed.join(', '));
process.exit(1);
