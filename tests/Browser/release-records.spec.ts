import { test, expect } from '@playwright/test';

// 只读验收：使用 Host 现有记录，不创建或修改 scaffold / release-records 文件。
test('release log menu, record navigation and title filter', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('/scaffold/docs');
    await page.getByRole('link', { name: '发版日志', exact: true }).click();
    await expect(page.locator('.header__menu-item.is-active')).toHaveText('发版日志');
    const records = page.locator('.side-tree__item-link');
    expect(await records.count(), 'Host needs at least two release records for navigation acceptance').toBeGreaterThan(1);
    const newest = await records.first().innerText();
    await expect(page.locator('#doc_article h1').first()).toContainText(newest);
    const older = records.last();
    const title = await older.innerText();
    await older.click();
    await expect(page.locator('#doc_article h1').first()).toContainText(title);
    await page.getByRole('searchbox', { name: '过滤' }).fill(newest);
    await expect(page.locator('.side-tree__item-link:visible')).toHaveCount(1);
    await page.locator('.side-tree__item-link:visible').click();
    await expect(page.locator('#doc_article h1').first()).toContainText(newest);
    await expect(page.getByRole('link', { name: '编辑', exact: true })).toHaveCount(0);
    expect(errors).toEqual([]);
});
