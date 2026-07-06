import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright e2e suite for scaffold designer UI.
 *
 * 首次使用前先录一次登录态(scaffold cookie auth):
 *   npm run test:e2e:auth
 * codegen 窗口里登录 → 关掉窗口,storage 会保存到 tests/Browser/.auth/admin.json。
 * 之后 `npm run test:e2e` 复用这份 state(失效后重录即可)。
 *
 * 不跑 web server,直接打宿主项目 URL(env E2E_BASE_URL,默认 http://localhost)。
 * 用法:E2E_BASE_URL=http://your-app.local E2E_SCHEMA=YourSchema E2E_TABLE=your_table npm run test:e2e
 */
export default defineConfig({
    testDir: './tests/Browser',
    testMatch: '**/*.spec.ts',
    fullyParallel: false,        // designer state 共享 yaml/migrations,串行更稳
    forbidOnly: !!process.env.CI,
    retries: 0,
    workers: 1,
    reporter: [['list'], ['html', { open: 'never', outputFolder: 'tests/Browser/.report' }]],
    globalSetup: './tests/Browser/global-setup.ts',
    use: {
        baseURL: process.env.E2E_BASE_URL || 'http://localhost',
        storageState: 'tests/Browser/.auth/admin.json',
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
