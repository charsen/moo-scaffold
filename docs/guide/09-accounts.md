# 09 · 账号管理

> 谁能登录 `/scaffold`,以及账号怎么增删改启停。**首个账号走 CLI,其余全走 Web UI**。

## 账号存哪

```
scaffold/accounts.yaml   # 主存储,跟 git 走(多端共享)
```

```yaml
meta:
  count: 3
  updated_at: 2026-05-15 10:23:45
  updated_by: charsen

accounts:
  charsen:
    username: charsen
    password: $2y$10$...      # bcrypt
    phone: '13800138000'
    role: admin               # admin 或 member
    enabled: true
    created_at: 2026-04-01 09:00:00
    updated_at: 2026-05-15 10:23:45
```

> 鉴权用 cookie session(`scaffold_auth`,AES-256 + HMAC)。

## 1. 建第一个账号(CLI 唯一保留命令)

```bash
php artisan moo:account:add charsen --password=xxx --role=admin
```

跨过"UI 要登录、登录要账号"的鸡蛋问题。其他参数:

| Flag | 说明 |
|---|---|
| `--password=` | 不传会交互式 prompt |
| `--phone=` | 手机号(可选) |
| `--role=` | `admin`(默认)或 `member` |
| `--disabled` | 创建后立即禁用 |
| `--by=` | 创建人,默认 `posix_getlogin()`,取不到回落 `cli` |

## 2. 之后全走 Web UI:`/scaffold/accounts`

| 操作 | HTTP 路由 | 行为 |
|---|---|---|
| 新增 | `POST /scaffold/accounts` | 填用户名 / 密码 / 角色 |
| 改密 / 改角色 | `POST /scaffold/accounts/{username}` | 密码框留空 = 不改,填新值 = 重置 |
| 启停 | `POST /scaffold/accounts/{username}/toggle` | 停用后无法登录,不删记录 |
| 删除 | `POST /scaffold/accounts/{username}/delete` | 带兜底拦截(见下) |

> 没有 CLI 列表 / 编辑命令(刻意):UI 已够用,维护两套是过度设计。

## 兜底规则

代码里硬拦的三条(绕过它们没人能登回来),不做备份历史 / 改密前快照 / 操作审计——历史回溯走 git:

- **不能删自己 / 不能停用自己** — `username === $me` 直接挡(`AccountController`)
- **不能删最后一个启用 admin** — `AccountStore::delete` 计数兜底

## 写保护

`/scaffold/accounts` 所有 POST,下面任一命中即拒(由 `AccountController` + `EnforceScaffoldWritable` 双重把守):

1. `APP_ENV=production`(生产一律只读)
2. `SCAFFOLD_CONFIG_READONLY=true`(强制只读总开关)

完整安全模型见 [12-security.md](12-security.md)。

## 多端同步

`accounts.yaml` 随 git 同步,多机同时改按**行级 last-write-wins**(`ScaffoldMergeYamlCommand`):双方都有的账号取 `updated_at` 较新版本,`meta.count` 重算,`updated_by` 写 `sync:auto-merge`。

> 合并按 username 取并集、无删除墓碑——"一边删一边改"时删除会被复活,真要删,合并后再删一次。详 [`11-sync.md`](11-sync.md) §4。

## 排错

| 现象 | 检查 |
|---|---|
| "账号或密码错误"但确信没错 | 该账号 `enabled: false`?角色不对? |
| 登录后立刻跳回登录页 | cookie `scaffold_auth` 没存上,reverse proxy 漏 cookie? |
| 改 `SCAFFOLD_AUTH_TTL_MINUTES` 后旧 session 失效 | 正常,重登即可 |
| YAML 损坏导致 500 | `git log -- scaffold/accounts.yaml` checkout 上一个好版本 |

> 老明文密码:**下次登录成功时**自动 bcrypt 化,零干预。误删账号从 `git log -- scaffold/accounts.yaml` 找删除前 commit,把对应行 checkout 回来。
