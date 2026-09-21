---
title: 19 · Web JSON 契约(统一信封)
group: Scaffold 操作手册
order: 190
---
# 19 · Web JSON 契约(统一信封)

> `/scaffold/*` 的 JSON 端点共用一套信封。**只有你要自己写 JS / 脚本消费 `/scaffold` 的接口时才需要读这页** —— 用包内自带页面的话，解包层已经替你做了。

## 1. 信封形态

唯一实现在 `src/Support/JsonEnvelope.php`（静态方法，无实例状态）。

成功：

```json
{ "ok": true, "data": { "schema": "Platform" } }
```

失败（HTTP 4xx / 5xx）：

```json
{
  "ok": false,
  "error": {
    "code": "SCHEMA_LOAD_FAILED",
    "msg": "…给人看的文案,可直接进 toast…",
    "detail": { "…": "字段级附加信息,可空" }
  }
}
```

三条硬约定：

- **`ok` 布尔是唯一成功判据** —— 别用 HTTP 200 判成功。失败必须给 4xx/5xx：`JsonEnvelope::error()` 的 `$http` 参数**故意没有默认值**，强制每个调用点表态（否则前端 `if (!res.ok)` 那条路会失效）。
- **`data` 键在成功时永远存在**（缺省是 `[]`，不是缺键）；但值可能是 `null` / `0` / `false`，判空要判**键**而不是判真值。
- **`code` 是机器码，`msg` 是文案。** 分支一律用 `code`，展示一律用 `msg`；不要拿 `msg` 做字符串匹配。

## 2. 两类永久例外(不套信封)

1. **`ApiProxyController`（`POST /scaffold/api/proxy`）** —— body 是**上游 API 的原样透传**，套信封会破坏代理语义。它的契约是：HTTP **恒 200**，真实状态码放在 body 的 `_proxy_status`、响应头放在 `_proxy_headers`。
2. **框架层响应** —— 由 Laravel 而非 scaffold 控制器产出，形态是 `{message}`（校验失败是 `{message, errors:{字段:[…]}}`）：
   - `abort()` / `abort_if()` / `abort_unless()` 抛出的 HttpException（计划 / 发版日志编辑的 403 / 404 / 409 / 422 走这条）；
   - 表单校验失败（`FormRequest` 的校验袋）；
   - 限流 429（`throttle:*`）。

   这是**刻意的**：给框架层套信封等于接管 exception handler，收益为零、风险全在框架升级上。

## 3. 前端解包层 `window.ScaffoldApi`

`public/javascript/api.js`（随 `vendor:publish --tag=public` 发布到宿主 `public/vendor/scaffold/javascript/`），**同时吃**上面三种形态。包内页面脚本一律经它读写，不要再自己拼 `{status, responseJSON}`。

| 方法 | 用途 |
|---|---|
| `pick(src)` | 从「已解析 body / jqXHR / `Response`」里取出 JSON 体 |
| `fromFetch(res, json)` | **`fetch` 专用**：`res.json()` 会消费 body，必须把「状态 + 已解析体」一起打包 |
| `httpStatus(src)` | 取 HTTP 状态码（拿不到时 `0`） |
| `isOk(src)` | 成功判据（信封 `ok` / 裸数据 / `_proxy_status` 都认） |
| `data(src)` | 取成功载荷（`{ok:true,data:null}` → `null`） |
| `errorCode(src)` | 取机器码 |
| `errorText(src, fallback)` | 取文案（吃 `error.msg`，也吃框架层 `message`） |
| `toError(src, fallback)` | 归一成 `{code, msg, detail, http}` |

三个易踩点：

- `$.ajax` 的 `success` 回调拿到的是**已解析 body**，`error` 回调拿到的是 **jqXHR** —— 两种入参形状都得喂对。
- `data()` 对**新信封**按「有没有 `data` 键」判，对**旧形态 / 代理**保持真值判断（`data ? data : 自身`）—— 后者是保真旧行为，不是笔误。
- 前端只从 `error.code` 分支。曾经存在过的「顶层字符串 `error`」读法**已删**（2026-09-19，无产出方）；若你看到它回来了，那是回归。

## 4. 机器码清单

`code` 由三处产出，全部登记如下。

### 4.1 中间件(一律 HTTP 403)

| code | 触发条件 |
|---|---|
| `ADMIN_ONLY` | `/scaffold/accounts*`（读 + 写）、`/scaffold/config/*`（写） |
| `DESIGNER_FORBIDDEN` | 设计器权限不足 |
| `WRITE_LOCKED` | 生产 / 强制只读下改 `db/designer*` · `accounts*` · `config*` · `cloud/push` · `cloud/discard` · `docs*` |
| `EDIT_LOCAL_ONLY` | 计划 / 发版日志：仅 local 且未开强制只读时可编辑 |

表单请求（非 AJAX）拿到的是 `flash_error` + 303 回退，不是信封 —— 这是「双形态拒绝回执」的既有设计。

### 4.2 控制器(显式声明)

| 域 | code（HTTP 状态） |
|---|---|
| 设计器 | `SCHEMA_LOAD_FAILED`(404/500) · `SUSPECTED_RENAMES`(422,带 detail) · `COMPACT_BLOCKED`(422,`detail.reason`) · `EMPTY_DIFF`(422) · `WRITE_FAILED`(500) · `VALIDATION_FAILED`(422) · `INVALID_FILE`(422) · `INVALID_FILENAME`(400) · `READONLY_ORIGIN`(403) · `NOT_FOUND`(404) · `DB_UNREACHABLE`(503) · `ALREADY_RAN`(409) · `DELETE_FAILED`(422/500) · `CREATE_FAILED`(422) · `CREATE_SCHEMA_FAILED`(422) · `CONFIRM_MISMATCH`(422) · `RENAME_FAILED`(422) · `UNEXPECTED`(500) |
| 设计器 · AI | `AI_NOT_CONFIGURED`(503) · `AI_UPSTREAM_ERROR`(502) · `AI_TIMEOUT`(504) |
| 文档中心 | `UNKNOWN_SOURCE`(422) · `REORDER_FAILED`(422) · `SAVE_FAILED`(422) · `DELETE_FAILED`(422) |
| 云端 | `CLOUD_NOT_CONFIGURED`(422) · `PUSH_INCOMPLETE`(422) · `PUSH_SKIPPED`(422) · `NOT_LOCAL_ONLY`(422) · `READONLY_LOCKED`(422) · `DISCARD_UNSUPPORTED`(422) · `DISCARD_INCOMPLETE`(422) |

### 4.3 业务异常(由类名派生)

`Exceptions\BaseException` 的 `render()` 产出信封，机器码 = **类名转 SCREAMING_SNAKE**（`SyncCDNException` → `SYNC_CDN_EXCEPTION`）。包内 7 个：

`SYNC_CDN_EXCEPTION` · `FORM_LAYOUT_EXCEPTION` · `SAVE_MEDIA_EXCEPTION` · `UPLOAD_EXCEPTION` · `MODEL_DELETE_EXCEPTION` · `CAN_NOT_EDIT_EXCEPTION` · `BATCH_ACTION_EXCEPTION`

**HTTP 状态沿用异常自身的 `code`**（默认 `522`，`FormLayoutException` 覆写为 `402`）—— 这是本类既有的对外契约，信封迁移没有改它，下游可能按这个码做判断。

从类名派生而不是逐个声明，是为了让**下游新增子类自动获得唯一码**，没有「漏登记导致静默降级」的口子。

## 5. 升级到 2.2.0+ 要做的事

1. **重新发布前端资源** —— `php artisan vendor:publish --provider="Mooeen\Scaffold\ScaffoldProvider" --tag=public --force`。否则宿主 `public/vendor/scaffold/` 里还是旧的页面脚本，拿不到 `api.js`。详见 [01-install.md](01-install.md)。
2. **改你自己消费 `/scaffold` JSON 的代码** —— 按 §1 的信封取载荷，错误分支改用 `error.code`。
3. **改已外迁成员的调用点** —— 如 `Utility::getStoragePath()` → `Support\Paths::storage()`、`Utility::getModels()` → `Support\StorageRegistry::models()`。完整清单见 [CHANGELOG](../../CHANGELOG.md) 的 `2.2.0` 与 `2.2.1` 两节。

## 6. 守卫在哪(契约不靠人记)

| 层 | 位置 | 钉什么 |
|---|---|---|
| PHP 结构 | `tests/Feature/Http/JsonEnvelopeTest.php` | 整个 `src/` 里只有 `JsonEnvelope` 与 `ApiProxyController` 能产出裸 JSON |
| PHP 行为 | `MiddlewareEnvelopeTest` · `FrameworkErrorShapeTest` · `Exceptions/BaseExceptionTest` | 四个中间件码 · 框架层形态 · 类名派生规则 |
| 前端 | `tests/javascript/*.test.js`（`npm run test:js`） | 解包层吃全部输入形态 + 各页面脚本的接线形态（源码扫描） |
| 真宿主 | `tests/Browser/*.spec.ts` | 浏览器里实际取到载荷（`docs-center.spec.ts` 专为信封而写） |

---

另见：[12-security.md](12-security.md)（dev 写 / prod 只读的完整边界）· [13-troubleshooting.md](13-troubleshooting.md)（排错合集）。
