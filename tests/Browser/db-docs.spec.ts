import { test, expect } from '@playwright/test';

test.describe('Database docs', () => {
    test('module selection preserves the sidebar scroll position', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 320 });
        await page.goto('/scaffold/db/docs');

        const aside = page.locator('#aside_container');
        const moduleLinks = aside.locator('.p-dbdoc-module:not(.p-dbdoc-module--dict)');
        await expect(moduleLinks.first()).toBeVisible();

        const expectedScroll = await aside.evaluate(element => {
            element.scrollTop = 123.5;
            return element.scrollTop;
        });
        expect(expectedScroll).toBeGreaterThan(0);

        const visibleModuleIndex = await moduleLinks.evaluateAll(links => {
            const sidebar = links[0]?.closest('#aside_container');
            if (!sidebar) return -1;
            const sidebarRect = sidebar.getBoundingClientRect();
            return links.findIndex(link => {
                const rect = link.getBoundingClientRect();
                return rect.top >= sidebarRect.top && rect.bottom <= sidebarRect.bottom;
            });
        });
        expect(visibleModuleIndex).toBeGreaterThan(0);

        await moduleLinks.nth(visibleModuleIndex).click();

        const active = aside.locator('.p-dbdoc-module.is-active');
        await expect(active).toBeVisible();
        const scrollState = await aside.evaluate(element => {
            const selected = element.querySelector('.p-dbdoc-module.is-active');
            if (!selected) return null;
            const asideRect = element.getBoundingClientRect();
            const activeRect = selected.getBoundingClientRect();
            return {
                scrollTop: element.scrollTop,
                activeVisible: activeRect.top >= asideRect.top && activeRect.bottom <= asideRect.bottom,
            };
        });

        expect(Math.abs((scrollState?.scrollTop ?? 0) - expectedScroll)).toBeLessThan(1);
        expect(scrollState?.activeVisible).toBe(true);
    });
});
