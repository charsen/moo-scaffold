# 12 · 安全模型

> scaffold 的"开发可写、生产只读"是定位里写死的原则,不是中间件偶然行为:生产环境不动代码、不动账号、不动配置。本篇讲两条写保护防线、登录链路、CSRF、CSP、防御头。

## 总览

一条请求进 `/scaffold/*` 穿过这串中间件:`SecurityHeaders`(加 CSP / 防御头)→ 路由级 `throttle`(login / intake 限流)→ `ScaffoldAuthenticate`(校验 `scaffold_auth` cookie)→ Session + ShareErrors → `VerifyCsrfToken` → `EnforceScaffoldWritable`(prod / readonly 拒所有写)。并行还有 CLI 防线:`config('scaffold.only_in_local')`。

## 两条强制写保护

### 1. CLI 层:`only_in_local`

```php
// config/scaffold.php
'only_in_local' => true,
```

所有 `moo:*` 生成器命令在 `app()->environment() !== 'local'` 时直接退出。即便有人在生产手滑跑 `php artisan moo:free`,也动不到任何代码 / migration / YAML。

### 2. Web 层:`EnforceScaffoldWritable` 中间件

挂在所有 `/scaffold/*` 受保护路由组(`src/Http/Middleware/EnforceScaffoldWritable.php`):

- **GET / HEAD / OPTIONS** → 永远放行。
- **POST / PUT / PATCH / DELETE**,命中高风险簇(designer / accounts / config / cloud/push)时:
  - `APP_ENV=production` → 403。
  - `SCAFFOLD_CONFIG_READONLY=true` → 403。
  - 其它(api 调试 / csp-report 等)→ 放行。

结果:读类页面(查文档 / 看 ACL / 看 runtime)生产也能用;改代码与账号体系的写操作生产一律拒;api 调试这类非高风险写仍放行。**新增写操作必须走这一层**,绕过它(controller 直接写文件不过 middleware)= 违反定位,review 时拦下。

### 单点防御

部分 controller 内部再守一道(`AccountController::assertCanWrite()` / `ConfigManager::assertWritable()`),只认上面那两个条件。middleware 哪天被改漏,业务层也会拒——"中间件 + 业务层 + CLI"纵深防御。

## 登录链路

- **cookie 模型**:cookie 名 `scaffold_auth`,值 = AES-256 加密 + HMAC 签名的 JSON(含 `username` / `last_active` / `signature`)。`ScaffoldAuthenticate` 每请求解 cookie → 验签 → 反序列化;失败清 cookie 并 302 到 `/scaffold/login`(AJAX 返 `401 + X-Scaffold-Login` header),成功把 username 注入 `request->attributes['scaffold_auth_user']` 并滚动续签。
- **TTL**:`SCAFFOLD_AUTH_TTL_MINUTES`(默认 24h)。改 TTL 让旧签名失效是预期行为。
- **限流**:`/scaffold/login`(POST)挂 `throttle:5,1`(5 次/分/IP),第 6 次返 429。
- **时序对齐**:`ScaffoldAuth::attempt` 不论账号是否存在都跑一次 bcrypt(用固定 hash 占位),让"账号不存在"和"密码错"耗时一致,消除时间侧信道。

## CSRF

- `VerifyCsrfToken` 加在登录后所有路由组上,POST 表单必须带 `@csrf`,AJAX 带 `X-CSRF-TOKEN` header(`<meta name="csrf-token">`)。
- **故意豁免**:`/scaffold/csp-report` 是无状态 webhook,浏览器直接 POST 不带 cookie,只挂 `throttle:60,1`。

> runtime 错误 / 慢 SQL / todo 走云端([`16-cloud-push.md`](16-cloud-push.md)),其 intake 端点在 cloud 一侧凭 project token 鉴权,不属于本包路由面。

## CSP

`SecurityHeaders` 每请求生成一个 nonce(View 共享为 `$cspNonce`),所有 inline `<script>` / `<style>` 必须带 `nonce="{{ $cspNonce }}"`。完整策略:

```
default-src 'self'; script-src 'self' 'nonce-{nonce}';
style-src 'self' 'nonce-{nonce}' 'unsafe-inline'; style-src-attr 'unsafe-inline';
img-src 'self' data: blob:; font-src 'self' data:; media-src 'self' blob:;
connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self';
report-uri /scaffold/csp-report;
```

关键取舍:

- **script** 只信 `'self'` + nonce,去掉 `'unsafe-eval'`,Alpine 切 CSP build(`alpine-csp.min.js`),`x-data` 必须预注册在 `alpine-init.js`。
- **style-src-attr** 留 `'unsafe-inline'`,因 HTML 内联 `style="..."`(列宽 / display 等结构性)大量存在;XSS 经此能改样式但执行不了 JS。
- **frame-ancestors 'none'** 与 `X-Frame-Options: DENY` 双重防 clickjacking。
- **违规上报** POST 到 `/scaffold/csp-report`(`throttle:60,1`),记进 Laravel log。

## 其它防御头

| Header | 值 | 作用 |
|---|---|---|
| `X-Frame-Options` | `DENY` | 防 iframe 嵌入 |
| `X-Content-Type-Options` | `nosniff` | 防 MIME 混淆 |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | 跨站不漏 path |
| `Permissions-Policy` | `camera=() microphone=() ...` | 关闭一系列敏感 Web API |

## SSRF / path traversal

涉及外部输入的文件路径走两层防护:路由 `->where()` 严格正则约束 + store 入口 `preg_match` / `realpath` 收敛,non-match 直接 404 / 抛异常,防漂出根目录。当前包内的活样本是**开发文档中心**的 slug 校验(`DocsRepository`:`isValidSlug` + `realpath` 双层,slug 走 query/body 不进路由 path,避开 unicode 路由正则坑)。runtime / sql-slow 的桶存储已随监控云端化迁到 moo-monitor-laravel(本包只剩 `runtimes/{hash}` / `sql-slows/{hash}` 两条重定向桩路由,仍带 `->where('hash','[a-f0-9]{12}')`);todo 桶已整体移出 scaffold(Chrome 扩展直发云端)。

## token 类型分清楚

| 名字 | 谁签发 | 给谁用 |
|---|---|---|
| `scaffold_auth` cookie | `ScaffoldAuth` 加密(APP_KEY 衍生) | 浏览器登录态 |
| 云端项目 token(`moo_*`) | moo-scaffold-cloud「接入 Token」 | 云端 intake 鉴权(POST body `token` 字段) |
| CSRF token | Laravel Session | 表单防伪造 |

## 检查清单(新增写操作时跑一遍)

- [ ] 路由放在 `EnforceScaffoldWritable` 包裹的 group 里?
- [ ] controller 写动作前再 `assertCanWrite()` 一次(防绕过)?
- [ ] 表单带 `@csrf` / AJAX 带 `X-CSRF-TOKEN`?
- [ ] 外部输入做了类型校验 / 长度截断?
- [ ] 文件路径输入做了正则 / `realpath` 校验防 traversal?
- [ ] inline `<script>` / `<style>` 都带 `nonce="{{ $cspNonce }}"`?
- [ ] AJAX 失败调 `handleAuthError`(读 `X-Scaffold-Login`)而非吞错?

## 排错

| 现象 | 检查 |
|---|---|
| 点保存 403 | `APP_ENV` / `SCAFFOLD_CONFIG_READONLY`,看页面状态条 |
| 表单提交 419 | CSRF token 过期 / 缺失,刷新取新 token |
| Alpine 报"can not eval" | 用了非 CSP-safe 表达式,改方法名引用 |
| 登录后立刻跳回登录页 | cookie 没存上 → reverse proxy / cookie domain 配错 |
| 暴力试密码被卡 | 限流 5/min/IP,等 1 分钟或换 IP |
| `/scaffold/csp-report` 看不到违规 | Network 看是否发请求 / log 搜 `scaffold.csp.violation` |
