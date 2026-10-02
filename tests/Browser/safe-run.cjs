'use strict';
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync, spawnSync } = require('node:child_process');

function inside(directory, target) {
    const relative = path.relative(directory, target);
    return relative === '' || (relative !== '..' && !relative.startsWith('..' + path.sep) && !path.isAbsolute(relative));
}

function hostCleanup(directory) {
    const db = fs.realpathSync(directory);
    if (!fs.statSync(db).isDirectory()) throw new Error('E2E_HOST_SCAFFOLD_DB_PATH 不是目录。');
    const root = fs.realpathSync(execFileSync('git', ['-C', db, 'rev-parse', '--show-toplevel'], { encoding: 'utf8' }).trim());
    const migrations = fs.realpathSync(path.resolve(db, '../../database/migrations'));
    for (const scope of [db, migrations]) {
        if (scope === root || !inside(root, scope)) throw new Error('schema/migrations 目录必须位于 Host Git 仓内。');
    }
    const scopes = [db, migrations].map(scope => path.relative(root, scope));
    const git = (...args) => execFileSync('git', ['-C', root, ...args], { encoding: 'utf8' });
    const status = () => {
        const fields = git('status', '--porcelain=v1', '-z', '--untracked-files=all', '--', ...scopes).split('\0');
        const entries = [];
        for (let i = 0; i < fields.length; i++) {
            if (!fields[i]) continue;
            const code = fields[i].slice(0, 2);
            entries.push({ code, file: fields[i].slice(3) });
            if (/[RC]/.test(code)) i++; // -z 的 rename/copy 另带一个原路径。
        }
        return entries;
    };
    const baseline = status();
    if (baseline.some(entry => entry.code !== '??' || inside(db, path.join(root, entry.file)))) {
        throw new Error('schema/快照有未提交文件，或 migrations 有已跟踪改动；拒绝启动，请使用独占且干净的 Host。');
    }
    const originalHead = git('rev-parse', 'HEAD');
    const tracked = git('ls-files', '-z', '--', ...scopes).split('\0').filter(Boolean);
    const existing = new Set(baseline.map(entry => entry.file));
    console.log('[e2e:safe] 仅还原 schema/快照与 migrations；保留已有未跟踪 migration 和范围外文件。');

    return () => {
        if (git('rev-parse', 'HEAD') !== originalHead) throw new Error('Host HEAD 已变化，拒绝自动清理，请人工核对现场。');
        if (tracked.length) git('restore', '--source=HEAD', '--worktree', '--', ...tracked);
        let removed = 0;
        for (const entry of status()) {
            if (entry.code !== '??' || existing.has(entry.file)) continue;
            const target = path.join(root, entry.file);
            // 只 unlink 文件/链接；父目录被改成范围外软链时拒绝，不递归删除目录。
            const parent = fs.realpathSync(path.dirname(target));
            if (![db, migrations].some(scope => inside(scope, parent))) throw new Error('清理路径越界：' + entry.file);
            fs.unlinkSync(target);
            removed++;
        }
        if (JSON.stringify(status()) !== JSON.stringify(baseline)) throw new Error('Host 仍有未还原的改动，请人工核对。');
        console.log('[e2e:safe] 还原检查通过，清理新增产物 ' + removed + ' 项。');
    };
}

let cleanup;
let exitCode = 1;
try {
    const directory = process.env.E2E_HOST_SCAFFOLD_DB_PATH;
    if (directory) cleanup = hostCleanup(directory);
    // 同组中断也让子进程结束后执行清理；不要由 Node 默认处理提前退出。
    process.on('SIGINT', () => {});
    process.on('SIGTERM', () => {});
    const result = spawnSync('playwright', ['test', ...process.argv.slice(2)], { stdio: 'inherit' });
    if (result.error) throw result.error;
    exitCode = result.status ?? (result.signal === 'SIGINT' ? 130 : 1);
} catch (error) {
    console.error('[e2e:safe] ' + error.message);
} finally {
    if (cleanup) {
        try { cleanup(); }
        catch (error) {
            console.error('[e2e:safe] 清理失败：' + error.message);
            exitCode = 1;
        }
    }
}
process.exitCode = exitCode;
