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
| `E2E_HOST_SCAFFOLD_DB_PATH` | 宿主 `scaffold/database/` 目录,供 `test:e2e:safe` 跑完还原 + 真写类 test 清理 | 无(相关 test 自动 skip) |

> `designer.spec` 还有 `E2E_TABLE_DROPDOWN` / `E2E_SCHEMAS_CSV` / `E2E_AI_LIVE` 等 fixture override，见该文件顶部注释。

## 计划与发版日志编辑验收

`local-markdown-editing.spec.ts` 在配置目录中以独占文件名创建隔离 Markdown 和目录内软链，验证原文/BOM/换行保存、frontmatter 预览与错误提示、软链只读、未保存提醒与冲突拒绝，以及断网、保存响应丢失、15 秒超时后的重试；结束只清理本次创建的文件。需要已登录、允许本地编辑且通过 path repository 接入当前 Scaffold 的 Host，并同步当前 `public/` 资源。

```bash
E2E_BASE_URL=http://127.0.0.1:8088 \
E2E_HOST_PLANS_PATH=/path/to/host/plans \
E2E_HOST_RELEASE_RECORDS_PATH=/path/to/host/release-records \
npm run test:e2e:safe -- tests/Browser/local-markdown-editing.spec.ts
```

两个目录参数必须对应 Host 当前 `scaffold.plans.path` / `scaffold.release_records.path` 的真实路径；未配置时对应测试明确跳过。这组测试不需要 `E2E_HOST_SCAFFOLD_DB_PATH`，也不操作数据库设计文件。
