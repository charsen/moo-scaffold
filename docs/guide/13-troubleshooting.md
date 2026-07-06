# 13 · 排错合集

> 出问题先来这里:每条「症状 → 原因 → 解法」一行带过,详情看对应模块手册。

## 装包 / 启动

| 症状 | 原因 → 解法 |
|---|---|
| `php artisan list` 没 `moo:*` | 自动发现没生效 → `composer dump-autoload`;仍无则查 `composer.json` 的 `dont-discover` |
| `vendor:publish` 后没 `config/scaffold.php` | 没覆盖 → 重跑带 `--force`,核对 `--provider=` |
| `/scaffold` 404 | `route.enabled=false` 或 `route.prefix` 改了 → 查 `config/scaffold.php` |
| 登录后又跳回 login | cookie 没存上 → 查 reverse proxy / cookie domain |
| `moo:account:add` 建了还登不上 | 账号未启用 → 看 `enabled: true` + `role: admin` |

详见 [01-install.md](01-install.md) / [09-accounts.md](09-accounts.md)。

## 代码生成

| 症状 | 原因 → 解法 |
|---|---|
| 改了 schema 但生成器没反应 | **忘跑 `moo:fresh`**(头号坑,生成器读缓存不读 YAML) |
| 路由没插进 `routes/{app}.php` | `:insert_code_here:do_not_delete` 标记被删 |
| `moo:model -F` 没追加到 Seeder | `//:auto_insert_code_here::do_not_delete` 标记被删 |
| Trait 里手写的逻辑没了 | Trait/Enum 每次都覆盖 → 业务逻辑搬 Model |
| `moo:auth` / `moo:api` 没扫到 action | 控制器要有**真实 public function** + **真实路由指向** |
| 生产跑 `moo:*` 报 "only_in_local" | 设计意图(写类只在 local),不要绕开 |
| `moo:schema Foo/Bar` 报错 | 不支持多级目录,schema 名必须单一标识符 |

详见 [02-schema-codegen.md](02-schema-codegen.md) / [03-cli-reference.md](03-cli-reference.md)。

## 写操作被拒(403)

任意 `/scaffold/*` 的 POST 报 403,挨个查:

- `APP_ENV=production`?生产一律只读。
- `SCAFFOLD_CONFIG_READONLY=true`?强制只读。
- 写操作是不是绕过了 `EnforceScaffoldWritable` 路由组?

详见 [12-security.md](12-security.md)。

## 表单 / CSRF

| 症状 | 原因 → 解法 |
|---|---|
| 提交 419 | CSRF token 过期 → 刷新;或全局 `VerifyCsrfToken` 漏排除 scaffold 写路由 |
| AJAX 401 + `X-Scaffold-Auth: required` / `X-Scaffold-Login` | 登录过期 → 前端认这两个 header 跳 login |
| 登录 5 次后 429 | 路由级 throttle 5/min/IP → 等 1 分钟 |

## Alpine / 前端

| 症状 | 原因 → 解法 |
|---|---|
| 组件没反应 / Console 报 "can not eval" | CSP build 不许内联表达式 → 改成方法名引用 |
| 改了 `.js` / `.scss` 浏览器没生效 | vendor 副本没更新 → 下游 `pull.sh`(内含 `vendor:publish --tag=public --force`);SCSS 改后包内先 `npm run build:css` |
| 改了 Blade 没生效 | 罕见 → `php artisan view:clear` |
| CSS 缓存破坏失效 | DevTools 看 `<link>` 的 `?v=` 变了没,勾 Disable cache |
| 主题切换不响应 | `<html data-theme>` 切了吗?`main.js` 加载了吗? |

详见包内 `public/javascript/alpine-init.js` 顶部的 Alpine CSP build 约束。

## YAML / 数据

| 症状 | 原因 → 解法 |
|---|---|
| `/scaffold/*` 整模块 500 | 某 yaml 写坏 → `php -r '...Yaml::parseFile(...)'` 找坏文件 |
| 不知道哪儿坏 | `git log <file>` 找上一好版本 `git checkout` 回来(运行时数据全入 git) |
| `accounts.yaml` 多端冲突 / 账号被合并误删 | 多端同步 rebase 冲突时调 `moo:scaffold:merge-yaml` 合并;误删从 git log checkout 回来 |

详见 [09-accounts.md](09-accounts.md) / [11-sync.md](11-sync.md)。

## API 调试

| 症状 | 原因 → 解法 |
|---|---|
| 参数显示不全 | 方法签名没用 `$request` 命名 / 没装 FormRequest |
| `{id}` 没替换成真实值 | 对应表没数据,或 `model_ids.php` 缓存缺 |
| Authorization 一直空 | YAML `authorization: false`,或在 `exclude_actions` 里 |
| 历史抽屉只有自己的记录 | 抽屉是个人 localStorage,纯本机、不跨成员共享(设计如此) |

详见 [05-api-debugger.md](05-api-debugger.md)。

## Runtime / 慢 SQL

> ⚠ 采集 / 缓冲 / 推送整链由依赖包 **moo-monitor-laravel** 提供,下面的机制与 config 键(`max_open` / `daily_cap` / `mask_keys` / `dontReport` / Recorder)都在 `config/moo-monitor.php`(前缀 `MOO_MONITOR_*`),**不在** scaffold 的 config。

| 症状 | 原因 → 解法 |
|---|---|
| 业务报错但云端看不到 | 异常在 host `dontReport([...])`;或 Recorder 没接入;或没跑 `moo:cloud:push` |
| 本地缓冲显示"已满" | open 桶到 `max_open` → 云端 resolve 已知问题 / 跑 prune |
| 同一异常反复重推 | 高频复发未 resolve → `daily_cap`(默认 10)限同异常每天写盘次数 |
| 敏感信息出现在 trace | `mask_keys` 没匹配 → 加进 `moo-monitor.php` config |

> Runtime / 慢 SQL 本地仅作临时缓冲,查看在云端。详见 [16-cloud-push.md](16-cloud-push.md)。

## 设计器

| 症状 | 原因 → 解法 |
|---|---|
| 保存报 "baseline drift" | git HEAD 变了 → 刷新页面取新 baseline |
| 翻译报 "AI not configured" | `/scaffold/config → AI 配置` 没填 API Key(不走 `.env`) |
| 迁移 compact 报 "git_pushed" / "not in git repo" | 待合并迁移已 push 或非 git 仓库 → 确认无误走 force 兜底 |
| 字段改了没自动保存 | Network 看 POST `/scaffold/db/designer/{schema}/save`;Console 查 Alpine init |

详见 [04-db-docs-designer.md](04-db-docs-designer.md)。

## 同步

| 症状 | 原因 → 解法 |
|---|---|
| rebase 失败但无冲突文件 | 多为 delete vs modify → 看 `git status` 人工处理 |
| push 失败 | 下次 `--pull` 自动解决 |
| accounts 合并后消失 | 后写覆盖 → 翻 git 找回 |

详见 [11-sync.md](11-sync.md) §6。

## 完全没头绪时

1. **Laravel log** — `storage/logs/laravel.log` 搜关键词。
2. **moo-scaffold-cloud** — 看有没有自动捕获的异常(`moo:cloud:push` 后上云)。
3. **浏览器 Network + Console** — 前端问题九成在这两个面板。
4. **git 回滚** — yaml/config 坏了,`git log <file>` 找上一好版本 checkout。
5. **清缓存** — `php artisan view:clear` / `config:clear`。

实在不行,回到 README 的定位说明——确认你想做的事在不在 scaffold 设计范围内,有些功能是故意不做的。
