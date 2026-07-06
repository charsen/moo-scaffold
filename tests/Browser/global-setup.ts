import { chromium, type FullConfig } from '@playwright/test';
import { existsSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * 首次 e2e:
 *   - 若 tests/Browser/.auth/admin.json 已存在 → 复用,不做事
 *   - 否则读 env `E2E_USERNAME` + `E2E_PASSWORD` 做一次登录 POST,把 cookie 保存
 *   - env 也没配 → 抛错提示用 `npm run test:e2e:auth` 手动 codegen
 */
async function globalSetup(config: FullConfig) {
    const authPath = resolve('tests/Browser/.auth/admin.json');
    if (existsSync(authPath)) return;

    const baseURL = (config.projects[0]?.use?.baseURL as string) || 'http://localhost';
    const username = process.env.E2E_USERNAME;
    const password = process.env.E2E_PASSWORD;

    if (!username || !password) {
        throw new Error(
            'storage state 未初始化\n' +
            '快速 fix:\n' +
            '  npm run test:e2e:auth     # 弹 codegen 窗口,登录后关闭,state 自动保存\n' +
            '或在 env 设 E2E_USERNAME / E2E_PASSWORD 让 globalSetup 自动登录',
        );
    }

    const browser = await chromium.launch();
    const ctx = await browser.newContext({ baseURL });
    const page = await ctx.newPage();
    await page.goto('/scaffold/login');
    await page.getByLabel(/用户名|username/i).fill(username);
    await page.getByLabel(/密码|password/i).fill(password);
    await page.getByRole('button', { name: /登录|登入|sign in/i }).click();
    await page.waitForURL(/\/scaffold(\/|$)/, { timeout: 8000 });
    await ctx.storageState({ path: authPath });
    await browser.close();
    // eslint-disable-next-line no-console
    console.log(`✓ saved storage state to ${authPath}`);
}

export default globalSetup;
