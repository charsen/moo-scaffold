import { test, expect, type ConsoleMessage, type Page } from '@playwright/test';
import { promises as fs } from 'node:fs';
import path from 'node:path';

/**
 * Designer 全流程 e2e。覆盖:
 *   1. Navigation:首页 list schema / 切表 / sidebar 选表
 *   2. Table view:字段表渲染 / 索引段 / yaml 原文 toggle / migration 历史 drawer
 *   3. Preview drawer:无 drift toast / 有 drift summary + diff 染色 / 取消 + ESC
 *   4. Batch translate(AI):modal 打开 / 草稿持久化 / 红点 / 恢复 / 上一步 / lenient checkbox
 *      + 真打 DeepSeek API(只在 E2E_AI_LIVE=1 时跑;preview cancel,不污染 yaml)
 *   5. Write ops:
 *      a) Modal open + cancel(全 modal 入口都覆盖)
 *      b) Create schema real write(需要 E2E_HOST_SCAFFOLD_DB_PATH 清理 yaml)
 *      c) Create table real write
 *      d) Delete field round-trip(加 + 删 = 净 0)
 *      e) Delete table round-trip(加表 + 删该表 = 净 0)
 *      f) 字段属性改 round-trip(改完恢复原值)
 *   6. CSP:无 Alpine warn
 *   7. API smoke:7 schema preview 全 200
 *
 * 不测试:实际"确认生成 migration"(避免污染 database/migrations/)。
 */

// page.goto 默认等 'load' 事件 + Playwright 自带 actionability wait;但 Alpine CSP build
// `:value="f.key"` 这种 bind 是 Alpine init 之后 async assign 进 input.value 的,Playwright
// `getByRole(textbox, { name })` + `toHaveValue` 在 a11y tree 还没刷新到 row 时找不到 element。
// designer/show 入口统一走这个 helper:goto + 显式等 Alpine ready + 字段表 row > 0 渲完。
async function gotoDesigner(page: Page, url: string, timeout = 5000): Promise<void> {
    await page.goto(url);
    await page.waitForFunction(() => {
        const win = window as unknown as { Alpine?: unknown };
        return typeof win.Alpine !== 'undefined'
            && document.querySelector('[x-data^="dbDesigner"]') !== null
            && document.querySelectorAll('table.p-designer-fields tbody tr').length > 0;
    }, undefined, { timeout });
}

// 找 fields 表里 key=X 的行 idx(Alpine x-model 不写 DOM value attr,只能 evaluate)
async function findFieldRowIdx(page: Page, key: string): Promise<number> {
    return await page.evaluate((k) => {
        const trs = document.querySelectorAll('table.p-designer-fields tbody tr');
        for (let i = 0; i < trs.length; i++) {
            const input = trs[i].querySelector('input[aria-label="字段 key"]') as HTMLInputElement | null;
            if (input?.value === k) return i;
        }
        return -1;
    }, key);
}

// 「index 列为空、且 select 未被禁用」的字段行 idx。
// 索引 round-trip 类用例需要一行可以自由改 index 的字段;原先写死 nth(2)
// 是在依赖 LLE platform_regions 的字段顺序(id/parent_id/_lft/_rgt),
// 换宿主后第 3 行可能是 parent_id(已是 index) → selectOption 找不到可用项。
async function findIndexFreeRowIdx(page: Page): Promise<number> {
    return await page.evaluate(() => {
        const trs = Array.from(document.querySelectorAll('table.p-designer-fields tbody tr'));
        for (let i = 0; i < trs.length; i++) {
            const sel = trs[i].querySelector('select[name="field_index"]') as HTMLSelectElement | null;
            if (sel && !sel.disabled && sel.value === '') return i;
        }
        return -1;
    });
}

// 侧栏表链接定位。**不要**用 getByRole('link', { name: /^<key>/ }) ——
// 链接的可访问名是「1. platform_regions 12」(序号 + key + 字段数三个 span 拼成),
// 前缀正则永远不会命中(踩过:该用例在任何宿主都超时)。视图已给 <a> 加 data-table-key。
function tableSidebarLink(page: Page, key: string) {
    return page.locator(`a.route-sidebar-item[data-table-key="${key}"]`);
}

// ─── plan-33: scaffold 包独立化后,fixture override ─────────────────
//
// 默认值照维护者本地 fixture 项目(LLE)写:**跟那份 yaml 强耦合**(schema 名 / 表名 /
// 字段属性 / 索引配置)。换宿主**只覆盖 env,不用改 spec**。
//
// 2026-09-11:原先散在用例里的宿主数据绑定已全部收成下面的 env(含「按字段 key 定位行」
// 替代写死行号、「按 data-table-key / data-schema-key 定位」替代前缀正则),所以
// 「git fork 改 spec body」这条路已不需要。
//
// 用法:E2E_SCHEMA=Demo E2E_TABLE=demo_users E2E_TABLE_DROPDOWN=demo_orders \
//       E2E_TABLE_IN_LIST=demo_pages E2E_SCHEMAS_CSV=Demo,Demo2 ... npm run test:e2e
// 完整清单 + 换宿主实例见 .env.e2e.example 与 ./README.md。
const SCHEMA          = process.env.E2E_SCHEMA           || 'Platform';
const TABLE           = process.env.E2E_TABLE            || 'platform_regions';
const TABLE_DROPDOWN  = process.env.E2E_TABLE_DROPDOWN   || 'platform_medias';
// 首页「模块」断言接受的标识,两种语义都认(key 或 UI 显示名):断言先看 a[data-schema-key],
// 再看 heading 文本,命中任一即过。默认值 = LLE 的 UI 显示名(Laravel.yaml → Infrastructure)。
// 换宿主推荐直接传 schema key 列表(更稳,不随 module.folder 变化)。
const SCHEMAS_LIST    = (process.env.E2E_SCHEMAS_CSV     || 'Infrastructure,Light,Order,Platform,Tagging,User').split(',');

// 「侧栏切表」用例点开的表(须是 SCHEMA 下真实存在的表)。默认 = LLE 的 platform_pages。
const SIDEBAR_TABLE   = process.env.E2E_TABLE_IN_LIST    || 'platform_pages';

// 「索引段」用例断言的字段:索引卡片里的删除按钮 title 含字段名。默认 = LLE 的 Platform.platform_regions
// 索引配置(parent_id + region_name);宿主索引不同则 override(如只有 parent_id)。
const INDEX_FIELDS    = (process.env.E2E_INDEX_FIELDS_CSV || 'parent_id,region_name')
    .split(',').map(s => s.trim()).filter(Boolean);

// 「字段表渲染」用例:按字段 key 定位行(不写死行号),三个属性期望可逐个覆盖。
// FORMAT 留空 = 跳过该列断言 —— 宿主该字段本就没有 format 属性时不该硬断。
const FIELD_DECIMAL   = process.env.E2E_FIELD_DECIMAL_KEY || 'media_duration';
const FIELD_PRECISION = process.env.E2E_FIELD_PRECISION   || '6';
const FIELD_SIZE      = process.env.E2E_FIELD_SIZE        || '10';
const FIELD_FORMAT    = process.env.E2E_FIELD_FORMAT ?? 'float:1000000';   // '' ⇒ 跳过

// ─── 1. Navigation ──────────────────────────────────────────────────

test.describe('Navigation', () => {
    test('designer 首页列出 SCHEMAS_LIST 全部模块', async ({ page }) => {
        await page.goto('/scaffold/db/designer');
        for (const s of SCHEMAS_LIST) {
            // 卡片标题渲的是 module.folder(UI 显示名),不一定等于 schema key
            // → data-schema-key 与 heading 文本两种标识都认,命中任一即过
            const byKey = page.locator(`a.p-designer-card[data-schema-key="${s}"]`);
            const byName = page.getByRole('heading', { name: s, exact: true });
            expect(
                (await byKey.count()) + (await byName.count()),
                `首页应有模块 ${s}(按 data-schema-key 或显示名匹配)`,
            ).toBeGreaterThan(0);
        }
    });

    test('点 schema 卡片进入 schema 设计页', async ({ page }) => {
        await page.goto('/scaffold/db/designer');
        await page.getByRole('link', { name: new RegExp(`^${SCHEMA} `), exact: false }).first().click();
        await expect(page).toHaveURL(new RegExp(`/designer/${SCHEMA}`));
        await expect(page.getByRole('textbox', { name: '表 key' })).toBeVisible();
    });

    test('sidebar 切表 URL 加 ?table=X + 表 key 同步', async ({ page }) => {
        await page.goto(`/scaffold/db/designer/${SCHEMA}`);
        await tableSidebarLink(page, SIDEBAR_TABLE).click();
        await expect(page).toHaveURL(new RegExp(`table=${SIDEBAR_TABLE}`));
        await expect(page.getByRole('textbox', { name: '表 key' })).toHaveValue(SIDEBAR_TABLE);
    });
});

// ─── 2. Table view ──────────────────────────────────────────────────

test.describe('Table view', () => {
    test('字段表渲染:precision/format 列存在(按字段 key 定位行)', async ({ page }) => {
        await gotoDesigner(page, `/scaffold/db/designer/${SCHEMA}?table=${TABLE_DROPDOWN}`);
        // Alpine x-model 不写 DOM value attr → 用字段 key 反查行号,不依赖 yaml 里的字段顺序
        const rows = page.locator('table.p-designer-fields tbody tr');
        const idx = await findFieldRowIdx(page, FIELD_DECIMAL);
        expect(idx, `表 ${TABLE_DROPDOWN} 应有字段 ${FIELD_DECIMAL}`).toBeGreaterThanOrEqual(0);
        const row = rows.nth(idx);
        await expect(row.getByRole('textbox', { name: /字段 key/ })).toHaveValue(FIELD_DECIMAL);
        await expect(row.getByRole('textbox', { name: /字段精度/ })).toHaveValue(FIELD_PRECISION);
        await expect(row.getByRole('textbox', { name: /字段大小/ })).toHaveValue(FIELD_SIZE);
        // format 属宿主 yaml 字段属性:该字段没有 format 时(E2E_FIELD_FORMAT='')跳过本条断言
        if (FIELD_FORMAT !== '') {
            await expect(row.getByRole('textbox', { name: /字段 format/ })).toHaveValue(FIELD_FORMAT);
        }
    });

    test('索引段显示已配置索引', async ({ page }) => {
        await gotoDesigner(page, `/scaffold/db/designer/${SCHEMA}?table=${TABLE}`);
        // 删按钮的 title 属性包含字段名(a11y name 只是 "×")
        for (const f of INDEX_FIELDS) {
            await expect(page.locator(`button[title*="${f}"]`)).toHaveCount(1);
        }
    });

    test('yaml 原文 toggle + 复制按钮', async ({ page }) => {
        await gotoDesigner(page, `/scaffold/db/designer/${SCHEMA}?table=${TABLE}`);
        const toggle = page.getByRole('button', { name: /yaml 原文/ });
        await toggle.click();
        // 展开后应该看到 pre / code 含 yaml 内容
        await expect(page.locator('pre, code').filter({ hasText: /parent_id/ }).first()).toBeVisible();
    });

    test('migration 历史 drawer:点 .php 文件名打开 + drawer 高度铺满', async ({ page }) => {
        await gotoDesigner(page, `/scaffold/db/designer/${SCHEMA}?table=${TABLE}`);
        await page.getByRole('button', { name: /\.php$/ }).first().click();
        const drawer = page.getByRole('dialog').filter({ hasText: 'Schema::' }).first();
        await expect(drawer).toBeVisible({ timeout: 5000 });
        const box = await drawer.boundingBox();
        const vp = page.viewportSize();
        expect(Math.abs((box?.height ?? 0) - (vp?.height ?? 0))).toBeLessThan(5);
    });
});

// ─── 3. Preview drawer ──────────────────────────────────────────────

test.describe('Preview drawer', () => {
    // Platform/platform_regions yaml 跟 baseline 100% 对齐是 production behavior(designer save
    // 端 sanitize strip null defaults),直接点"生成 migration"会出"无变更"toast 而非 dialog,
    // dirty 类 test 全失败。beforeAll 直接 fs 写 yaml 注入一个临时字段制造 drift,afterAll 无条件
    // 复位回原文(避免污染工作树 / 触发 migration emit)。
    // 注意:只在 SCHEMA=Platform + TABLE=platform_regions 下生效(其他 fixture override 的 schema
    // 可能 yaml 结构不同,跳过 fixture inject 走原行为)。
    let yamlPath = '';
    let fieldsYamlPath = '';
    let originalYaml: string | null = null;
    let originalFieldsYaml: string | null = null;
    let fixtureInjected = false;

    test.beforeAll(async () => {
        if (SCHEMA !== 'Platform' || TABLE !== 'platform_regions') return; // 非默认 fixture,跳过 inject
        const dbDir = process.env.E2E_HOST_SCAFFOLD_DB_PATH;
        if (!dbDir) {
            console.warn('[fixture skip] E2E_HOST_SCAFFOLD_DB_PATH 未设;preview-drift fixture inject 跳过');
            return;
        }
        yamlPath = path.resolve(dbDir, `${SCHEMA}.yaml`);
        fieldsYamlPath = path.resolve(dbDir, '_fields.yaml');
        originalYaml = await fs.readFile(yamlPath, 'utf8');
        originalFieldsYaml = await fs.readFile(fieldsYamlPath, 'utf8');
        // 既要 add 又要 drop 才能命中 test 2 染色断言(is-op-add + is-op-drop)。
        // 注意 — SchemaDiffService::detectSuspectedRenames 当 1 drop + 1 add 时返 422 SUSPECTED_RENAMES,
        // 必须 add 数 ≠ 1 或 drop 数 ≠ 1 才不触发。所以注入 **2 add + 1 drop** 打破 1+1 pattern:
        //   add a = region_e2e_preview_drift_a
        //   add b = region_e2e_preview_drift_b
        //   drop  = region_desc(baseline 有 / 注入后 yaml 没)
        let dirtyYaml = originalYaml.replace(
            /(\n            region_desc: \{[^}]*\}\n)(            deleted_at)/,
            "\n            region_e2e_preview_drift_a: { required: false, name: 临时预览测试字段A, type: varchar, size: 8 }\n            region_e2e_preview_drift_b: { required: false, name: 临时预览测试字段B, type: varchar, size: 8 }\n$2",
        );
        if (dirtyYaml === originalYaml) {
            throw new Error(`Preview drawer fixture inject failed: region_desc → deleted_at anchor not found in ${yamlPath}`);
        }
        await fs.writeFile(yamlPath, dirtyYaml, 'utf8');
        // _fields.yaml 加翻译条目,避免 designer 报字段缺翻译
        const dirtyFields = originalFieldsYaml.replace(
            /(    region_name: \{ en: 'Region Name', 'zh-CN': '地区名称' \})/,
            "$1\n    region_e2e_preview_drift_a: { en: 'Region E2E Preview Drift A', 'zh-CN': '临时预览测试字段A' }\n    region_e2e_preview_drift_b: { en: 'Region E2E Preview Drift B', 'zh-CN': '临时预览测试字段B' }",
        );
        await fs.writeFile(fieldsYamlPath, dirtyFields, 'utf8');
        fixtureInjected = true;
    });

    test.afterAll(async () => {
        if (!fixtureInjected) return;
        if (originalYaml !== null) await fs.writeFile(yamlPath, originalYaml, 'utf8');
        if (originalFieldsYaml !== null) await fs.writeFile(fieldsYamlPath, originalFieldsYaml, 'utf8');
    });

    test('clean table (Laravel cache) → info toast, no drawer', async ({ page }) => {
        // Laravel.cache 跟 baseline 100% 对齐(parser size_default + name fallback bug 修后)
        // — 用它做"无 drift"行为锚定,比 Order 稳(Order yaml 端 unsigned 状态可能浮动)
        await gotoDesigner(page, '/scaffold/db/designer/Laravel?table=cache');
        await page.getByRole('button', { name: '生成 migration' }).click();
        await expect(page.locator('[class*="toast"]').filter({ hasText: /没有变更|无变更/ }).first())
            .toBeVisible({ timeout: 5000 });
        await expect(page.getByRole('dialog', { name: '生成新 migration · 预览' })).toBeHidden();
    });

    test('dirty schema → drawer with summary + diff colored', async ({ page }) => {
        await gotoDesigner(page, `/scaffold/db/designer/${SCHEMA}?table=${TABLE}`);
        await page.getByRole('button', { name: '生成 migration' }).click();
        const dialog = page.getByRole('dialog', { name: '生成新 migration · 预览' });
        await expect(dialog).toBeVisible({ timeout: 8000 });
        // summary 段
        await expect(page.getByRole('heading', { name: '变更摘要' })).toBeVisible();
        // diff 染色:- 红 / + 绿
        await expect(page.locator('.p-designer-preview .is-op-drop').first()).toBeVisible();
        await expect(page.locator('.p-designer-preview .is-op-add').first()).toBeVisible();
        // drawer 高度铺满
        const box = await dialog.boundingBox();
        const vp = page.viewportSize();
        expect(Math.abs((box?.height ?? 0) - (vp?.height ?? 0))).toBeLessThan(5);
        await page.getByRole('button', { name: '取消' }).click();
        await expect(dialog).toBeHidden();
    });

    test('ESC 关闭 preview drawer', async ({ page }) => {
        await page.goto(`/scaffold/db/designer/${SCHEMA}?table=${TABLE}`);
        await page.getByRole('button', { name: '生成 migration' }).click();
        const dialog = page.getByRole('dialog', { name: '生成新 migration · 预览' });
        await expect(dialog).toBeVisible({ timeout: 8000 });
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
    });

    // plan 39 把 preview 里的 commit_message textarea + 复制按钮整删 — GUI 不做 git commit,
    // 开发者手动 git add + commit(show.blade.php:1132 同步注释)。对应 spec 跟着退场。
});

// ─── 4. Batch translate (AI) ───────────────────────────────────────

test.describe('Batch translate', () => {
    test.afterEach(async ({ page }) => {
        await page.evaluate(() => {
            Object.keys(localStorage)
                .filter(k => k.includes('batch-draft'))
                .forEach(k => localStorage.removeItem(k));
        });
    });

    test('draft persists + red dot + recovery on reload', async ({ page }) => {
        await page.goto(`/scaffold/db/designer/${SCHEMA}?table=${TABLE}`);
        await page.getByRole('button', { name: /\+ 批量加.*AI/ }).click();
        const textarea = page.getByRole('textbox', { name: /中文字段名/ });
        await expect(textarea).toBeVisible();
        await textarea.fill('字段A\n字段B');
        await page.getByRole('button', { name: '取消' }).click();
        // localStorage 持久化
        const draft = await page.evaluate((schema) => {
            return localStorage.getItem(`scaffold:designer:batch-draft:${schema}:platform_regions`);
        }, SCHEMA);
        expect(draft).toContain('字段A');
        // reload → 红点亮
        await page.reload();
        await expect(page.locator('.p-designer-card-block__hd-btn-dot').first()).toBeVisible();
        // 再点开 modal → 草稿恢复
        await page.getByRole('button', { name: /\+ 批量加.*AI/ }).click();
        await expect(page.getByRole('textbox', { name: /中文字段名/ })).toHaveValue(/字段A.*字段B/s);
    });

    test('宽松翻译 checkbox 默认 unchecked + 可勾', async ({ page }) => {
        await page.goto(`/scaffold/db/designer/${SCHEMA}?table=${TABLE}`);
        await page.getByRole('button', { name: /\+ 批量加.*AI/ }).click();
        const checkbox = page.getByRole('checkbox', { name: /宽松翻译/ });
        await expect(checkbox).not.toBeChecked();
        await checkbox.check();
        await expect(checkbox).toBeChecked();
        await page.getByRole('button', { name: '取消' }).click();
    });

    test('插入位置 select 列出当前所有字段', async ({ page }) => {
        await page.goto(`/scaffold/db/designer/${SCHEMA}?table=${TABLE}`);
        await page.getByRole('button', { name: /\+ 批量加.*AI/ }).click();
        const insertAfter = page.getByRole('combobox', { name: /插入位置/ });
        // platform_regions 有 parent_id / _lft / _rgt 等
        await expect(insertAfter.locator('option', { hasText: 'parent_id' })).toHaveCount(1);
        await expect(insertAfter.locator('option', { hasText: 'region_name' })).toHaveCount(1);
        await page.getByRole('button', { name: '取消' }).click();
    });

    // 真打 + 真追加 + 逐个删 round-trip。yaml 会有 sanitize drift(designer save strip null defaults
    // 等微差异),开发者跑完用 `npm run test:e2e:safe`(自动 git checkout) 或手动 `git checkout` fixture 复位。
    //
    // 用 Order/order_users:它配了 prefix="order_user_"(backend translate validate 强制要 prefix
    // required|string,Platform 各表没 prefix attr → 422)。可 env override E2E_AI_SCHEMA / E2E_AI_TABLE。
    // 真打 DeepSeek 翻译 API,需要宿主项目 scaffold/ai.yaml 配了 api_key(/scaffold/config → AI 配置),且 E2E_AI_LIVE=1。
    const AI_SCHEMA = process.env.E2E_AI_SCHEMA || 'Order';
    const AI_TABLE  = process.env.E2E_AI_TABLE  || 'order_users';

    test('真打 → confirmTranslateAppend → 字段加入 → 逐个删 → 净 0', async ({ page }) => {
        await page.goto(`/scaffold/db/designer/${AI_SCHEMA}?table=${AI_TABLE}`);
        test.skip(process.env.E2E_AI_LIVE !== '1', '真打 AI 需设 E2E_AI_LIVE=1 + 宿主 ai.yaml 配 api_key');

        await page.getByRole('button', { name: /\+ 批量加.*AI/ }).click();
        const textarea = page.getByRole('textbox', { name: /中文字段名/ });
        // backend 限制 prefix+output 总长 ≤ 20 字符(order_user_ = 11,留 9 字符给 suffix);
        // 同时 order_users 已有大量常用字段(头像/性别/手机号等),挑没用过的稀有词避撞库
        await textarea.fill('标签\n评分');

        const translateResp = page.waitForResponse(r =>
            r.url().includes('/db/designer/translate') && r.request().method() === 'POST',
            { timeout: 30000 });
        await page.getByRole('button', { name: /翻译/ }).click();
        const resp = await translateResp;
        const bodyText = await resp.text();
        // 503 AI_NOT_CONFIGURED → skip(controller 抛 AiNotConfiguredException 走 503,见 DesignerController:191)
        if (resp.status() === 503 && bodyText.includes('AI_NOT_CONFIGURED')) {
            test.skip(true, '宿主 ai.yaml 未配 api_key(/scaffold/config → AI 配置)');
            return;
        }
        expect(resp.status(), `translate body: ${bodyText.slice(0, 400)}`).toBe(200);
        const body = JSON.parse(bodyText);
        const results = body.data?.results || [];
        const validKeys: string[] = results
            .filter((r: { valid?: boolean; output?: string }) => r.valid && r.output)
            .map((r: { output: string }) => r.output);
        expect(validKeys.length, `应至少有 1 个有效翻译: ${JSON.stringify(results).slice(0, 200)}`)
            .toBeGreaterThan(0);

        // confirmTranslateAppend → 真追加 → POST /save 200
        const previewModal = page.getByRole('dialog').filter({ hasText: '翻译结果预览' }).first();
        await expect(previewModal).toBeVisible({ timeout: 3000 });
        const appendSavePromise = page.waitForResponse(r =>
            r.url().includes(`/db/designer/${AI_SCHEMA}/save`) && r.request().method() === 'POST',
            { timeout: 5000 });
        await previewModal.getByRole('button', { name: '追加', exact: true }).click();
        expect((await appendSavePromise).status()).toBe(200);

        // 验证每个 key 都进 fields 表
        for (const k of validKeys) {
            const idx = await findFieldRowIdx(page, k);
            expect(idx, `字段 ${k} 应已加入`).toBeGreaterThanOrEqual(0);
        }

        // 逐个删:每次找当前 idx → 行末 × 按钮 → confirm "删除" → 等 save → 验证消失
        for (const k of validKeys) {
            const idx = await findFieldRowIdx(page, k);
            expect(idx).toBeGreaterThanOrEqual(0);
            const row = page.locator('table.p-designer-fields tbody tr').nth(idx);
            await row.getByRole('button', { name: '删除字段', exact: true }).click();
            const confirmDialog = page.getByRole('alertdialog');
            await expect(confirmDialog).toBeVisible({ timeout: 3000 });
            await expect(confirmDialog).toContainText(k);
            const delSavePromise = page.waitForResponse(r =>
                r.url().includes(`/db/designer/${AI_SCHEMA}/save`) && r.request().method() === 'POST',
                { timeout: 5000 });
            await confirmDialog.getByRole('button', { name: '删除', exact: true }).click();
            expect((await delSavePromise).status()).toBe(200);
            // 等字段消失再走下一个(save 200 不代表 fields[] 已更新,但 designer.js splice 在 confirm 同步)
            const idxAfter = await findFieldRowIdx(page, k);
            expect(idxAfter, `字段 ${k} 应已删`).toBe(-1);
        }
    });
});

// ─── 5a. Modal write ops(open + cancel,不真触发 POST)────────────

test.describe('Modal write ops (open + cancel)', () => {
    // 通用:期间不应有 POST mutation 请求(只允许 GET preview / save 不被触发)
    async function assertNoMutation(page: any, fn: () => Promise<void>) {
        const mutations: string[] = [];
        const handler = (req: any) => {
            const m = req.method();
            const url = req.url();
            if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(m) && url.includes('/scaffold/db/designer/')) {
                mutations.push(`${m} ${url}`);
            }
        };
        page.on('request', handler);
        try {
            await fn();
        } finally {
            page.off('request', handler);
        }
        expect(mutations, `unexpected mutations:\n${mutations.join('\n')}`).toEqual([]);
    }

    test('新建 schema modal:打开 → 取消 → 关', async ({ page }) => {
        await page.goto('/scaffold/db/designer');
        await assertNoMutation(page, async () => {
            await page.getByRole('button', { name: '+ 新建 schema' }).click();
            const modal = page.getByRole('dialog').filter({ hasText: '新建' }).first();
            await expect(modal).toBeVisible({ timeout: 3000 });
            await modal.getByRole('button', { name: '取消' }).click();
            await expect(modal).toBeHidden();
        });
    });

    test('新建表 modal:sidebar "+ 新建" → 取消', async ({ page }) => {
        await page.goto(`/scaffold/db/designer/${SCHEMA}?table=${TABLE}`);
        await assertNoMutation(page, async () => {
            await page.getByRole('button', { name: '+ 新建' }).click();
            const modal = page.getByRole('dialog').filter({ hasText: '新建表' }).first();
            await expect(modal).toBeVisible({ timeout: 3000 });
            await modal.getByRole('button', { name: '取消' }).click();
            await expect(modal).toBeHidden();
        });
    });

    test('加字段 modal:"+ 加字段" → 取消', async ({ page }) => {
        await page.goto(`/scaffold/db/designer/${SCHEMA}?table=${TABLE}`);
        await assertNoMutation(page, async () => {
            await page.getByRole('button', { name: '+ 加字段', exact: true }).click();
            const modal = page.getByRole('dialog').filter({ hasText: /加字段|新增字段/ }).first();
            await expect(modal).toBeVisible({ timeout: 3000 });
            await modal.getByRole('button', { name: '取消' }).click();
            await expect(modal).toBeHidden();
        });
    });

    test('加多字段索引 modal:"+ 加多字段" → 取消', async ({ page }) => {
        await page.goto(`/scaffold/db/designer/${SCHEMA}?table=${TABLE}`);
        await assertNoMutation(page, async () => {
            await page.getByRole('button', { name: '+ 加多字段' }).click();
            const modal = page.getByRole('dialog').filter({ hasText: /索引|多字段/ }).first();
            await expect(modal).toBeVisible({ timeout: 3000 });
            await modal.getByRole('button', { name: '取消' }).click();
            await expect(modal).toBeHidden();
        });
    });

    test('删表 confirm:点 "删表" → 弹 confirm → 取消', async ({ page }) => {
        await page.goto(`/scaffold/db/designer/${SCHEMA}?table=${TABLE}`);
        await assertNoMutation(page, async () => {
            await page.getByRole('button', { name: '删表' }).click();
            // confirm modal 可能是 dialog 或 alert,标题/文本含"删"
            const confirm = page.getByRole('dialog').filter({ hasText: /删/ }).first();
            await expect(confirm).toBeVisible({ timeout: 3000 });
            await confirm.getByRole('button', { name: '取消' }).click();
            await expect(confirm).toBeHidden();
        });
    });
});

// ─── 5b. Real create-table flow ────────────────────────────────────

test.describe('Create table (real write)', () => {
    // 用 timestamp 唯一前缀避免重跑冲突
    const TEMP_KEY = `e2e_temp_videos_${Date.now()}`;

    test('新建表 → 写 yaml → redirect → sidebar 出现新表 → 字段表渲染 → 测后 DELETE 清理', async ({ page }) => {
        // 用 gotoDesigner 而非裸 goto:后者只等 load,不等 Alpine init —— 而「+ 新建」是
        // x-on:click 绑定,Alpine 没起来时点了不发请求(表现为 waitForResponse 超时,极易误判成后端坏)
        await gotoDesigner(page, `/scaffold/db/designer/${SCHEMA}?table=${TABLE}`);
        await page.getByRole('button', { name: '+ 新建', exact: true }).click();
        const modal = page.getByRole('dialog').filter({ hasText: '新建表' }).first();
        await expect(modal).toBeVisible({ timeout: 3000 });
        // 填表
        await modal.locator('input[name="new_table_key"]').fill(TEMP_KEY);
        await modal.locator('input[name="new_table_name"]').fill('E2E 测试视频');
        await modal.locator('input[name="new_table_desc"]').fill('e2e 临时表');
        // 拦截 createTable POST + 跟随 redirect。
        // 超时 15s(非 6s):这条是「真写 yaml + 重建 schema 缓存 + 服务端 redirect」,
        // 冷态(视图首编译 / 缓存失效)下 6s 会偶发不够 —— 实测同代码两次跑一红一绿。
        const respPromise = page.waitForResponse(r =>
            r.url().includes(`/db/designer/${SCHEMA}/tables`) && r.request().method() === 'POST',
            { timeout: 15000 });
        await modal.getByRole('button', { name: '创建', exact: false }).click();
        const resp = await respPromise;
        expect(resp.status()).toBe(200);
        // redirect 后 URL 含新表 key
        await expect(page).toHaveURL(new RegExp(`table=${TEMP_KEY}`), { timeout: 5000 });
        // sidebar 应出现新表 link(按 data-table-key 定位:可访问名带序号前缀,前缀正则失配)
        await expect(tableSidebarLink(page, TEMP_KEY)).toBeVisible();
        // 表 key input 同步
        await expect(page.getByRole('textbox', { name: '表 key' })).toHaveValue(TEMP_KEY);

        // 测后清理:走 Alpine designer._post(带 csrfToken)调 DELETE 端点删表 yaml 节点
        // (净 0,避免历史 e2e_temp_videos_* 累积)
        const delResp = page.waitForResponse(r =>
            r.url().includes(`/db/designer/${SCHEMA}/tables/${TEMP_KEY}`) && r.request().method() === 'DELETE',
            { timeout: 6000 });
        await page.evaluate(async ({ schema, table }) => {
            // @ts-expect-error Alpine global
            const Alpine = window.Alpine;
            const el = document.querySelector('[x-data^="dbDesigner"]') as HTMLElement | null;
            if (!el || !Alpine) throw new Error('designer Alpine 组件未挂载');
            await Alpine.$data(el)._post(
                `/scaffold/db/designer/${schema}/tables/${table}`,
                { confirm_key: table },
                { method: 'DELETE' },
            );
        }, { schema: SCHEMA, table: TEMP_KEY });
        expect((await delResp).status()).toBe(200);
    });
});

// ─── 5c. Real create-schema flow ───────────────────────────────────
//
// 真往 scaffold/database/ 写 yaml。需要 E2E_HOST_SCAFFOLD_DB_PATH 指向宿主项目
// scaffold yaml 目录用来 afterAll 清理(没设 → 自动 skip,避免污染)。
// 用法:E2E_HOST_SCAFFOLD_DB_PATH=/path/to/your/laravel-root/scaffold/database npx playwright test

const HOST_DB_PATH = process.env.E2E_HOST_SCAFFOLD_DB_PATH || '';

test.describe('Create schema (real write)', () => {
    test.skip(!HOST_DB_PATH, '需 E2E_HOST_SCAFFOLD_DB_PATH 指向宿主 scaffold/database/ 才能清理 yaml');

    const tempSchemas: string[] = [];

    test.afterAll(async () => {
        if (!HOST_DB_PATH) return;
        for (const s of tempSchemas) {
            const yamlPath = path.join(HOST_DB_PATH, `${s}.yaml`);
            await fs.rm(yamlPath, { force: true });
        }
    });

    test('+ 新建 schema → 真 POST → 落盘 yaml → redirect 到 schema 设计页', async ({ page }) => {
        const tempSchema = `E2eTmp${Date.now().toString().slice(-10)}`;
        tempSchemas.push(tempSchema);

        await page.goto('/scaffold/db/designer');
        await page.getByRole('button', { name: '+ 新建 schema' }).click();
        const modal = page.getByRole('dialog').filter({ hasText: /新建 schema/ }).first();
        await expect(modal).toBeVisible({ timeout: 3000 });

        await modal.locator('#newschema-key').fill(tempSchema);
        await modal.locator('#newschema-name').fill('e2e 临时模块');
        await modal.locator('#newschema-desc').fill('playwright 测试用,跑完自动清理');

        const respPromise = page.waitForResponse(r =>
            r.url().includes('/db/designer/schemas') && r.request().method() === 'POST',
            { timeout: 6000 });
        await modal.getByRole('button', { name: '创建', exact: true }).click();
        const resp = await respPromise;
        expect(resp.status()).toBe(200);

        // redirect 后 URL 含新 schema
        await expect(page).toHaveURL(new RegExp(`/designer/${tempSchema}`), { timeout: 5000 });

        // yaml 落盘
        const yamlPath = path.join(HOST_DB_PATH, `${tempSchema}.yaml`);
        const exists = await fs.stat(yamlPath).then(() => true, () => false);
        expect(exists, `yaml 应已创建: ${yamlPath}`).toBe(true);

        // sidebar / 内容里有"+ 新建表"按钮(空 schema 的入口)
        await expect(page.getByRole('button', { name: '+ 新建表' }).first()).toBeVisible();
    });

    test('+ 新建 schema → PascalCase 校验前端拦截(toast warn)', async ({ page }) => {
        await page.goto('/scaffold/db/designer');
        await page.getByRole('button', { name: '+ 新建 schema' }).click();
        const modal = page.getByRole('dialog').filter({ hasText: /新建 schema/ }).first();
        await expect(modal).toBeVisible({ timeout: 3000 });
        await modal.locator('#newschema-key').fill('lower_case_invalid');
        await modal.locator('#newschema-name').fill('x');

        // 监听 POST 不该被发出(纯前端拦截)
        const mutations: string[] = [];
        page.on('request', req => {
            if (req.method() === 'POST' && req.url().includes('/db/designer/schemas')) {
                mutations.push(req.url());
            }
        });
        await modal.getByRole('button', { name: '创建', exact: true }).click();
        await page.waitForTimeout(500);
        expect(mutations).toEqual([]);
        // toast warn
        await expect(page.locator('[class*="toast"]').filter({ hasText: /PascalCase/ }).first())
            .toBeVisible({ timeout: 3000 });
        await modal.getByRole('button', { name: '取消' }).click();
    });
});

// ─── 5d. Delete field (round-trip) ─────────────────────────────────
//
// 净 0 策略:加临时字段 → 行内 ⋯ → "删除" → confirm alertdialog → save 200 → 字段消失。
// 由于 designer save sanitize 可能 strip null defaults,工作树 yaml 会有微差异(已知行为)。

test.describe('Delete field (round-trip)', () => {
    test('加临时字段 → 行内 ⋯ "删除" → confirm "删除" → POST 200 → 字段消失', async ({ page }) => {
        await page.goto(`/scaffold/db/designer/${SCHEMA}?table=${TABLE}`);
        const tempKey = `e2e_tmp_${Date.now().toString().slice(-9)}`;

        // 1. 加临时字段
        await page.getByRole('button', { name: '+ 加字段', exact: true }).click();
        // 用 #addfield-key 唯一锚定(避免撞批量加 modal),scope locator
        const addModal = page.locator('[role="dialog"]').filter({ has: page.locator('#addfield-key') }).first();
        await expect(addModal).toBeVisible({ timeout: 3000 });
        await addModal.locator('#addfield-key').fill(tempKey);
        await addModal.locator('#addfield-name').fill('e2e 临时');

        const addSavePromise = page.waitForResponse(r =>
            r.url().includes(`/db/designer/${SCHEMA}/save`) && r.request().method() === 'POST',
            { timeout: 5000 });
        await addModal.getByRole('button', { name: '加', exact: true }).click();
        expect((await addSavePromise).status()).toBe(200);

        // 2. 找新字段行
        const idx = await findFieldRowIdx(page, tempKey);
        expect(idx, `新字段 ${tempKey} 应在 fields 表里`).toBeGreaterThanOrEqual(0);
        const newRow = page.locator('table.p-designer-fields tbody tr').nth(idx);

        // 3. 行末 × 按钮直接删(替代旧 ⋯ + popover "删除")
        await newRow.getByRole('button', { name: '删除字段', exact: true }).click();

        // 4. confirm alertdialog 出现 + 含字段 key
        const confirmDialog = page.getByRole('alertdialog');
        await expect(confirmDialog).toBeVisible({ timeout: 3000 });
        await expect(confirmDialog).toContainText(tempKey);

        // 5. 点真"删除" → save 200
        const delSavePromise = page.waitForResponse(r =>
            r.url().includes(`/db/designer/${SCHEMA}/save`) && r.request().method() === 'POST',
            { timeout: 5000 });
        await confirmDialog.getByRole('button', { name: '删除', exact: true }).click();
        expect((await delSavePromise).status()).toBe(200);

        // 6. 字段消失
        const idxAfter = await findFieldRowIdx(page, tempKey);
        expect(idxAfter, `字段 ${tempKey} 应已删`).toBe(-1);
    });
});

// ─── 5e. Delete table (round-trip) ─────────────────────────────────
//
// 净 0 策略:走 5b 同款 timestamp 临时表 → redirect 进新表 → "删表" → confirm → DELETE 200 → 跳走。
// 跑完 yaml 里临时表节点既不存在(创建后又删) → 工作树干净(除 designer save 可能 strip 注释格式)。

test.describe('Delete table (round-trip)', () => {
    test('+ 新建表 → 真"删表" → confirm 输入 key → DELETE 200 → 跳回 list', async ({ page }) => {
        const TEMP_KEY = `e2e_del_tmp_${Date.now()}`;

        // 1. 加表(同 5b:必须先等 Alpine ready,否则「+ 新建」的 x-on:click 未绑定)
        await gotoDesigner(page, `/scaffold/db/designer/${SCHEMA}?table=${TABLE}`);
        await page.getByRole('button', { name: '+ 新建', exact: true }).click();
        const modal = page.getByRole('dialog').filter({ hasText: '新建表' }).first();
        await expect(modal).toBeVisible({ timeout: 3000 });
        await modal.locator('input[name="new_table_key"]').fill(TEMP_KEY);
        await modal.locator('input[name="new_table_name"]').fill('删表测试');
        await modal.locator('input[name="new_table_desc"]').fill('round-trip');
        const createResp = page.waitForResponse(r =>
            r.url().includes(`/db/designer/${SCHEMA}/tables`) && r.request().method() === 'POST',
            { timeout: 15000 });
        await modal.getByRole('button', { name: '创建', exact: false }).click();
        expect((await createResp).status()).toBe(200);
        await expect(page).toHaveURL(new RegExp(`table=${TEMP_KEY}`), { timeout: 5000 });

        // 2. 点"删表" → confirm modal
        await page.getByRole('button', { name: '删表', exact: true }).click();
        const delModal = page.getByRole('dialog').filter({ hasText: /删除表/ }).first();
        await expect(delModal).toBeVisible({ timeout: 3000 });
        // 永久删除 button 默认 disabled,要输 key
        const finalBtn = delModal.locator('.btn--danger');
        await expect(finalBtn).toBeDisabled();

        // 3. 输入表 key
        await delModal.locator('#del-confirm').fill(TEMP_KEY);
        await expect(finalBtn).toBeEnabled();

        // 4. 点"永久删除" → DELETE 200
        const delResp = page.waitForResponse(r =>
            r.url().includes(`/db/designer/${SCHEMA}/tables/${TEMP_KEY}`) && r.request().method() === 'DELETE',
            { timeout: 6000 });
        await finalBtn.click();
        expect((await delResp).status()).toBe(200);

        // 5. 800ms 后 redirect 回 schema 设计页(无 table param)
        await page.waitForURL(new RegExp(`/designer/${SCHEMA}(?!\\?table=${TEMP_KEY})`), { timeout: 3000 });
        // sidebar 不再有该表
        await expect(tableSidebarLink(page, TEMP_KEY)).toHaveCount(0);
    });
});

// ─── 5f. Rename field (round-trip) ─────────────────────────────────
//
// 2026-05-23:改名 popover 删除 — 改用 key 列行内 input 直接 fill + blur 触发 setFieldKey
// → rename_hint → renameColumn migration(同样保数据,不是 drop+add)。
// 删除走行末 × 按钮(替代 ⋯ + popover 模式)。

test.describe('Rename field (round-trip)', () => {
    test('加字段 → key 列行内改名 → 字段 key 更新 → 行末 × 删 → 净 0', async ({ page }) => {
        await page.goto(`/scaffold/db/designer/${SCHEMA}?table=${TABLE}`);
        const ts = Date.now().toString().slice(-9);
        const tempKey = `e2e_rn_a_${ts}`;
        const renamedKey = `e2e_rn_b_${ts}`;

        // 1. 加临时字段
        await page.getByRole('button', { name: '+ 加字段', exact: true }).click();
        const addModal = page.locator('[role="dialog"]').filter({ has: page.locator('#addfield-key') }).first();
        await expect(addModal).toBeVisible({ timeout: 3000 });
        await addModal.locator('#addfield-key').fill(tempKey);
        await addModal.locator('#addfield-name').fill('e2e rename');
        const addSave = page.waitForResponse(r =>
            r.url().includes(`/db/designer/${SCHEMA}/save`) && r.request().method() === 'POST',
            { timeout: 5000 });
        await addModal.getByRole('button', { name: '加', exact: true }).click();
        expect((await addSave).status()).toBe(200);

        // 2. 行内 key 列 input → fill 新 key + blur → setFieldKey 触发 rename_hint → save 200
        let idx = await findFieldRowIdx(page, tempKey);
        expect(idx).toBeGreaterThanOrEqual(0);
        const row = page.locator('table.p-designer-fields tbody tr').nth(idx);
        const keyInput = row.getByRole('textbox', { name: '字段 key' });
        const renameSave = page.waitForResponse(r =>
            r.url().includes(`/db/designer/${SCHEMA}/save`) && r.request().method() === 'POST',
            { timeout: 5000 });
        await keyInput.fill(renamedKey);
        await keyInput.blur();
        expect((await renameSave).status()).toBe(200);

        // 3. 旧 key 消失,新 key 出现
        expect(await findFieldRowIdx(page, tempKey)).toBe(-1);
        idx = await findFieldRowIdx(page, renamedKey);
        expect(idx, `字段应已改名为 ${renamedKey}`).toBeGreaterThanOrEqual(0);

        // 4. 行末 × 删 → confirm 删 → save 200 → 净 0
        const renamedRow = page.locator('table.p-designer-fields tbody tr').nth(idx);
        await renamedRow.getByRole('button', { name: '删除字段', exact: true }).click();
        const confirmDialog = page.getByRole('alertdialog');
        await expect(confirmDialog).toBeVisible({ timeout: 3000 });
        const delSave = page.waitForResponse(r =>
            r.url().includes(`/db/designer/${SCHEMA}/save`) && r.request().method() === 'POST',
            { timeout: 5000 });
        await confirmDialog.getByRole('button', { name: '删除', exact: true }).click();
        expect((await delSave).status()).toBe(200);
        expect(await findFieldRowIdx(page, renamedKey)).toBe(-1);
    });
});

// ─── 5g. Multi-field index (round-trip) ────────────────────────────
//
// "+ 加多字段" modal → 填索引名 + 至少 2 chip → "加" → save → 索引行出现 → × 删 → confirm → save → 净 0。

test.describe('Multi-field index (round-trip)', () => {
    test('加多字段索引 → save 200 → × 删 → confirm → save 200 → 净 0', async ({ page }) => {
        await page.goto(`/scaffold/db/designer/${SCHEMA}?table=${TABLE}`);
        const idxName = `e2e_midx_${Date.now().toString().slice(-9)}`;

        // 1. 打开 modal
        await page.getByRole('button', { name: '+ 加多字段' }).click();
        const modal = page.locator('[role="dialog"]').filter({ has: page.locator('#midx-name') }).first();
        await expect(modal).toBeVisible({ timeout: 3000 });

        // 2. 填名 + 选 2 个 chip(platform_regions:用 parent_id + region_name,两个都是 user-editable)
        await modal.locator('#midx-name').fill(idxName);
        await modal.locator('button.p-designer-toggle-chip[data-field="parent_id"]').click();
        await modal.locator('button.p-designer-toggle-chip[data-field="region_name"]').click();

        // 3. 点"加" → save 200
        const addSave = page.waitForResponse(r =>
            r.url().includes(`/db/designer/${SCHEMA}/save`) && r.request().method() === 'POST',
            { timeout: 5000 });
        await modal.getByRole('button', { name: '加', exact: true }).click();
        expect((await addSave).status()).toBe(200);

        // 4. 多字段索引行出现:行内是 <code x-text="m.name">,删按钮 title="删除"(没 idxName)。
        //    locator:tr 内的 code:text-is(idxName) 锚定到对应行,再取行内的 × 按钮
        const indexRow = page.locator('tr').filter({ has: page.locator(`code:text-is("${idxName}")`) }).first();
        await expect(indexRow).toBeVisible({ timeout: 3000 });

        // 5. 点行内 × 删 → confirm → save 200
        await indexRow.locator('button[title="删除"]').click();
        const confirmDialog = page.getByRole('alertdialog');
        await expect(confirmDialog).toBeVisible({ timeout: 3000 });
        await expect(confirmDialog).toContainText(idxName);
        const delSave = page.waitForResponse(r =>
            r.url().includes(`/db/designer/${SCHEMA}/save`) && r.request().method() === 'POST',
            { timeout: 5000 });
        await confirmDialog.getByRole('button', { name: '删除', exact: true }).click();
        expect((await delSave).status()).toBe(200);

        // 6. 索引消失
        await expect(page.locator(`code:text-is("${idxName}")`)).toHaveCount(0);
    });
});

// ─── 5h. Enum group (round-trip) ───────────────────────────────────
//
// "+ 加枚举" → 选 region_name 字段 → group 出现(空 entry 默认)→ 填 entry key+value → save 200
//   → × 删 entry → save → × 删 group → save → 净 0。

test.describe('Enum group (round-trip)', () => {
    test('加枚举组 → 填 entry → × 删 entry → × 删组 → 净 0', async ({ page }) => {
        await page.goto(`/scaffold/db/designer/${SCHEMA}?table=${TABLE}`);
        const entryKey = `e2e${Date.now().toString().slice(-7)}`;

        // 1. 打开加枚举组 modal → 选 1 个字段(Alpine CSP build 下 :value 未必写 DOM value attr,
        //    用 index 1 跳过 "— 请选 —" 默认选项,再读 inputValue 拿实际 field key)
        await page.getByRole('button', { name: '+ 加枚举' }).click();
        const modal = page.locator('[role="dialog"]').filter({ has: page.locator('#newenum-field') }).first();
        await expect(modal).toBeVisible({ timeout: 3000 });
        const selectEl = modal.locator('#newenum-field');
        await selectEl.selectOption({ index: 1 });
        const enumField = await selectEl.inputValue();
        expect(enumField).not.toBe('');
        await modal.getByRole('button', { name: '加', exact: true }).click();
        // 加 group 不立刻 _scheduleSave(看 confirmAddEnumGroup line 1031-1041,无 _scheduleSave);
        // 等 group DOM 出现即可
        const group = page.locator(`.p-designer-enums-group[data-egroup="${enumField}"]`);
        await expect(group).toBeVisible({ timeout: 3000 });

        // 2. 填空 entry 的 key + value(group 默认有 1 空 entry)→ trigger save
        const entryRow = group.locator('tbody tr').first();
        const fillSave = page.waitForResponse(r =>
            r.url().includes(`/db/designer/${SCHEMA}/save`) && r.request().method() === 'POST',
            { timeout: 5000 });
        await entryRow.locator('input[name="enum_key"]').fill(entryKey);
        await entryRow.locator('input[name="enum_value"]').fill('1');
        await entryRow.locator('input[name="enum_label_zh"]').fill('e2e');
        await entryRow.locator('input[name="enum_label_zh"]').blur();
        expect((await fillSave).status()).toBe(200);

        // 3. × 删 entry → confirm → save 200
        // 行内 button.p-designer-fields__row-btn title="删除"(同 row 也有 input,只此 1 button)
        await entryRow.locator('button[title="删除"]').click();
        let confirmDialog = page.getByRole('alertdialog');
        await expect(confirmDialog).toBeVisible({ timeout: 3000 });
        const delEntrySave = page.waitForResponse(r =>
            r.url().includes(`/db/designer/${SCHEMA}/save`) && r.request().method() === 'POST',
            { timeout: 5000 });
        await confirmDialog.getByRole('button', { name: '删除', exact: true }).click();
        expect((await delEntrySave).status()).toBe(200);

        // 4. × 删 group → confirm → save 200
        await group.locator('button[title="删枚举组"]').click();
        confirmDialog = page.getByRole('alertdialog');
        await expect(confirmDialog).toBeVisible({ timeout: 3000 });
        await expect(confirmDialog).toContainText(enumField);
        const delGroupSave = page.waitForResponse(r =>
            r.url().includes(`/db/designer/${SCHEMA}/save`) && r.request().method() === 'POST',
            { timeout: 5000 });
        await confirmDialog.getByRole('button', { name: '删除', exact: true }).click();
        expect((await delGroupSave).status()).toBe(200);

        // 5. group 消失 → 净 0
        await expect(page.locator(`.p-designer-enums-group[data-egroup="${enumField}"]`)).toHaveCount(0);
    });
});

// ─── 5i. Cmd/Ctrl+S 快捷键 ─────────────────────────────────────────
//
// 改字段 → 立刻 Cmd+S(< 500ms 内 bypass debounce)→ POST 200 + "已保存" toast → restore。

test.describe('Cmd+S shortcut', () => {
    test('dirty 时 Cmd+S(saveNow)→ bypass 500ms debounce → POST < 400ms 内 fire', async ({ page }) => {
        // designer.js fix:saveNow 用 _saveTimer 区分"等待中(timer 还在) vs 真 in-flight(timer null)"
        // → 仅真 in-flight 才 noop;fill 后 timer 等待中 → saveNow 立刻 clear + flush
        await page.goto(`/scaffold/db/designer/${SCHEMA}?table=${TABLE}`);
        const rows = page.locator('table.p-designer-fields tbody tr');
        const parentRow = rows.nth(1);
        const nameInput = parentRow.getByRole('textbox', { name: /字段中文名/ });
        const original = await nameInput.inputValue();

        // fill 触发 _scheduleSave 启动 500ms timer + savingState='saving'
        await nameInput.fill(original + ' (cmds)');

        // 立刻 saveNow:必须 bypass timer < 400ms 出 POST
        const t0 = Date.now();
        const savePromise = page.waitForResponse(r =>
            r.url().includes(`/db/designer/${SCHEMA}/save`) && r.request().method() === 'POST',
            { timeout: 2000 });
        await page.evaluate(() => {
            // @ts-expect-error Alpine global
            const Alpine = window.Alpine;
            const el = document.querySelector('[x-data^="dbDesigner"]') as HTMLElement | null;
            if (!el || !Alpine) throw new Error('designer Alpine 组件未挂载');
            Alpine.$data(el).saveNow();
        });
        const resp = await savePromise;
        const elapsed = Date.now() - t0;
        expect(resp.status()).toBe(200);
        expect(elapsed, `Cmd+S 应 bypass 500ms debounce → < 400ms,实际 ${elapsed}ms`).toBeLessThan(400);

        // restore
        const restoreSave = page.waitForResponse(r =>
            r.url().includes(`/db/designer/${SCHEMA}/save`) && r.request().method() === 'POST',
            { timeout: 5000 });
        await nameInput.fill(original);
        await nameInput.blur();
        await restoreSave;
    });
});

// ─── 5j. Write ops (yaml backup/restore) ────────────────────────────

test.describe('Write ops', () => {
    // designer save 端点会 sanitize yaml(strip null default 等),即便 spec 改完恢复原值,
    // 工作树仍可能微差异。`npm run test:e2e` 已 chain `git checkout HEAD -- Platform.yaml` 自动恢复。

    // 备份 → 改 → 验证 POST 200 → restore 原 yaml
    // 用 git checkout HEAD 恢复(spec 跑完工作树跟跑前一致)
    test('改字段中文名 → debounce 500ms → POST /save 200(改完恢复原值)', async ({ page }) => {
        await page.goto(`/scaffold/db/designer/${SCHEMA}?table=${TABLE}`);
        // platform_regions 字段顺序:0=id, 1=parent_id, 2=_lft, ...
        const rows = page.locator('table.p-designer-fields tbody tr');
        const parentRow = rows.nth(1);
        const nameInput = parentRow.getByRole('textbox', { name: /字段中文名/ });
        await expect(nameInput).toBeVisible();
        const original = await nameInput.inputValue();
        // 拦截下一次 POST /save
        const savePromise = page.waitForResponse(r =>
            r.url().includes(`/db/designer/${SCHEMA}/save`) && r.request().method() === 'POST',
            { timeout: 5000 });
        await nameInput.fill(original + ' (e2e)');
        await nameInput.blur();
        const resp = await savePromise;
        expect(resp.status()).toBe(200);
        // restore:改回原值让 designer 自己 save 一次
        const restorePromise = page.waitForResponse(r =>
            r.url().includes(`/db/designer/${SCHEMA}/save`) && r.request().method() === 'POST',
            { timeout: 5000 });
        await nameInput.fill(original);
        await nameInput.blur();
        await restorePromise;
    });

    test('改字段索引列(行内 select)→ debounce 500ms → POST /save 200(改完恢复原值)', async ({ page }) => {
        await page.goto(`/scaffold/db/designer/${SCHEMA}?table=${TABLE}`);
        // 挑一行「index 为空、select 未禁用」的字段当靶(不写死字段顺序 ——
        // 原先写死 nth(2) 是在依赖 LLE platform_regions 的 id/parent_id/_lft/_rgt 顺序)
        const rows = page.locator('table.p-designer-fields tbody tr');
        const freeIdx = await findIndexFreeRowIdx(page);
        expect(freeIdx, `${TABLE} 应有一行 index 可自由改动的字段`).toBeGreaterThanOrEqual(0);
        const targetRow = rows.nth(freeIdx);
        const indexSelect = targetRow.getByRole('combobox', { name: /字段索引/ });
        await expect(indexSelect).toBeVisible();
        const original = await indexSelect.inputValue();
        // 拦截下一次 POST /save
        const savePromise = page.waitForResponse(r =>
            r.url().includes(`/db/designer/${SCHEMA}/save`) && r.request().method() === 'POST',
            { timeout: 5000 });
        await indexSelect.selectOption({ value: 'index' });
        const resp = await savePromise;
        expect(resp.status()).toBe(200);
        // restore
        const restorePromise = page.waitForResponse(r =>
            r.url().includes(`/db/designer/${SCHEMA}/save`) && r.request().method() === 'POST',
            { timeout: 5000 });
        await indexSelect.selectOption({ value: original });
        await restorePromise;
    });

    test('单字段索引三态切换:none → index → unique-db → none(每步 POST 200)', async ({ page }) => {
        // designer_index_options(plan-51 之后)= ['', primary, unique-app, unique-db, index]
        // —— 旧的 'unique' 已拆成 unique-app / unique-db 并移除,传 'unique' 会 selectOption 找不到 option。
        // 不测 primary(首列通常已是 primary,多 primary 不合法)。
        await page.goto(`/scaffold/db/designer/${SCHEMA}?table=${TABLE}`);
        const rows = page.locator('table.p-designer-fields tbody tr');
        const freeIdx = await findIndexFreeRowIdx(page);
        expect(freeIdx, `${TABLE} 应有一行 index 可自由改动的字段`).toBeGreaterThanOrEqual(0);
        const targetRow = rows.nth(freeIdx);
        const indexSelect = targetRow.getByRole('combobox', { name: /字段索引/ });
        const original = await indexSelect.inputValue();
        const transitions: Array<string> = ['index', 'unique-db', original];
        for (const next of transitions) {
            const savePromise = page.waitForResponse(r =>
                r.url().includes(`/db/designer/${SCHEMA}/save`) && r.request().method() === 'POST',
                { timeout: 5000 });
            await indexSelect.selectOption({ value: next });
            expect((await savePromise).status(), `切到 ${next || '(none)'} 应 200`).toBe(200);
            expect(await indexSelect.inputValue()).toBe(next);
        }
    });
});

// ─── 6. CSP / 7. API smoke ────────────────────────────────────────

test.describe('CSP', () => {
    test('no Alpine CSP warnings on Platform designer page', async ({ page }) => {
        const warnings: string[] = [];
        page.on('console', (msg: ConsoleMessage) => {
            if (msg.type() === 'warning' || msg.type() === 'error') {
                const text = msg.text();
                if (text.includes('Alpine') || text.includes('CSP')) warnings.push(text);
            }
        });
        await page.goto(`/scaffold/db/designer/${SCHEMA}?table=${TABLE}`);
        await expect(page.getByRole('textbox', { name: '表 key' })).toBeVisible();
        await page.waitForTimeout(500);
        expect(warnings, `unexpected Alpine/CSP warnings:\n${warnings.join('\n')}`).toEqual([]);
    });
});

test.describe('API smoke', () => {
    // API yaml schema 名(跟 designer UI category 名可能不同 — e.g. UI 显示 "Infrastructure",yaml 文件名 "Laravel.yaml")
    // 默认 = LLE 当前实际 yaml 文件名;其他下游 E2E_API_SCHEMAS_CSV env override
    const SCHEMAS = (process.env.E2E_API_SCHEMAS_CSV || 'Laravel,Light,Order,Platform,Tagging,User').split(',');

    for (const schema of SCHEMAS) {
        test(`preview ${schema} returns 200 + parses tables`, async ({ page }) => {
            await page.goto('/scaffold/db/designer');
            const data = await page.evaluate(async (s) => {
                const r = await fetch(`/scaffold/db/designer/${s}/preview`, { headers: { Accept: 'application/json' } });
                if (!r.ok) return { ok: false, status: r.status };
                const j = await r.json();
                return { ok: true, tables: Object.keys(j?.data?.tables || {}).length };
            }, schema);
            expect(data.ok, `preview ${schema} failed: ${JSON.stringify(data)}`).toBe(true);
            expect(data.tables).toBeGreaterThanOrEqual(0);
        });
    }
});
