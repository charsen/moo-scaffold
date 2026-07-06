# 16 · 云端汇聚(moo-scaffold-cloud × moo-monitor-laravel)

> 把本地 **运行时错误 / 慢 SQL / Todos** 汇到 moo-scaffold-cloud 集中查看 + 处置。
>
> **采集 / 缓冲 / 推送 / MCP 整条链路由独立包 [moo-monitor-laravel](https://github.com/charsen/moo-monitor-laravel) 提供**(scaffold 的 composer 依赖自动带入;不用 scaffold 的 Laravel 项目也可单独装它接入云端)。scaffold 保留的是 UI 壳:`/scaffold/cloud` 推送控制台 + 首页「云端汇聚」面板 + 旧查看器 302 跳转。

## 模型一眼

```
宿主 app 捕获(reportable 异常 / QueryExecuted 慢查询)   ← moo-monitor-laravel 自动挂钩
   → 本地 yaml 缓冲(storage/moo-monitor/{runtimes,sql-slows}/…,自带 .gitignore)
   → moo:cloud:push(命令 / scheduler,解耦请求链路)
   → 云端 intake(按 project+hash upsert)→ 云端 UI 查看 + 推送后回收本地
Todos:Chrome 扩展【直发云端】,本包不接收。
```

- **解耦**:推送只在命令/调度里跑,云端宕机不拖慢宿主 app。
- **幂等 + 增量**:云端按 `(project, hash)` upsert;命令按 `meta.updated_at` 游标只推变化的。
- **本地回收**:推送成功后清 `resolved` 桶、`open` 留作聚合锚点(仅清 `local_retention_days` 天前的);`=0` 完全不回收(并存期用)。

---

## 1. 两类数据怎么采集

两类都自动落盘成本地 yaml,**同一错误 / 查询按 hash 聚合**(`count++`、刷新 `last_seen`,不新建文件;已 `resolved` 复发自动 reopen)。本地**无查看器**,访问 `/scaffold/runtimes`、`/scaffold/sql-slows` 自动跳云端。

**Runtime 错误** — Laravel `report()` 捕获的 reportable 异常自动落盘(trace + 触发源码片段 ±10 行 + 请求现场,敏感字段脱敏)。MonitorProvider 自动挂 reportable 钩子,宿主零接入(旧版手动接入可删,留着也不会双计)。
- 开关 `MOO_MONITOR_RUNTIME_ENABLED`(默认 `true`)→ `storage/moo-monitor/runtimes/{open,resolved}/<hash>.yaml`。

**慢 SQL** — 挂 `QueryExecuted` 的监听器,`耗时 > 阈值` 且未命中跳过规则的查询落盘(同步,PDO 不可入队列)。
- 开关 `MOO_MONITOR_SQL_SLOW_ENABLED`(默认 `false`),阈值 `MOO_MONITOR_SQL_SLOW_THRESHOLD_MS`(默认 100)→ `storage/moo-monitor/sql-slows/{open,resolved,deleted}/<hash>.yaml`。
- 跳过规则:`config/moo-monitor.php` 的 `sql_slow.skip_patterns`(子串匹配,任一命中即 skip)。

> 两类都有 `max_open`(默认 500,超了静默丢新条目)+ `daily_cap`(同 hash 每天写盘上限,默认 10,只计不写盘),env 前缀 `MOO_MONITOR_RUNTIME_*` / `MOO_MONITOR_SQL_SLOW_*`。通知(钉钉/企微/邮件)由云端按项目规则发。

---

## 2. 启用云端推送

**前提**:① 云端已部署到宿主可达的 URL(本机调试 `http://127.0.0.1:8000`,生产需公网/内网 + HTTPS,部署见 cloud 仓 `DEPLOY.md`);② 云端「接入 Token」页生成 token,勾 `runtimes` + `slow_queries`(Todos 走扩展,token 另勾 `todos`)。

宿主 `.env`:

```env
MOO_MONITOR_CLOUD_ENABLED=true
MOO_MONITOR_CLOUD_TOKEN=moo_xxxxxxxx               # 云端「接入 Token」生成
# MOO_MONITOR_CLOUD_URL=https://c.mooeen.com      # 默认值,自托管部署才覆盖
# 可选
MOO_MONITOR_CLOUD_LOCAL_RETENTION_DAYS=7           # 0 = 完全不回收(并存期)
MOO_MONITOR_CLOUD_SCHEDULE=true                    # 自动挂每分钟调度
```

`php artisan config:clear`(用了 config:cache 的话)让配置生效。

**怎么跑**:
- **自动**:`cloud.enabled + cloud.schedule` 为真 → MonitorProvider 把 `moo:cloud:push` 挂进 scheduler(每分钟、`withoutOverlapping` 10 分钟)。**宿主已跑 `schedule:run` 就零额外 cron。**
- **手动**:`php artisan moo:cloud:push`(`--dry-run` 只数不发 / `--all` 忽略游标全量重推)。

---

## 3. 从旧版 scaffold 升级(老宿主)

升级到当前监控链路时有三处变化:① env 改名 `SCAFFOLD_RUNTIME/SQL_SLOW/CLOUD_*` → `MOO_MONITOR_*`(**不做兼容回落**);② 本地缓冲从 `scaffold/{runtimes,sql-slows}`(base_path)移到 `storage/moo-monitor/`(自带 .gitignore,与 git 彻底解耦);③ `moo:cloud:adopt` 退役,由 `moo:monitor:migrate` 接班。

```bash
composer update charsen/moo-scaffold        # 自动带入 charsen/moo-monitor-laravel
# .env:SCAFFOLD_CLOUD_* → MOO_MONITOR_CLOUD_*(改名对照 migrate 命令会打印)
php artisan moo:monitor:migrate --dry-run   # 体检:旧 yaml / 游标 / .env 残留
php artisan moo:monitor:migrate             # 平移旧数据 + 游标(幂等,可重跑)
php artisan moo:cloud:push --dry-run        # 验证推送管道
```

> 同一异常的 hash 算法不变 → 云端记录无缝延续,不会出现重复条目。
> 旧 `scaffold/runtimes`、`scaffold/sql-slows` 目录若曾入 git,迁移后记得从 `.gitignore` 清掉旧条目并 `git rm -r --cached`(migrate 命令会提示)。
> **并存过渡**:先 `MOO_MONITOR_CLOUD_LOCAL_RETENTION_DAYS=0`(推云但不删本地),云端稳定后再调回 7。

---

## 4. 首页「云端汇聚」面板

数据上了云、本地无查看器,但开发者打开自己项目 `/scaffold` 首页时仍想一眼看到"几个未处理错误"。首页**回拉**一个云端只读汇总(三类统计 + 最近几条)直接展示。

- **零额外 token**:用 `.env` 里的 `MOO_MONITOR_CLOUD_TOKEN` 回拉(云端读端点 `POST /api/v1/summary` 挂 `project.token:runtimes`,提报 token 本就带)。
- **不拖累首页**:短超时(≤4s)+ 缓存(成功 60s / 失败 15s);云端宕机 / 未接入 → 面板空态或不出现,首页照常秒开。
- **何时出现**:`MOO_MONITOR_CLOUD_ENABLED=true` 且 URL + TOKEN 齐备。点面板深链到云端本项目;`/scaffold/cloud` 点「立即推送」成功后清缓存、首页即刷新。

> 纯只读展示,处置(解决/删除)统一在云端控制台。

---

## 5. 让 AI 直接处理云端异常 / 待办(`moo:cloud:mcp`)

moo-monitor-laravel 内置一个极简 [MCP](https://modelcontextprotocol.io) server(命令名沿用 `moo:cloud:mcp`),把本项目云端的 runtime 错误与团队待办以「工具」暴露给跑在本仓库的 AI(AI assistant / Codex 等)——让 AI 直接拿异常、在对应项目代码里把活干了。

**接入**(在任意装了 scaffold 或 moo-monitor-laravel 的项目根目录):

```bash
# AI assistant
your MCP client add moo-cloud -- php artisan moo:cloud:mcp
# 其它客户端:mcpServers 加一项 command="php" args=["artisan","moo:cloud:mcp"](cwd=项目根)
```

> 复用 `.env` 的 `MOO_MONITOR_CLOUD_URL` + `MOO_MONITOR_CLOUD_TOKEN`,零额外 token;token 即项目身份,自动锁定当前仓库对应项目;读/修复是交互动作,`MOO_MONITOR_CLOUD_ENABLED` 关着也能用。

之后 AI 即有六个工具:

| 工具 | 作用 | 入参 |
|---|---|---|
| `list_open_runtimes` | 列待处理异常(默认 open + in_progress,最近优先) | `limit`(1–50,默认 20)、`status` |
| `get_runtime` | 取单条完整上下文(`exc_file:line` + 源码 + 调用栈)+ markdown | `hash`(必填)、`with_payload`(默认 false) |
| `resolve_runtime` | 修复验证后标记「已解决」闭环 | `hash`(必填)、`note`、`resolved_by` |
| `list_open_todos` | 列可处理待办(测试/产品经扩展提报的 bug 单) | `limit`(1–50,默认 20)、`status` |
| `get_todo` | 取单条待办上下文(描述/失败请求/JS 错误/时间线)+ markdown | `id`(必填) |
| `update_todo_status` | `in_progress` 认领 / `done` 闭环(幂等保留既有记录) | `id`、`status`(必填),`note`、`by` |

典型用法:「修掉 cloud 上没修的异常」→ `list_open_runtimes` 挑一条 → `get_runtime` 拿源码 → 改本地代码 → 你 review → `resolve_runtime` 回写。

> **隐私**:`get_runtime` 默认不返回请求入参(可能含密码/token),需要时显式 `with_payload=true`。
> **路径**:`exc_file` 是宿主服务器绝对路径,但后缀(`app/…`)稳定 + 带源码片段,AI 可 grep 定位。
> **stdout 洁净**:MCP 走 stdio,本命令只往 stdout 写 JSON-RPC;宿主启动期别往 stdout 打东西。

---

## 排错

| 现象 | 排查 |
|---|---|
| 启用了但云端列表空 | `*_ENABLED=true` 且 `config:clear` 过 / 阈值太高 / SQL 全在 skip_patterns / 没跑 `moo:cloud:push`(scheduler 没起) |
| 本地看不到 yaml | `storage/moo-monitor/{runtimes,sql-slows}/{open,resolved,deleted}/` 目录是否存在 + 有写权限 |
| 升级后推送"未启用/未配置" | env 还是旧名 `SCAFFOLD_CLOUD_*` → 改 `MOO_MONITOR_CLOUD_*`(push 命令检测到旧名会提示) |
| `cloud 推送未启用` | `MOO_MONITOR_CLOUD_ENABLED` 没真 / 配置缓存没清 |
| `URL / TOKEN 未配置` | token 必配;URL 默认 `https://c.mooeen.com`,生产别配成访问不到的地址(别用 `127.0.0.1`) |
| MCP 报 `… 未配置` | `moo:cloud:mcp` 要 URL + TOKEN(**不**需 `ENABLED`);确认这俩 env 在该仓库可见 |
| MCP 连不上 / 协议解析错 | 宿主启动期有东西打到 stdout 污染 JSON-RPC。`php artisan moo:cloud:mcp < /dev/null` 看 stdout 是否有杂输出 |
| `HTTP 401/403` | token 无效 / 缺能力(runtimes / slow_queries) |
| `HTTP 422`(每批都失败) | `MOO_MONITOR_CLOUD_BATCH`(默认 100)超过云端 `intake.max_records`(默认 200)→ 调 batch ≤ max_records |
| 推了但本地没回收 | `local_retention_days=0` / 刚推的 open 还没过 N 天 |
| `git status` 还在冒 yaml | 旧 base_path 时代的历史文件仍被跟踪 → `git rm -r --cached scaffold/runtimes scaffold/sql-slows` |
