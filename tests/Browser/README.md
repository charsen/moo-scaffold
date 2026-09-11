# Scaffold e2e（Playwright）

两个 spec —— `designer.spec.ts`（数据库设计器全流程）、`api-request.spec.ts`（接口调试器 tab 编排回归）。
**不自带 web server**，跑在一个真实宿主 Laravel 的 `/scaffold` 上。

## 前提

1. 一个跑着的宿主，服务 `/scaffold`（本地可用任意接入了本包的 Laravel 项目）。
2. 录好的登录态 `tests/Browser/.auth/admin.json`（gitignored）。

## 1. 起本地宿主

```bash
php /path/to/host/artisan serve --host=127.0.0.1 --port=8088
```

- 宿主项目的 `vendor/charsen/moo-scaffold` 建议通过 composer path repository **软链本仓**，Blade / PHP 改动即时生效。
- ⚠️ **坑：`public/` 资源（JS / CSS / img）在宿主里是 `vendor:publish` 的拷贝，不是软链。**
  改了本仓 `public/` 下的 JS/SCSS 后**必须重新同步进宿主**，否则 e2e 跑的是旧资源（会出莫名超时/红）：

  ```bash
  npm run build:css   # 仅在改了 SCSS 时
  php /path/to/host/artisan vendor:publish --tag=public --force
  ```

## 2. 录登录态

**人工（推荐）**：

```bash
E2E_BASE_URL=http://127.0.0.1:8088 npm run test:e2e:auth
# 弹 codegen 窗口 → 登录（账号见宿主 scaffold/accounts.yaml）→ 关窗，admin.json 自动保存
```

或设 `E2E_USERNAME` / `E2E_PASSWORD`，`global-setup.ts` 会自动登录一次 —— 它会等"离开 `/scaffold/login`"
并确认拿到 `scaffold_auth`（cookie 名可用 `E2E_AUTH_COOKIE` 覆盖）后才写 state；任一步不满足就**报错退出**，
不会写一份空会话进去（写空会话会让整套 spec 以「未登录」形态假失败，极难排查）。

> headless / agent 无窗口时：在宿主里 tinker 铸一个**双层** EncryptCookies cookie 注入即可（`ScaffoldAuth::makeCookie('admin')` → 套 `CookieValuePrefix` + `encrypter->encrypt` → `rawurlencode` 写进 `admin.json` 的 `scaffold_auth`，domain `127.0.0.1` / path `/`）。`/scaffold` 挂 `web` 组，直接注单层 makeCookie 值会被 EncryptCookies 解坏、一直跳登录。

## 3. 跑

```bash
# 全跑
E2E_BASE_URL=http://127.0.0.1:8088 npm run test:e2e

# 只跑接口调试器回归（BUG1 发送中切tab记错历史 / BUG2 切tab完成态不刷新）
#   —— 需所选 app 至少 2 个接口,admin 合适
E2E_BASE_URL=http://127.0.0.1:8088 E2E_API_APP=admin \
  npx playwright test tests/Browser/api-request.spec.ts

# 跑完自动还原宿主：回滚已跟踪文件 + 删掉本次**新增**的未跟踪产物
#   （新建 schema yaml / .snapshots/*.yaml / database/migrations/*.php 都是未跟踪文件，
#     只 git checkout 删不掉它们，见 tests/Browser/safe-run.sh 顶部说明）
# ⚠ 路径要指到 scaffold/database/（不是 scaffold/）
E2E_HOST_SCAFFOLD_DB_PATH=/path/to/host/engine/scaffold/database npm run test:e2e:safe

# 调单个 spec / 看回放
npm run test:e2e:ui
```

## env 速查

| env | 作用 | 默认 |
|---|---|---|
| `E2E_BASE_URL` | 宿主地址 | `http://localhost` |
| `E2E_USERNAME` / `E2E_PASSWORD` | 让 global-setup 自动登录(没录 admin.json 时) | 无 |
| `E2E_API_APP` | `api-request.spec` 用哪个 app（需 ≥2 接口） | 首页第一个 app 卡片 |
| `E2E_SCHEMA` / `E2E_TABLE` | `designer.spec` 的 fixture schema / 表 | `Platform` / `platform_regions` |
| `E2E_TABLE_DROPDOWN` | 「字段表渲染」用的表（须含带 precision/size 的 decimal 字段） | `platform_medias` |
| `E2E_TABLE_IN_LIST` | 「侧栏切表」点开的表 | `platform_pages` |
| `E2E_SCHEMAS_CSV` | 首页「模块」断言接受的标识（schema key 或 UI 显示名都认） | LLE 的 6 个显示名 |
| `E2E_API_SCHEMAS_CSV` | API smoke 用哪些 schema（须是宿主真实 yaml 文件名） | LLE 的 6 个 |
| `E2E_INDEX_FIELDS_CSV` | 「索引段」断言的字段名 | `parent_id,region_name` |
| `E2E_FIELD_DECIMAL_KEY` / `_PRECISION` / `_SIZE` | 「字段表渲染」的字段与期望值 | `media_duration` / `6` / `10` |
| `E2E_FIELD_FORMAT` | 期望的 format 值；**留空 = 跳过该列断言** | `float:1000000` |
| `E2E_HOST_SCAFFOLD_DB_PATH` | 宿主 `scaffold/database/` 目录,供 `test:e2e:safe` 跑完还原 + 真写类 test 清理 | 无(相关 test 自动 skip) |

带完整注释与换宿主实例的模板见 [`.env.e2e.example`](../../.env.e2e.example)。

## 换宿主跑

`designer.spec` 的默认 fixture 是照维护者本地项目写的，换宿主**只覆盖上表 env 即可，spec 不用动**。
不覆盖的典型症状：`preview <Schema> failed: {"ok":false,"status":500}`（该 schema 在宿主不存在，
看着像代码坏了；Platform/Tagging 恰好同名时会过，更容易误判）。

判定「改动是否引入回归」用 **A/B 对照**：改动前后在**同一宿主、同一 env** 下各跑一遍，
比**失败集合**（不只是 passed 数）。同一份代码两次跑也可能一条红一条绿（本仓见过
`designer.spec` 的创建表类用例偶发超时），所以「疑似回归」至少要复跑一次再定性。

## ⚠ 跑完必须核对宿主

`Create table` / `Delete table` 两条用例的**清理步骤**会调 `DELETE .../tables/<key>`，
而后端 `deleteTable` 会**对整个 schema 做 diff 并生成 migration**（设计如此：删表要出 migration）。
所以它们会在宿主 `database/migrations/` 留下文件 —— 对应宿主 yaml 与 baseline 的既有差异，
**不是本次改动造成的**，但必须清：

- 用 `npm run test:e2e:safe` → 当次新增的会被清掉（见 `safe-run.sh`）；
- 直连 `npm run test:e2e` → **不会清**。这类残留下次被 safe 跑当成「宿主原有未跟踪文件」而保留，
  于是**永久累积**（本仓实测见过 3 个 `..._drop_platform_attachments_table.php` 之类）。

跑完一律 `git -C <宿主仓> status --short --untracked-files=all` 核对到干净为止。

## 计划与发版日志编辑验收

`local-markdown-editing.spec.ts` 在配置目录中以独占文件名创建隔离 Markdown 和目录内软链，验证原文/BOM/换行保存、frontmatter 预览与错误提示、软链只读、未保存提醒与冲突拒绝，以及断网、保存响应丢失、15 秒超时后的重试；结束只清理本次创建的文件。需要已登录、允许本地编辑且通过 path repository 接入当前 Scaffold 的 Host，并同步当前 `public/` 资源。

```bash
E2E_BASE_URL=http://127.0.0.1:8088 \
E2E_HOST_PLANS_PATH=/path/to/host/plans \
E2E_HOST_RELEASE_RECORDS_PATH=/path/to/host/release-records \
npm run test:e2e:safe -- tests/Browser/local-markdown-editing.spec.ts
```

两个目录参数必须对应 Host 当前 `scaffold.plans.path` / `scaffold.release_records.path` 的真实路径；未配置时对应测试明确跳过。这组测试不需要 `E2E_HOST_SCAFFOLD_DB_PATH`，也不操作数据库设计文件。
