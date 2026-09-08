import { test, expect } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { existsSync, readFileSync, writeFileSync, unlinkSync, symlinkSync } from 'node:fs';
import { join } from 'node:path';

// 仅创建并清理本次独占的测试文档，不改 Host 既有计划 / 发版记录。
for (const section of [
    { route: 'plans', query: 'doc', directory: process.env.E2E_HOST_PLANS_PATH },
    { route: 'release-records', query: 'record', directory: process.env.E2E_HOST_RELEASE_RECORDS_PATH },
]) {
    test(`${section.route}: raw editor, frontmatter preview, save and conflict`, async ({ page }, testInfo) => {
        test.skip(!section.directory, '需要配置 Host 对应的文档目录以创建隔离测试文件');
        const slug = `e2e-local-markdown-${randomUUID()}.md`;
        const path = join(section.directory!, slug);
        const raw = '\r\n# E2E 原始正文\r\n\r\n<!-- E2E comment -->\r\n\r\n  ';
        writeFileSync(path, raw, { flag: 'wx' });
        const errors: string[] = [];
        page.on('pageerror', error => errors.push(error.message));
        try {
            await page.goto(`/scaffold/${section.route}?${section.query}=${slug}`);
            await page.getByRole('link', { name: '编辑', exact: true }).click();
            const editor = page.getByRole('textbox', { name: 'Markdown 正文' });
            await expect(editor).toHaveValue(raw.replace(/\r\n/g, '\n'));
            await expect(page.locator('#doc_preview h1')).toHaveText('E2E 原始正文');
            await page.getByRole('button', { name: '保存', exact: true }).click();
            await expect(page.getByRole('status')).toHaveText('已保存');
            expect(readFileSync(path, 'utf8')).toBe(raw);

            const updated = '---\ntitle: E2E 元数据标题\ngroup: E2E 分组\norder: 3\ntags: [E2E标签]\ncustom: unchanged\n---\n\n# E2E 修改正文\n\n<!-- E2E comment -->\n\n  ';
            await editor.fill(updated);
            await expect(page.locator('#doc_preview h1')).toHaveText('E2E 修改正文');
            await expect(page.locator('#doc_preview')).not.toContainText('custom: unchanged');
            await expect(page.locator('#doc_preview')).not.toContainText('E2E comment');
            expect(readFileSync(path, 'utf8')).toBe(raw);
            await page.screenshot({ path: testInfo.outputPath('editor.png'), fullPage: true });

            page.once('dialog', dialog => dialog.dismiss());
            await page.getByRole('link', { name: '返回阅读' }).click();
            await expect(editor).toBeVisible();
            await editor.press('ControlOrMeta+s');
            await expect(page.getByRole('status')).toHaveText('已保存');
            expect(readFileSync(path, 'utf8')).toBe(updated.replace(/\n/g, '\r\n'));
            await page.getByRole('link', { name: '返回阅读' }).click();
            await expect(page.locator('.p-docs-reader__title')).toHaveText('E2E 元数据标题');
            await expect(page.locator('.p-docs-reader__crumb')).toContainText('E2E标签');
            await expect(page.locator('#doc_article')).not.toContainText('custom: unchanged');

            await page.getByRole('link', { name: '编辑', exact: true }).click();
            await expect(editor).toHaveValue(updated);
            writeFileSync(path, '# E2E 外部编辑');
            await editor.fill(updated + '\n\nE2E stale change');
            await page.getByRole('button', { name: '保存', exact: true }).click();
            await expect(page.getByRole('status')).toContainText('文件已被修改');
            await expect(editor).toHaveValue(updated + '\n\nE2E stale change');
            expect(readFileSync(path, 'utf8')).toBe('# E2E 外部编辑');
            expect(errors).toEqual([]);
        } finally {
            page.on('dialog', dialog => dialog.accept());
            await page.close();
            if (existsSync(path)) unlinkSync(path);
        }
    });

    test(`${section.route}: frontmatter diagnostics and symlink read-only state`, async ({ page }, testInfo) => {
        test.skip(!section.directory, '需要配置 Host 对应的文档目录');
        const slug = `e2e-local-markdown-${randomUUID()}.md`;
        const alias = `e2e-local-markdown-${randomUUID()}.md`;
        const path = join(section.directory!, slug);
        const aliasPath = join(section.directory!, alias);
        const original = '\uFEFF---\r\n---\r\n# E2E 空头部\r\n';
        writeFileSync(path, original, { flag: 'wx' });
        try {
            symlinkSync(path, aliasPath);
            await page.goto(`/scaffold/${section.route}?${section.query}=${alias}`);
            await expect(page.locator('#doc_article h1')).toContainText('E2E 空头部');
            await expect(page.getByRole('link', { name: '编辑', exact: true })).toHaveCount(0);
            await page.goto(`/scaffold/${section.route}?${section.query}=${slug}`);
            await page.getByRole('link', { name: '编辑', exact: true }).click();
            const editor = page.getByRole('textbox', { name: 'Markdown 正文' });
            await expect(editor).toHaveValue(original.replace(/\r\n/g, '\n'));
            await expect(page.locator('#doc_preview h1')).toHaveText('E2E 空头部');
            await expect(page.locator('#doc_preview hr')).toHaveCount(0);
            await page.getByRole('button', { name: '保存', exact: true }).click();
            await expect(page.getByRole('status')).toHaveText('已保存');
            expect(readFileSync(path, 'utf8')).toBe(original);

            await editor.fill('---\ntitle: [\n---\n# E2E 错误头部');
            await expect(page.locator('#doc_frontmatter_error')).toContainText('第 3 行');
            await expect(page.locator('#doc_preview h1')).toHaveText('E2E 错误头部');
            await page.screenshot({ path: testInfo.outputPath('frontmatter-error.png'), fullPage: true });
            await page.getByRole('button', { name: '保存', exact: true }).click();
            await expect(page.getByRole('status')).toContainText('YAML 语法错误');
            expect(readFileSync(path, 'utf8')).toBe(original);
            await editor.fill('---\norder: true\n---\n# E2E 错误类型');
            await expect(page.locator('#doc_frontmatter_error')).toContainText('order 必须是整数');
            await editor.fill('---\norder: 0\ntitle: E2E 修正标题\n---\n# E2E 已修正');
            await expect(page.locator('#doc_frontmatter_error')).toBeHidden();
            await expect(page.locator('#doc_preview h1')).toHaveText('E2E 已修正');
            await page.getByRole('button', { name: '保存', exact: true }).click();
            await expect(page.getByRole('status')).toHaveText('已保存');
        } finally {
            page.on('dialog', dialog => dialog.accept());
            await page.close();
            if (existsSync(aliasPath)) unlinkSync(aliasPath);
            if (existsSync(path)) unlinkSync(path);
        }
    });

    test(`${section.route}: network failure, lost reply and timeout recovery`, async ({ page, context }) => {
        test.setTimeout(45000);
        test.skip(!section.directory, '需要配置 Host 对应的文档目录');
        const slug = `e2e-local-markdown-${randomUUID()}.md`;
        const path = join(section.directory!, slug);
        writeFileSync(path, '# E2E Initial', { flag: 'wx' });
        const saveRoute = `**/scaffold/${section.route}/save`;
        try {
            await page.goto(`/scaffold/${section.route}/edit?slug=${slug}`);
            const editor = page.getByRole('textbox', { name: 'Markdown 正文' });
            const save = page.getByRole('button', { name: '保存', exact: true });
            await expect(editor).toHaveValue('# E2E Initial');
            await expect(page.locator('#doc_preview h1')).toHaveText('E2E Initial');

            await context.setOffline(true);
            await editor.fill('# E2E Offline change');
            await save.click();
            await expect(page.getByRole('status')).toContainText('保存失败');
            await expect(save).toBeEnabled();
            await expect(editor).toHaveValue('# E2E Offline change');
            expect(readFileSync(path, 'utf8')).toBe('# E2E Initial');
            await context.setOffline(false);
            await save.click();
            await expect(page.getByRole('status')).toHaveText('已保存');

            await page.route(saveRoute, async route => {
                const response = await route.fetch();
                expect(response.status()).toBe(200);
                await route.abort('failed'); // 已落盘，但浏览器没有收到成功回执。
            });
            await editor.fill('# E2E Reply lost');
            await save.click();
            await expect(page.getByRole('status')).toContainText('保存失败');
            expect(readFileSync(path, 'utf8')).toBe('# E2E Reply lost');
            await page.unroute(saveRoute);
            await save.click();
            await expect(page.getByRole('status')).toHaveText('已保存');

            let stalled: import('@playwright/test').Route | undefined;
            await page.route(saveRoute, route => { stalled = route; });
            await editor.fill('# E2E Timeout change');
            await save.click();
            await expect(page.getByRole('status')).toContainText('保存超时', { timeout: 20000 });
            await expect(save).toBeEnabled();
            await expect(editor).toHaveValue('# E2E Timeout change');
            expect(readFileSync(path, 'utf8')).toBe('# E2E Reply lost');
            if (stalled) await stalled.abort().catch(() => {});
            await page.unroute(saveRoute);
            await save.click();
            await expect(page.getByRole('status')).toHaveText('已保存');
            expect(readFileSync(path, 'utf8')).toBe('# E2E Timeout change');
        } finally {
            await context.setOffline(false);
            page.on('dialog', dialog => dialog.accept());
            await page.close();
            if (existsSync(path)) unlinkSync(path);
        }
    });
}
