import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// 本用例要停在登录页,但全局 storageState 带**有效**登录态时 Laravel 会把已登录用户
// 从 /scaffold/login 302 到 /scaffold → 页面里没有 login logo,断言报 element(s) not found
// (不是 not visible,很容易误判成 UI 坏了)。显式用空登录态隔离。
test.use({ storageState: { cookies: [], origins: [] } });

test('login brand logo follows the active theme without layout shift', async ({ page }) => {
    // Host 的 public/vendor/scaffold 是发布副本；验收包分支时让其原 CSS 请求返回本仓构建产物，
    // 等价于 vendor:publish 后的资源形态，也不会触发页面 CSP 的 inline-style 限制。
    await page.route('**/vendor/scaffold/css/index.css*', route => route.fulfill({
        contentType: 'text/css',
        body: readFileSync(resolve('public/css/index.css')),
    }));
    for (const name of ['logo-light.png', 'logo-moon.png']) {
        await page.route(`**/vendor/scaffold/images/${name}`, route => route.fulfill({
            contentType: 'image/png',
            body: readFileSync(resolve(`public/images/${name}`)),
        }));
    }

    await page.goto('/scaffold/login');

    const logo = page.getByRole('img', { name: 'Scaffold', exact: true });
    await expect(logo).toBeVisible();

    await page.evaluate(() => document.documentElement.removeAttribute('data-theme'));
    await expect.poll(() => logo.evaluate(element => getComputedStyle(element).backgroundImage))
        .toContain('logo-light.png');
    const lightBox = await logo.boundingBox();

    await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
    await expect.poll(() => logo.evaluate(element => getComputedStyle(element).backgroundImage))
        .toContain('logo-moon.png');
    const darkBox = await logo.boundingBox();

    expect(lightBox).not.toBeNull();
    expect(darkBox).not.toBeNull();
    expect(lightBox?.width).toBe(292);
    expect(lightBox?.height).toBeCloseTo(40, 0);
    expect(darkBox?.width).toBe(lightBox?.width);
    expect(darkBox?.height).toBe(lightBox?.height);
});
