import { test, expect, type Page } from '@playwright/test';

/**
 * 接口调试器(/scaffold/api/request)tab 编排回归 e2e —— 锁发送中切 tab 的最近记录串台 bug:
 *
 *   BUG 1  发送中切 tab,最近记录(localStorage history)记成「切过去那个」接口。
 *          根因:recordHistory 跑在响应回调里却实时读 live 侧栏(.is-active 已被切走),
 *          修法:点击时把 f/c/a/host/uri 快照进闭包,recordHistory 只用快照。
 *
 * 纯前端 tab 编排,不依赖真实后端响应内容 —— BUG 1 的发送走 page.route 拦截 /api/proxy
 * 返回假 200 + 人为延迟,稳定腾出「响应未回时切 tab」的窗口(否则真后端响应太快、窗口不可控)。
 *
 * 跟 designer.spec 一样要求 dev 宿主可达(/api/param 真加载参数表)+ 已录登录态(admin.json)。
 * fixture:默认进首页第一个 app;E2E_API_APP 可指定 app key。需该 app 至少 2 个接口,否则 skip。
 *
 * 本地怎么起宿主 / 录登录态 / 跑(含 vendor:publish 同步坑)见 ./README.md。
 */

const API_APP = process.env.E2E_API_APP || '';

type Meta = { f: string; c: string; a: string; m: string; url: string; apiName: string; module: string };

// 进调试器页:带不带 app 都行,落到 app picker 就点第一个 app 卡片进去,等接口树渲出来。
// 返回 currentApp(localStorage history key 要用)。
async function openApiDebugger(page: Page): Promise<string> {
    const appUrl = API_APP ? `/scaffold/api/request?app=${encodeURIComponent(API_APP)}` : '/scaffold/api/request';
    await page.goto(appUrl);
    const picker = page.locator('.p-api-picker');
    if (await picker.isVisible().catch(() => false)) {
        await page.locator('.p-api-picker a').first().click();
        await page.waitForURL(/\/api\/request\?app=/, { timeout: 8000 });
    }
    // collapsedByDefault 只是 CSS 折叠,leaf link 都在 DOM 里
    await page.waitForFunction(
        () => document.querySelectorAll('#aside_container .side-tree__item-link[data-a]').length > 0,
        undefined, { timeout: 8000 },
    );
    return await page.evaluate(() => (window as unknown as { ScaffoldRequestIndex?: { currentApp?: string } }).ScaffoldRequestIndex?.currentApp || '');
}

// 从侧栏取前 2 个 f/c/a 互不相同的接口(side-tree 渲 data-f/c/a/m/url/api-name/module)
async function discoverTwoEndpoints(page: Page): Promise<Meta[]> {
    return await page.evaluate(() => {
        const links = Array.from(document.querySelectorAll('#aside_container .side-tree__item-link[data-a]'));
        const seen = new Set<string>();
        const out: Meta[] = [];
        for (const el of links) {
            const f = el.getAttribute('data-f') || '';
            const c = el.getAttribute('data-c') || '';
            const a = el.getAttribute('data-a') || '';
            if (!f || !c || !a) continue;
            const key = f + '::' + c + '::' + a;
            if (seen.has(key)) continue;
            seen.add(key);
            out.push({
                f, c, a,
                m: el.getAttribute('data-m') || '',
                url: el.getAttribute('data-url') || '',
                apiName: el.getAttribute('data-api-name') || (el.textContent || '').trim(),
                module: el.getAttribute('data-module') || '',
            });
            if (out.length >= 2) break;
        }
        return out as Meta[];
    });
}

// 走 ScaffoldDebugTabs.openOrSwitch 开一个 tab(skipAutoSend:GET 接口默认会 auto-send,tab 编排测试不需要真发)
async function openTab(page: Page, meta: Meta): Promise<void> {
    const paramResp = page.waitForResponse(
        r => r.url().includes('/api/param') && r.request().method() === 'GET',
        { timeout: 8000 },
    );
    await page.evaluate((m) => {
        (window as unknown as { ScaffoldDebugTabs: { openOrSwitch: (meta: unknown, opts: unknown) => void } })
            .ScaffoldDebugTabs.openOrSwitch(
                { f: m.f, c: m.c, a: m.a, m: m.m, url: m.url, module: m.module, apiName: m.apiName },
                { skipAutoSend: true },
            );
    }, meta);
    await paramResp;
    await expect(page.locator('#send')).toBeVisible({ timeout: 5000 });
}

// 点 tabs bar 第 idx 个 tab 的 label(避开 close ×)→ 触发 switch
async function switchToTabByIndex(page: Page, idx: number): Promise<void> {
    await page.locator('.api-debug-tabs__item').nth(idx).locator('.api-debug-tabs__label').click();
}

test.describe('接口调试器 tab 编排回归', () => {
    test('BUG 1:发送中切 tab,最近记录记的是发送时的接口(不是切过去那个)', async ({ page }) => {
        const app = await openApiDebugger(page);
        const eps = await discoverTwoEndpoints(page);
        test.skip(eps.length < 2, '当前 app 接口少于 2 个,无法测发送中切 tab');
        const [A, B] = eps;

        await openTab(page, A);
        await openTab(page, B);
        await switchToTabByIndex(page, 0);   // 回 A,准备在 A 上发送

        // 清空该 app 的最近记录,保证 history[0] 就是这一次
        await page.evaluate((k) => localStorage.removeItem('scaffold.apiHistory.' + (k || 'default')), app);

        // 拦截 /api/proxy:延迟 1.5s 再返回假 200,腾出「响应未回时切到 B」的窗口
        await page.route('**/api/proxy', async (route) => {
            await new Promise((res) => setTimeout(res, 1500));
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ _proxy_status: 200, _proxy_headers: {}, data: { ok: true } }),
            });
        });

        const proxyResp = page.waitForResponse(
            r => r.url().includes('/api/proxy') && r.request().method() === 'POST',
            { timeout: 8000 },
        );
        await page.locator('#send').click();
        await switchToTabByIndex(page, 1);   // 响应还没回(延迟 1.5s)→ 立刻切到 B
        await proxyResp;
        await page.unroute('**/api/proxy');

        // 最近记录最新一条的接口标识应是 A(发送时快照),不是 B(切过去那个)
        await expect.poll(async () => page.evaluate((k) => {
            const list = JSON.parse(localStorage.getItem('scaffold.apiHistory.' + (k || 'default')) || '[]');
            const e = list[0];
            return e ? { f: e.folder, c: e.controller, a: e.action } : null;
        }, app), { timeout: 5000 }).toEqual({ f: A.f, c: A.c, a: A.a });
    });
});
