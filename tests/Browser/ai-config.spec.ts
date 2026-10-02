import { test, expect } from '@playwright/test';
import { closeSync, existsSync, openSync, readFileSync, statSync, unlinkSync, writeFileSync } from 'node:fs';

test('AI 配置表单原子保存，留空保留 key 且不回显明文', async ({ page }) => {
    const file = process.env.E2E_HOST_AI_PATH;
    test.skip(!file, '需要 Host 当前 scaffold.ai.yaml_path 的隔离文件路径');
    test.skip(existsSync(file!), '不修改 Host 既有 AI 配置');
    const old = 'ai:\n  base_url: http://127.0.0.1:1/v1\n  model: e2e-old-model\n  api_key: E2eFixtureOnly\n';
    writeFileSync(file!, old, { flag: 'wx', mode: 0o600 });
    const reader = openSync(file!, 'r');
    try {
        await page.goto('/scaffold/config/ai');
        await expect(page.getByLabel('模型', { exact: true })).toHaveValue('e2e-old-model');
        await expect(page.getByLabel('API Key', { exact: true })).toHaveValue('');
        expect(await page.content()).not.toContain('E2eFixtureOnly');
        await page.getByLabel('模型', { exact: true }).fill('e2e-new-model');
        await page.getByRole('button', { name: '保存 AI 配置', exact: true }).click();
        await expect(page.locator('.p-config-flash--ok')).toContainText('AI 配置已保存');
        await expect(page.getByLabel('模型', { exact: true })).toHaveValue('e2e-new-model');
        await expect(page.getByLabel('API Key', { exact: true })).toHaveValue('');
        expect(readFileSync(file!, 'utf8')).toContain('api_key: E2eFixtureOnly');
        expect(readFileSync(reader, 'utf8')).toBe(old);
        expect(statSync(file!).mode & 0o7777).toBe(0o600);
    } finally {
        closeSync(reader);
        unlinkSync(file!);
    }
});
