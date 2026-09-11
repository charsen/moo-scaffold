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

    // ⚠ 这里**不能**用 `page.waitForURL(/\/scaffold(\/|$)/)`：那个正则会被 `/scaffold/login`
    // 自身命中（它也是 `/scaffold` + `/`），于是 waitForURL 立刻返回 —— 登录 POST 还没回来就
    // storageState()，存出一份**没有 scaffold_auth** 的空会话。症状是整轮 spec 都以「未登录」
    // 形态失败（等不到任何菜单项），看起来像功能坏了。改成等"离开登录页"。
    try {
        await page.waitForFunction(
            () => !location.pathname.startsWith('/scaffold/login'),
            undefined,
            { timeout: 20000 },
        );
    } catch {
        throw new Error(
            '自动登录失败：仍停留在 /scaffold/login。多半是 E2E_USERNAME / E2E_PASSWORD 不对，'
            + '或宿主压根没有可用的 scaffold 账号（`scaffold/accounts.yaml` 不存在时谁都登不进去，'
            + '用宿主的 `php artisan moo:account:add <user> --password=... --role=admin` 造第一个）。'
            + '也可以改用 `npm run test:e2e:auth` 手登一次。',
        );
    }

    // 再确认真的拿到了登录 cookie —— "URL 变了但没会话"这种半成功同样会污染整套 spec，
    // 而写一份空 state 进去比直接报错更难排查，所以这里宁可 fail fast。
    const cookieName = process.env.E2E_AUTH_COOKIE || 'scaffold_auth';
    const cookies = await ctx.cookies();
    if (!cookies.some((c) => c.name === cookieName)) {
        throw new Error(
            `自动登录后没拿到 ${cookieName} cookie，不写入 storage state`
            + '（写进去只会让整套 spec 以「未登录」形态假失败）。'
            + '请核对账号密码，或改用 `npm run test:e2e:auth` 手登一次。',
        );
    }

    await ctx.storageState({ path: authPath });
    await browser.close();
    // eslint-disable-next-line no-console
    console.log(`✓ saved storage state to ${authPath}`);
}

export default globalSetup;
