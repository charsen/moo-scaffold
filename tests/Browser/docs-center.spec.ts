import { test, expect } from '@playwright/test';

/**
 * `/scaffold/docs/edit` + `/scaffold/docs`（文档中心）的实时行为守卫。
 *
 * 为什么单独为它写：文档中心原先**零 e2e 覆盖**，而它统一 JSON 信封化之后的失效方式**全是静默的** ——
 * 漏解包不会抛错，只会让「实时预览整块空白」或「搜索永远显示没有匹配」，PHP 侧一条都测不到。
 * 配套的形态守卫在 `tests/javascript/docs-unwrap.test.js`（扫描源码接线），本文件守**运行时**。
 *
 * 全程**只读**：
 *   - 编辑器用例停在「新建态」（路径框留空）—— docs-editor 的 autosave 会直接 `return`，
 *     不会落盘任何文件；
 *   - 搜索用例只发 GET /docs/search。
 *
 * 宿主处于生产/强制只读（编辑器整页锁定、不绑交互）时自动跳过。
 */
test.describe('Docs 文档中心', () => {
    test('编辑器实时预览：经解包层渲染 markdown（新建态，零写入）', async ({ page }) => {
        const pageErrors: string[] = [];
        page.on('pageerror', (e) => pageErrors.push(String(e)));

        await page.goto('/scaffold/docs/edit');
        expect(page.url(), '没进到编辑器（多半是登录态失效）').not.toContain('/scaffold/login');

        test.skip(
            await page.evaluate(() => (window as any).ScaffoldDocsEditor?.locked === true),
            '宿主为生产/强制只读，编辑器不接交互',
        );

        const content = page.locator('#doc_content');
        await expect(content).toBeVisible();
        // 新建态：路径框留空 ⇒ 自动保存不发请求（状态显示「填路径后自动保存」），零写入
        await content.fill('# 探针标题\n\n正文 **加粗**。\n');

        // 预览防抖 350ms + 一次往返。漏解包（照旧读顶层 html）时这里会一直空白 ⇒ 超时失败。
        await expect(page.locator('#doc_preview h1')).toHaveText('探针标题', { timeout: 20000 });
        await expect(page.locator('#doc_preview')).toContainText('加粗');

        expect(pageErrors, '页面不该有 JS 异常').toEqual([]);
    });

    test('目录主页全文搜索：DOM 命中数与接口返回一致', async ({ page }) => {
        await page.goto('/scaffold/docs');
        expect(page.url(), '没进到目录主页（多半是登录态失效）').not.toContain('/scaffold/login');

        const locked = await page.evaluate(() => (window as any).ScaffoldDocsHome?.locked === true);
        test.skip(locked, '宿主为生产/强制只读，拖拽/搜索不绑交互');

        // 从现有文档里取一个关键词（不写死任何宿主专有词），先问接口「真有几个命中」。
        // 有命中的词才有鉴别力：漏解包时 DOM 会渲染 0 条，而接口说 >0 —— 两者一比就露馅。
        const slug = await page.locator('.p-docs-home__row').first().getAttribute('data-slug');
        const keyword = (slug || '').split('/').pop()?.slice(0, 2) ?? '';
        test.skip(keyword.length < 2, '宿主没有可用于搜索的文档');

        const apiCount = await page.evaluate(async (q) => {
            const r = await fetch(`/scaffold/docs/search?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } });
            const body = await r.json();
            return (body?.data?.results ?? []).length;
        }, keyword);
        test.skip(apiCount === 0, `接口对「${keyword}」也没有命中，本用例无鉴别力`);

        await page.fill('#docs_home_search', keyword);

        // 防抖 250ms + 一次往返
        await expect(page.locator('#docs_home_results')).toBeVisible({ timeout: 20000 });
        await expect(page.locator('#docs_home_results .p-docs-home__result')).toHaveCount(apiCount);
    });
});
