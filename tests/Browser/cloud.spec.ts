import { test, expect } from '@playwright/test';

test.describe('Cloud local noise cleanup', () => {
    test('local page shows the destructive confirmation without submitting it', async ({ page }) => {
        await page.goto('/scaffold/cloud');

        const button = page.getByRole('button', { name: '清理开发噪音', exact: true });
        await expect(button).toBeVisible();
        await button.click();

        const dialog = page.getByRole('alertdialog');
        await expect(dialog).toContainText('Cloud 中未解决的运行时错误 / 慢 SQL 移入「已删除」');
        await expect(dialog).toContainText('已解决记录保持不动');
        await expect(dialog).toContainText('本地只丢弃待推记录');
        await expect(dialog.getByRole('textbox')).toHaveCount(0);
        await expect(dialog.getByRole('button', { name: '确认' })).toBeEnabled();
        await dialog.getByRole('button', { name: '取消' }).click();
        await expect(dialog).toBeHidden();
    });
});
