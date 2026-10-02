'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync, spawnSync } = require('node:child_process');

const script = path.resolve(__dirname, '../Browser/safe-run.sh');
let passed = 0;
function check(actual, expected) { assert.deepEqual(actual, expected); passed++; }
function fixture(run) {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'scaffold-safe-run-'));
    const db = path.join(root, 'engine/scaffold/database');
    const migrations = path.join(root, 'engine/database/migrations');
    const bin = path.join(root, '.test-bin');
    const git = (...args) => execFileSync('git', ['-C', root, ...args], { encoding: 'utf8' });
    const write = (relative, value) => fs.writeFileSync(path.join(root, relative), value);
    for (const directory of [db, path.join(db, '.snapshots'), migrations, bin]) fs.mkdirSync(directory, { recursive: true });
    write('engine/scaffold/database/Demo.yaml', 'tables: {}\n');
    write('engine/database/migrations/original.php', '<?php // original\n');
    git('init', '-q');
    write('.git/info/exclude', '.test-bin/\n');
    git('add', 'engine');
    git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit', '-qm', 'fixture');
    const fake = path.join(bin, 'playwright');
    const execute = (body, environment = {}) => {
        fs.writeFileSync(fake, '#!/usr/bin/env node\n' + body, { mode: 0o755 });
        return spawnSync('bash', [script, '--grep', 'fixture only'], {
            cwd: root,
            encoding: 'utf8',
            env: { ...process.env, PATH: bin + path.delimiter + process.env.PATH,
                E2E_HOST_SCAFFOLD_DB_PATH: db, ...environment },
        });
    };
    try { run({ root, db, migrations, bin, git, write, execute }); }
    finally { fs.rmSync(root, { recursive: true, force: true }); }
}

const mutation = `
const fs = require('node:fs');
fs.writeFileSync('engine/scaffold/database/Demo.yaml', 'changed');
fs.writeFileSync('engine/scaffold/database/新建 模块\\n.yaml', 'new');
fs.writeFileSync('engine/scaffold/database/.snapshots/New.yaml', 'new');
fs.writeFileSync('engine/database/migrations/new file.php', 'new');
fs.writeFileSync('outside-notes.md', 'concurrent work');
`;

fixture(({ root, git, write, execute }) => {
    write('engine/database/migrations/precious.php', 'pre-existing');
    const result = execute(mutation);
    check(result.status, 0);
    check(fs.readFileSync(path.join(root, 'engine/scaffold/database/Demo.yaml'), 'utf8'), 'tables: {}\n');
    check(fs.existsSync(path.join(root, 'engine/scaffold/database/新建 模块\n.yaml')), false);
    check(fs.existsSync(path.join(root, 'engine/scaffold/database/.snapshots/New.yaml')), false);
    check(fs.existsSync(path.join(root, 'engine/database/migrations/new file.php')), false);
    check(fs.readFileSync(path.join(root, 'engine/database/migrations/precious.php'), 'utf8'), 'pre-existing');
    check(fs.readFileSync(path.join(root, 'outside-notes.md'), 'utf8'), 'concurrent work');
    check(git('diff', '--', 'engine'), '');
});

for (const state of ['unstaged', 'staged', 'untracked']) {
    fixture(({ root, git, write, execute }) => {
        const file = state === 'untracked' ? 'New.yaml' : 'Demo.yaml';
        write('engine/scaffold/database/' + file, 'user work');
        if (state === 'staged') git('add', 'engine/scaffold/database/Demo.yaml');
        const before = git('status', '--porcelain=v1', '-z');
        const result = execute("require('node:fs').writeFileSync('launched', 'yes');");
        check(result.status, 1);
        check(fs.existsSync(path.join(root, 'launched')), false);
        check(fs.readFileSync(path.join(root, 'engine/scaffold/database', file), 'utf8'), 'user work');
        check(git('status', '--porcelain=v1', '-z'), before);
    });
}

fixture(({ root, execute }) => {
    const result = execute(mutation + 'process.exitCode = 7;');
    check(result.status, 7);
    check(fs.readFileSync(path.join(root, 'engine/scaffold/database/Demo.yaml'), 'utf8'), 'tables: {}\n');
    check(fs.existsSync(path.join(root, 'engine/database/migrations/new file.php')), false);
});

fixture(({ root, execute }) => {
    const result = execute(mutation + "process.kill(process.pid, 'SIGINT');");
    check(result.status, 130);
    check(fs.readFileSync(path.join(root, 'engine/scaffold/database/Demo.yaml'), 'utf8'), 'tables: {}\n');
});

fixture(({ root, execute }) => {
    const result = execute("require('node:fs').writeFileSync('launched', 'yes');", {
        E2E_HOST_SCAFFOLD_DB_PATH: path.join(root, 'missing'),
    });
    check(result.status, 1);
    check(fs.existsSync(path.join(root, 'launched')), false);
});

fixture(({ root, execute }) => {
    const result = execute("require('node:fs').writeFileSync('arguments.json', JSON.stringify(process.argv.slice(2))); process.exitCode = 7;", {
        E2E_HOST_SCAFFOLD_DB_PATH: '',
    });
    check(result.status, 7);
    check(JSON.parse(fs.readFileSync(path.join(root, 'arguments.json'), 'utf8')), ['test', '--grep', 'fixture only']);
});

// 测试进程变更 HEAD 时，不用新的基线静默覆盖现场。
fixture(({ root, execute }) => {
    const result = execute(mutation + `
require('node:child_process').execFileSync('git', ['-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit', '--allow-empty', '-qm', 'changed head']);
`);
    check(result.status, 1);
    check(fs.readFileSync(path.join(root, 'engine/scaffold/database/Demo.yaml'), 'utf8'), 'changed');
});

fixture(({ root, write, execute }) => {
    write('engine/database/migrations/original.php', 'user migration');
    const result = execute("require('node:fs').writeFileSync('launched', 'yes');");
    check(result.status, 1);
    check(fs.existsSync(path.join(root, 'launched')), false);
    check(fs.readFileSync(path.join(root, 'engine/database/migrations/original.php'), 'utf8'), 'user migration');
});

// 恢复失败不能沿用 Playwright 的成功退出码。
fixture(({ root, bin, execute }) => {
    const realGit = execFileSync('which', ['git'], { encoding: 'utf8' }).trim();
    fs.writeFileSync(path.join(bin, 'git'), `#!/usr/bin/env node
const args = process.argv.slice(2);
if (args[2] === 'restore') { console.error('fixture restore failed'); process.exit(1); }
const result = require('node:child_process').spawnSync(${JSON.stringify(realGit)}, args, { stdio: 'inherit' });
process.exit(result.status ?? 1);
`, { mode: 0o755 });
    const result = execute(mutation);
    check(result.status, 1);
    check(result.stderr.includes('fixture restore failed'), true);
    check(fs.readFileSync(path.join(root, 'engine/scaffold/database/Demo.yaml'), 'utf8'), 'changed');
});

// 测试意外 stage 新文件时，保留供人工检查，并报告未还原。
fixture(({ root, execute }) => {
    const result = execute(mutation + `
require('node:child_process').execFileSync('git', ['add', 'engine/scaffold/database/新建 模块\\n.yaml']);
`);
    check(result.status, 1);
    check(result.stderr.includes('仍有未还原的改动'), true);
    check(fs.existsSync(path.join(root, 'engine/scaffold/database/新建 模块\n.yaml')), true);
});

console.log('E2E 清理范围、脏现场保护、特殊路径与退出码（' + passed + ' passed）');
