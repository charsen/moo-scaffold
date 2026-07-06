# 10 · 配置 UI

> 可视化编辑 `config/scaffold.php` 和 `.env` 的 scaffold 相关项,省得翻文件、记 key。仅开发环境可写,生产只读。

## 入口

| 路径 | 干什么 |
|---|---|
| `/scaffold/config` | 主页:单页 + 锚点 TOC,各分组字段表依次铺开 |
| `/scaffold/config/env` | `.env` 镜像页,**只看不改** |

`/scaffold/config/{group}` 是旧书签兼容,自动 302 到 `/scaffold/config#group-{key}`。

## 怎么改

1. 打开 `/scaffold/config`,在对应分组找到字段,改值。
2. 提交该分组表单(`POST /scaffold/config/{group}`)。
3. `ConfigManager::write()` 按字段 source 写回:
   - **`source: file`** → `PhpFileEditor` 写 `config/scaffold.php`,下次请求生效。
   - **`source: env`** → `EnvFileEditor` 写 `.env`,**必须重启 PHP 进程**(`php artisan serve` / `php-fpm reload`)`env()` 才读到新值。
4. 页面 flash 回该分组锚点,显示写入结果:`flash_message`(变更概述)、`flash_diff`(前后 diff)、`flash_skipped`(跳过的字段:校验失败 / 值未变 / 只读 / 不存在)、`flash_error`(异常)。改了 `.env` 额外提示重启进程。

> 字段定义在 `ConfigManager` 内部的 group → fields 映射(`path` / `source` / `env_key` / `type` / `label` 等),完整以源码为准。

## 写保护

POST 在以下任一情况被拒(403 + flash),`EnforceScaffoldWritable` 中间件 + `ConfigManager::assertWritable()` 双重防护:

1. **`APP_ENV=production`** — 生产一律只读。
2. **`SCAFFOLD_CONFIG_READONLY=true`** — 强制只读总开关(local 也只读)。

页面顶部显示当前状态(可编辑 / 强制只读 / 生产·只读)。详见 [12-security.md](12-security.md)。

## 敏感字段

`config_ui.sensitive_keys`(默认 `['PASSWORD', 'SECRET', 'KEY', 'TOKEN']`)按子串匹配 env 名,命中即认定 `sensitive`;也可在字段定义里显式写 `'sensitive' => true`。标了 `sensitive` 的字段在表单和 env 镜像页自动掩码,点"显示"才看明文。

## `.env` 镜像页

`/scaffold/config/env` 是**永远只读**的 .env 浏览器,展示全量内容(不限 `SCAFFOLD_*`),每行 Key / Value / 是否敏感,敏感项自动掩码。这页不提供编辑:已收录的 env 项改它走对应分组表单;未收录的原始行只能 SSH 改 `.env` 后重启进程。

## 历史回溯

不在 UI 里做(刻意不做"备份历史 / 修改前快照 / 撤销")。`config/scaffold.php` 入 git,看历史走 `git log -p -- config/scaffold.php`(`.env` 通常 gitignore,看部署机)。

## 排错

| 现象 | 检查 |
|---|---|
| 改了 .env 但 `env()` 还是旧值 | 没重启 PHP 进程,`php artisan serve` 重启 / `php-fpm reload` |
| 点保存报 403 | `APP_ENV=production` 或 `SCAFFOLD_CONFIG_READONLY=true`,看页面状态条 |
| 字段表单里没有想改的项 | 该字段未在 `ConfigManager` 中定义,需直接编辑 `config/scaffold.php` |
| 改了 `config/scaffold.php` 但 `config(...)` 还是旧 | `php artisan config:clear`(用了 config cache 时) |
| 主页空白只剩 TOC | `config_ui.enabled=false` |

## 关键 env

| Env | 默认 | 作用 |
|---|---|---|
| `SCAFFOLD_CONFIG_UI` | `true` | 功能开关(`config_ui.enabled`),关掉则主页空白只剩 TOC |
| `SCAFFOLD_CONFIG_READONLY` | `false` | 强制只读,拒所有写入 |

`SCAFFOLD_CONFIG_UI` 管"功能开不开",`SCAFFOLD_CONFIG_READONLY` 管"开了能不能写"。

> 账号管理 `/scaffold/accounts` 与 `/scaffold/config` 是两个独立模块,只共用左侧 sidebar 导航树。
