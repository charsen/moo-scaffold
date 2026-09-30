import { test, expect, type Page } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { readFileSync, writeFileSync, unlinkSync, existsSync, readdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const dbDir = process.env.E2E_HOST_SCAFFOLD_DB_PATH;

// 独占 schema / snapshot；仅生成迁移文件，不执行数据库迁移。
test.describe('设计器操作可靠性', () => {
    let schema: string, tableA: string, tableB: string, source: string, snapshot: string;
    test.beforeEach(() => {
        test.skip(!dbDir, '需要 Host schema 路径创建隔离夹具');
        const suffix = randomUUID().replaceAll('-', '').slice(0, 12);
        schema = `E2eWorkflow${suffix}`;
        tableA = `e2e_wf_a_${suffix}`;
        tableB = `e2e_wf_b_${suffix}`;
        source = join(dbDir!, `${schema}.yaml`);
        snapshot = join(dbDir!, '.snapshots', `${schema}.yaml`);
        const table = (key: string) => `    ${key}:\n        attrs: { name: E2E测试 }\n        fields:\n            id: {}\n            label: { name: 标签, type: varchar, size: 64 }\n`;
        const yaml = `module: { name: E2E测试, folder: ${schema} }\ntables:\n${table(tableA)}${table(tableB)}`;
        writeFileSync(source, yaml, { flag: 'wx' });
        writeFileSync(snapshot, yaml, { flag: 'wx' });
    });
    test.afterEach(async ({ page }) => {
        page.on('dialog', dialog => dialog.accept());
        await page.close();
        for (const file of [source, snapshot]) if (file && existsSync(file)) unlinkSync(file);
        if (!dbDir || !tableA) return;
        const migrations = resolve(dbDir, '../../database/migrations');
        for (const file of readdirSync(migrations)) {
            if (file.includes(tableA) || file.includes(tableB)) unlinkSync(join(migrations, file));
        }
    });
    async function open(page: Page) {
        await page.goto(`/scaffold/db/designer/${schema}?table=${tableA}`);
        await expect(page.locator('input[name="field_size"]').nth(1)).toHaveValue('64');
    }

    test('在途保存合并最新编辑，预览等待完成，过期预览拒绝生成', async ({ page }) => {
        await open(page);
        let release!: () => void;
        const held = new Promise<void>(resolve => { release = resolve; });
        let saves = 0, previews = 0, migrations = 0;
        page.on('request', request => {
            if (request.url().endsWith('/preview')) previews++;
            if (request.url().endsWith('/migrate')) migrations++;
        });
        await page.route(`**/designer/${schema}/save`, async route => {
            saves++;
            if (saves === 1) await held;
            await route.continue();
        });
        const size = page.locator('input[name="field_size"]').nth(1);
        try {
            await size.fill('80');
            await expect.poll(() => saves).toBe(1);
            await size.fill('96');
            await page.getByRole('button', { name: '生成 migration', exact: true }).click();
            expect(previews).toBe(0);
            expect(saves).toBe(1);
            release();
            const preview = page.getByRole('dialog', { name: '生成新 migration · 预览' });
            await expect(preview).toBeVisible();
            expect(saves).toBe(2);
            expect(readFileSync(source, 'utf8')).toMatch(/size: 96/);
            await size.fill('112');
            await preview.getByRole('button', { name: '确认生成 migration', exact: true }).click();
            await expect(page.locator('[class*="toast"]').filter({ hasText: '重新预览后生成迁移' }).first()).toBeVisible();
            expect(migrations).toBe(0);
        } finally { release(); }
    });

    test('删除 A 只生成 A 的迁移，保留 B 的待处理变更及快照', async ({ page }) => {
        const raw = readFileSync(source, 'utf8');
        writeFileSync(source, raw.replace(/size: 64(?= }\n$)/, 'size: 128'));
        await open(page);
        await page.getByRole('button', { name: '删表', exact: true }).click();
        const dialog = page.getByRole('dialog').filter({ hasText: '删除表' }).first();
        await dialog.locator('#del-confirm').fill(tableA);
        const response = page.waitForResponse(r => r.request().method() === 'DELETE' && r.url().includes(tableA));
        await dialog.locator('.btn--danger').click();
        const result = await response;
        expect(result.status()).toBe(200);
        const data = (await result.json()).data;
        expect(data.migration_files).toHaveLength(1);
        const dir = resolve(dbDir!, '../../database/migrations');
        const files = readdirSync(dir).filter(file => file.includes(tableA));
        expect(files).toHaveLength(1);
        const migration = readFileSync(join(dir, files[0]), 'utf8');
        expect(migration).toContain(`dropIfExists('${tableA}')`);
        expect(migration).not.toContain(tableB);
        expect(readFileSync(source, 'utf8').split(`${tableB}:`)[1]).toMatch(/size: 128/);
        const baseline = readFileSync(snapshot, 'utf8');
        expect(baseline).not.toContain(`${tableA}:`);
        expect(baseline.split(`${tableB}:`)[1]).toMatch(/size: 64/);
    });

    test('缓存警告显示部分成功，522 保存拒绝不会重试或打开预览', async ({ page }) => {
        await open(page);
        const size = page.locator('input[name="field_size"]').nth(1);
        await page.route(`**/designer/${schema}/save`, async route => {
            // 后端仍真实保存；只注入刷新失败回执以验证浏览器反馈。
            const response = await route.fetch();
            expect(response.status()).toBe(200);
            const body = await response.json();
            body.data.warnings = ['文件已保存，但 Schema 缓存刷新失败。请运行 php artisan moo:fresh。'];
            await route.fulfill({ response, json: body });
        });
        await size.fill('80');
        await expect(page.locator('.p-designer-header__save-status')).toHaveText('已保存 · 缓存刷新失败');
        expect(readFileSync(source, 'utf8')).toMatch(/size: 80/);
        await page.unroute(`**/designer/${schema}/save`);
        let saves = 0, previews = 0;
        page.on('request', request => { if (request.url().endsWith('/preview')) previews++; });
        await page.route(`**/designer/${schema}/save`, route => {
            saves++;
            return route.fulfill({ status: 522, json: { ok: false, error: { code: 'FIXTURE_REJECTED', msg: 'E2E 业务拒绝' } } });
        });
        await size.fill('96');
        await page.getByRole('button', { name: '生成 migration', exact: true }).click();
        await expect(page.locator('.p-designer-header__save-status')).toContainText('E2E 业务拒绝');
        await page.waitForTimeout(1200); // 覆盖旧实现一秒后技术重试的时间窗。
        expect(saves).toBe(1);
        expect(previews).toBe(0);
        expect(readFileSync(source, 'utf8')).toMatch(/size: 80/);
    });
});

test('隔离账号新增、编辑、停用立即撤销旧会话并可删除；日志菜单新窗口', async ({ page, browser }) => {
    test.skip(process.env.E2E_ISOLATED_ACCOUNTS !== '1', '仅允许独立 E2E 账号文件的 Host 执行');
    const username = `e2e-member-${randomUUID().slice(0, 8)}`;
    await page.goto('/scaffold/accounts');
    await page.getByRole('button', { name: '新增开发人员' }).click();
    await page.locator('#acc-form-username').fill(username);
    await page.locator('#acc-form-password').fill('E2eDisposableOnly20260930');
    await page.locator('#acc-form-role').selectOption('member');
    await page.getByRole('button', { name: '保存', exact: true }).click();
    await expect(page.locator('.p-acc-flash--ok')).toContainText('新增账号');
    const member = await browser.newContext({
        baseURL: process.env.E2E_BASE_URL,
        storageState: { cookies: [], origins: [] },
    });
    try {
        const login = await member.newPage();
        await login.goto('/scaffold/login');
        await login.getByLabel(/用户名/).fill(username);
        await login.getByLabel(/密码/).fill('E2eDisposableOnly20260930');
        await login.getByRole('button', { name: /登录/ }).click();
        await expect(login).not.toHaveURL(/\/login/);
        expect((await member.request.get('/scaffold/logs/api/files')).status()).toBe(200);
        await page.getByRole('button', { name: `编辑账号 ${username}`, exact: true }).click();
        await page.locator('#acc-form-phone').fill('123456');
        await page.getByRole('button', { name: '保存', exact: true }).click();
        await expect(page.locator('.p-acc-flash--ok')).toContainText('更新');
        await page.getByRole('button', { name: `停用账号 ${username}`, exact: true }).click();
        await expect(page.locator('.p-acc-flash--ok')).toContainText('已切换');
        const denied = await member.request.get('/scaffold/logs/api/files');
        expect(denied.status()).toBe(401);
        expect(denied.headers()['x-scaffold-auth']).toBe('required');
        await login.goto('/scaffold/logs');
        await expect(login).toHaveURL(/\/scaffold\/login/);
        const link = page.getByRole('link', { name: '应用日志', exact: true });
        await expect(link).toHaveAttribute('target', '_blank');
        const popup = page.waitForEvent('popup');
        await link.click();
        const logs = await popup;
        await expect(logs).toHaveURL(/\/scaffold\/logs/);
        await logs.close();
    } finally {
        await member.close();
        await page.goto('/scaffold/accounts');
        await page.getByRole('button', { name: `删除账号 ${username}`, exact: true }).click();
        await page.getByRole('button', { name: '确认删除', exact: true }).click();
        await expect(page.locator('.p-acc-flash--ok')).toContainText('已删除');
    }
});
