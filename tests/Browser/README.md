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

或设 `E2E_USERNAME` / `E2E_PASSWORD`，`global-setup.ts` 会自动登录一次。

> headless / agent 无窗口时：在宿主里 tinker 铸一个**双层** EncryptCookies cookie 注入即可（`ScaffoldAuth::makeCookie('admin')` → 套 `CookieValuePrefix` + `encrypter->encrypt` → `rawurlencode` 写进 `admin.json` 的 `scaffold_auth`，domain `127.0.0.1` / path `/`）。`/scaffold` 挂 `web` 组，直接注单层 makeCookie 值会被 EncryptCookies 解坏、一直跳登录。

## 3. 跑

```bash
# 全跑
E2E_BASE_URL=http://127.0.0.1:8088 npm run test:e2e

# 只跑接口调试器回归（BUG1 发送中切tab记错历史 / BUG2 切tab完成态不刷新）
#   —— 需所选 app 至少 2 个接口,admin 合适
E2E_BASE_URL=http://127.0.0.1:8088 E2E_API_APP=admin \
  npx playwright test tests/Browser/api-request.spec.ts

# fixture 被污染(designer save round-trip 会微改 yaml)→ 跑完自动 git checkout 还原
E2E_HOST_SCAFFOLD_DB_PATH=/path/to/host/scaffold/database npm run test:e2e:safe

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
