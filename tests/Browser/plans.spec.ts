import { test, expect } from '@playwright/test';

// 只读 Host 现有 plans，验证正文中的真实目录内链接。
test('plans index, internal links, archive grouping and filtering', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('/scaffold/release-records');
    await page.getByRole('link', { name: '研发计划', exact: true }).click();
    await expect(page.locator('.header__menu-item.is-active')).toHaveText('研发计划');
    await expect(page.locator('#doc_article')).toBeVisible();
    const internal = page.locator('#doc_article a[href*="/plans?doc="]').first();
    const destination = await internal.getAttribute('href');
    expect(destination).toBeTruthy();
    await internal.click();
    await expect(page).toHaveURL(destination!);
    await expect(page.locator('#doc_article')).toBeVisible();
    const archive = page.locator('.side-tree__item-link[href*="doc=archive"]').first();
    const title = await archive.innerText();
    await archive.click();
    await expect(page.locator('.p-docs-reader__title')).toHaveText(title);
    await page.getByRole('searchbox', { name: '过滤' }).fill(title);
    await expect(page.locator('.side-tree__item-link:visible').first()).toContainText(title);
    await expect(page.getByRole('link', { name: '编辑', exact: true })).toBeVisible();
    expect(errors).toEqual([]);
});
