# 04 · 数据库设计器(Designer)

> `/scaffold/db/designer` 可视化改 schema(自动生成 migration)。`/scaffold/dictionaries` 看字典(按模块章节展开所有枚举,锚点导航,找枚举值就来这)。**写类操作 dev 专用**,production / readonly 下整页只读(红 banner + 写按钮全灰)。

## 入口速查

| 路径 | 干什么 |
|---|---|
| `/scaffold/db/designer` | 总览:创建 / 改名 / 删 schema、模块切换 |
| `/scaffold/db/designer/{schema}` | 单 schema 设计页(左 sidebar 表列表 + 右主区:基础信息 / 字段 / 索引 / 枚举 / migration 历史) |
| `/scaffold/dictionaries` | 字典页:按模块章节展开所有枚举 |

## 改 schema → 上 DB(主流程)

1. 进 `/scaffold/db/designer/{schema}`,左 sidebar 选表(或"新建表")。
2. 字段表里改字段 / 索引 / 枚举 —— **无保存按钮**,改完 500ms debounce 自动 POST `/save`,sub-nav 上 `saveStatus` 更新(失败弹 toast)。
3. 点"预览"(`/preview`):后端跑 diff + 生成 migration PHP 源码,右栏抽屉显示,**不写盘**。
4. 点"migrate"(`/migrate`):真写 `database/migrations/{ts}_xxx.php` + 推进 `.snapshots/` baseline。
5. **GUI 不自动 git commit**,你手动 `git add + commit`(模板见 [`14-multi-dev-workflow.md`](14-multi-dev-workflow.md) §五)。

> 字段名前缀 strip / 拼写检查 / 中文→字段名翻译 / 批量加字段都有行内 AI 按钮,走 DeepSeek(配置见下文)。

## 创建 / 改名 / 删 schema · 创建 / 删表

| 操作 | 入口 | 范围 |
|---|---|---|
| 创建 schema | 总览页"新建模块" | 只写新 yaml + storage cache,不 migrate |
| 改名 schema | schema hero ✏ | 文件名 + yaml `module.name` 一起改 |
| 删 schema | schema hero × | **草稿态** — 只删 yaml,**不**删 migration / DB |
| 创建表 | sidebar"新建表" | 写新 table 段,带默认 id / 时间戳字段 |
| 删表 | 表 hero × | 从 yaml 移除该表段,不动 migration / DB |

> 删表 / 删 schema 都是**草稿态删**(只动 yaml,不 emit drop migration)。要彻底删 DB 里的表:designer 标删 → 手写 drop migration → migrate → 再删 yaml。

## 字段索引 dropdown

| 选项 | yaml 表达 | 行为 |
|---|---|---|
| — | (无) | 无索引 |
| primary | index 块 `type:primary` | 系统字段,只读 |
| unique(app · 软删过滤) | `unique: true`(field attr) | Request 验证 `Rule::unique()->whereNull('deleted_at')`,migration **不**加 DB unique |
| unique(DB · 强约束) | index 块 `type:unique` | Request 验证 Laravel 默认 unique,migration `$table->unique()` |
| index | index 块 `type:index` | 普通查询索引 |

复合索引(`UNIQUE KEY (a, b)`)在右主区"索引"卡片独立编辑(名称 + 类型 + 字段数组)。yaml `unique: true` 语义详 [`yaml-style.md`](../yaml-style.md) §三。

## Migration 历史合并(compact)

开发期同表累积 1 create + N update,deploy 前可一键合并 → 1 create(filename 不变,本地 DB 不需重 migrate)。入口:"Migration 历史"卡片底部"合并",两段式(预览 `/compact-preview` → 执行 `/compact`)。

**3 个兜底拒合并**:

| 兜底 | 触发 |
|---|---|
| rename / drop 拒 | update 历史里有 renameColumn / dropColumn(合并丢 history,prod 节点无法回滚) |
| schema drift warn | yaml 跟现存 migration 期望状态不一致(yaml 是 source of truth) |
| git push hard refuse | 当前分支已 push 到 origin(改不动,会破坏其它机器 git 历史) |

## 删 migration 文件

清理测试 / 误生成的 migration。前提:**migrations 表里没该 record**(prod 没跑过)。入口:Migration 历史卡片 → 单行右侧 × → 二次 modal 确认(可选勾"清表 baseline 让 designer 重生成")。

## AI 翻译配置(DeepSeek)

在 **`/scaffold/config` → AI 配置** 页面可视化填写(**不走 env**)。存 `scaffold/ai.yaml`,运行时读 yaml、**改完即时生效、无需重启**,`config:cache` 安全。要清空 key 直接删该文件。生产 / 只读环境只读。**⚠ api_key 明文写入该文件；公开业务仓不要提交 `scaffold/ai.yaml`**。

| 字段 | 默认 | 作用 |
|---|---|---|
| API Base URL | `https://api.deepseek.com/v1` | OpenAI 兼容上游 |
| API Key | `''` | **必填** |
| 模型 | `deepseek-chat` | |
| 超时(秒) | `10` | 单次请求总超时 |
| 连接超时(秒) | `8` | 建立连接超时 |
| 最大 Token | `8192` | 单次生成上限 |
| 采样温度 | `0.2` | 越低越稳,中文→snake_case 建议 0.2 |

## baseline drift 守护

`.snapshots/{Schema}.yaml` 是 designer diff 的参照系,每次 `moo:migration` / designer migrate 成功后自动推进。

| 场景 | 行为 |
|---|---|
| baseline 文件不存在(新 schema / 新机器没 pull) | 整模块红 banner — 跑 `moo:snapshot:init --schema=X` 或 git pull |
| baseline 缺某表段 + DB 已有该表 | 该表 banner — `git checkout HEAD -- scaffold/database/.snapshots/X.yaml` 复位 |
| baseline 跟 yaml 一致 / 漂移 | 无 diff / 走正常 preview → migrate 流程 |

跨设备 / 多人协同详 [`14-multi-dev-workflow.md`](14-multi-dev-workflow.md)。

## 删字段的反向依赖警告

删字段时 SchemaDiffService 扫整个 codebase 查引用,按"自动 vs 手动清"分组:

- **AUTO**(`*Trait.php` / 重生成被覆盖的)— 不用动,下次 `moo:fresh` 自动清
- **MANUAL**(`*Model.php` / `*Filter.php` / Request / Resource / TS)— 手动改;GUI 给整组 vim 命令一键复制

## 排错

| 现象 | 检查 |
|---|---|
| 报 "baseline drift" | git HEAD 变了 / 同事 push 了新 migration 你没 pull → [14](14-multi-dev-workflow.md) |
| baseline 文件不存在 banner | 新机器 / 新 schema → `php artisan moo:snapshot:init --schema=X` |
| AI 按钮报 "AI not configured" | `/scaffold/config` → AI 配置 没填 API Key |
| AI 报 "upstream error" | DeepSeek 限流 / key 失效 / 网络,看 `storage/logs/laravel.log` |
| 字段改了没自动保存 | 看 Network 有没 POST `/save`,Alpine 出错查 Console |
| migration 合并按钮变灰 | 三兜底之一触发,看 hover 提示 |
| 删 migration 提示 "已执行" | migrations 表有记录 → 先 `php artisan migrate:rollback` 再删 |
| 字典页空白 | schema 没定义 `enums` 或 `moo:fresh` 没解析到 |
| 切 unique-app/db 刷新又变回 | yaml legacy 双写 — 详 [yaml-style.md](../yaml-style.md) §三 |
| Production banner + 灰按钮 | 设计意图,生产只读(`APP_ENV=production` 或 `scaffold.config_ui.readonly=true`) |
