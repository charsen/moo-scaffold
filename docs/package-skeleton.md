---
title: 扩展包骨架规范
group: 扩展包
order: 20
tags:
---
# 扩展包骨架规范

本文件是 moo 系扩展包**仓库骨架**的唯一真值源：一个包该有哪些文件、目录、命名与红线偏离怎么判。
可执行判据是 [`../tools/audit-package-structure.php`](../tools/audit-package-structure.php)（只读、零依赖）。

> **基准是现况，不是文档。** canonical 范本为 `moo-system`（首选）与 `moo-<name>`。
> 本文件与任何 skill 文本、历史 plan 冲突时，以**当前代码**为准；发现不一致先归因，别机械选边。

## 用法

```bash
# 人工可读报告（默认扫本仓父目录＝同级目录，可省略 --workspace）
php tools/audit-package-structure.php --workspace=<同级目录>

# 只看一个包 / 机器读
php tools/audit-package-structure.php --workspace=<同级目录> --package=moo-<name>
php tools/audit-package-structure.php --workspace=<同级目录> --json

# 闸门：有未豁免的 MISS / STYLE-DRIFT 时退出码 1
php tools/audit-package-structure.php --workspace=<同级目录> --fail-on-drift
```

脚本自动识别目标：`moo-*` 目录中 `type=library` 且包名为 `charsen/moo-*` 的按**扩展包**审，
`moo-engine-skeleton` 按**host**审（三份 manifest 分流 + 接入面），其余（应用 / 无 composer.json /
脚本 `DEFERRED_TARGETS` 里的开发中仓）跳过并**列出理由**（见「维护」）。

## 严重级

| 级 | 含义 | 会让 `--fail-on-drift` 失败 |
| --- | --- | --- |
| `MISS` | 必需文件 / 目录缺失（豁免清单命中则不计） | 是 |
| `STYLE-DRIFT` | `pint.json` 规则集与 canonical 不一致；`CLAUDE.md` 不是纯入口（未指向 `AGENTS.md` 或超过 8 行） | 是 |
| `CONFIG` | 机器可判、且会**实际坏事**的配置口径：① path 仓库 `versions` 写成约束式（无 lock 的 fresh install 被 Composer 拒绝 ⇒ CI/新克隆装不上）；② `.gitattributes` 缺基准 `export-ignore` 条目（产物随 dist 进消费方 `vendor/`） | 是 |
| `LAYOUT` | 布局红线偏离（trait 放错位置、Requests 出现模块段） | 否，只报告 |
| `NAME` | config stem / 命名空间 / provider 类名偏离命名约定 | 否，只报告 |
| `OPTIONAL` | CI 骨架与 `.gitattributes` **是否存在** | 否，只报告 |
| `INFO` | 观察项（空骨架、host 发布状态等） | 否 |

**为什么这三级判失败**：`MISS` / `STYLE-DRIFT` / `CONFIG` 都是「机器能判、且不修就真会坏事」——
前两者是骨架与规则集本身，`CONFIG` 的两条分别是 **fresh clone/CI 装不上** 与 **产物泄漏进消费方**（都实测踩过）。
而 `LAYOUT` / `NAME` 的改动会破坏 namespace 或跨 host 契约（消费方要同步升级），
`OPTIONAL` 的收益取决于分发方式（是否走 composer dist、GitHub 镜像是否启用）。这三类都需先确认设计意图。

**`CONFIG` 的两条判据边界**（都在脚本里，改判据要同步本节）：

- **path 仓库 `versions` 只比语法**：值必须是具体版本（`2.2.8`），出现 `^ ~ * > < = @ |` 或空格即报。
  `repositories` 的 list / dict 两种形态都支持（生态里两种都有，脚本都吃）。
- **`.gitattributes` 只比「必需条目是否齐」**：不比整份文件、**只在该路径于本仓确实存在时才要求**
  （`moo-scaffold` 没有 `.claude`/`.editorconfig`/`.phpunit.cache`，就不要求它去 ignore）。
  逐仓的**额外**条目是刻意的（`moo-<name>` +`/tools`、`moo-system` +`/HANDOFF.md`、`moo-<name>` +`/.codegen`），不算偏离；
  「有意随包分发」的例外走脚本 `ALLOWANCES` 的 `gitattributes:<path>` 键（如 `moo-scaffold` 的 `docs/`——
  它是 host 文档中心的包文档源）。host（`moo-engine-skeleton`）有自己的清单，不套本判据。

## 必需清单

```
composer.json  pint.json  .gitignore  README.md  CHANGELOG.md
AGENTS.md  CLAUDE.md  NOTES.md  TODOS.md  phpunit.xml
config/moo-<stem>.php     routes/admin.php（含插入标记）  docs/overview.md
scaffold/database/<X>.yaml
lang/zh-CN/  lang/en/     database/migrations/（日期命名）
src/<Provider>.php        tests/{TestCase.php,Pest.php}
src/Models/{Traits,Filters}/
src/Http/{Controllers/Admin,Requests,Resources}/
```

几个易错点：

- **项目记忆文件名是大写 `NOTES.md`**，全仓不存在小写 `notes.md`（部分 skill 文本里的 `notes.md` 是旧写法）。
- **`CLAUDE.md` 只作入口、不复制规则**：全部规则写进 `AGENTS.md`，`CLAUDE.md` 只指明去读它（≤ 8 行）。
  理由：本工具与 Codex 只读 `AGENTS.md` 层级，写在 `CLAUDE.md` 里的规则对它们不可见 —— 复制等于分叉出第二份真相。
- **`AGENTS.md` 只写本仓特有约束**，不重复全局 `~/.agents/AGENTS.md`（它每次请求自动载入）：提交 / 推送 / 打 tag / 发布的授权、
  敏感信息禁令、`NOTES.md` 记法纪律、时间字段 nullable、Schema-first 通用部分、E2E 与浏览器验证授权等，都不要在本仓重复展开。
- `routes/admin.php` 必须含 `// :insert_code_here:do_not_delete` —— 删了 codegen 插路由就断。
- `config` 文件名、config 命名空间、publish tag、后台中间件组统一用 `moo-<name>` stem。
- **model trait 一律放 `src/Models/Traits/`**（`namespace ...\Models\Traits`），不放 `Models/Concerns/` ——
  codegen 硬编码 emit `use {base_namespace}Traits\...`，放错位重生成时 model 的 `use` 行会断。
- `src/Http/Requests/<Controller>/` 按控制器分组；`Concerns/` 是跨控制器复用的 Request trait，不算模块段。
- **基础设施三件套从 scaffold 共享，包不自持**：雪花主键 `Mooeen\Scaffold\Concerns\UsingSnowFlakePrimaryKey`、
  Filter 基类 `Mooeen\Scaffold\Foundation\BaseFilter`、翻译合并器 `Mooeen\Scaffold\Translation\MergingLoader`。
  包内不得有 `registerSnowflake`，config 不得有 snowflake 段。
- `pint.json` 与 `moo-system` 的**规则集语义一致**（缩进差异属纯格式，报 `STYLE-DRIFT` 时按需归一）。

## 豁免清单

残缺骨架 ≠ 待补。下列包按定位本就只具备子集，脚本据此不报 `MISS`：

| 包 | 豁免 | 理由 |
| --- | --- | --- |
| `moo-contract` | 无路由 / 配置 / provider / lang / 迁移 / Eloquent 层 / Laravel 测试宿主 | 纯契约包，**零框架依赖**，只放接口 |
| `moo-<name>` | 无 provider / 配置 / 路由 / lang / 迁移 / Eloquent 层 | 状态机内核；领域定义与装配留 host |
| `moo-monitor-laravel` | 无路由 / `scaffold/database` / lang / 迁移 / CRUD 层 | 采集上报包，无自身业务表 |
| `moo-scaffold` | 无 `routes/admin.php` / `scaffold/database` / lang / 迁移 | codegen 工具本身，后台是 `/scaffold/*` |
| `moo-<name>` | 无迁移、无 `Models/Filters` | 已初始化未落地：空骨架，无真实表设计 |
| `moo-<name>` | 无 `tests/TestCase.php`、`Pest.php` | 测试走 `tools/bootstrap.php` 驱动的形态 |
| `moo-<name>`、`moo-upload` | 无 `Models/Filters`、`Http/Resources` | 轻控制器包：无列表筛选，直接返数组/DTO |
| `moo-<name>`、`moo-<name>` | 无 `Http/Resources` | 同上 |

**新增豁免必须写明理由**，并同步脚本里的 `allowances()` —— 它是数据，不是散落的 if。

## 与 `AGENTS.md` 的关系

包级 `AGENTS.md` 管「这个包怎么干活」，本文件管「这个包的仓库形状」。两者不重复条款：
形状相关的硬约束（trait 位置、三件套来源、生成区边界）在两边各出现一次是刻意的，
因为它们分别是**人读的规则**与**机器判的判据**，任一侧失效都要能被另一侧发现。

## 维护

- 改 canonical 判据（新增必需项、调整豁免）→ 同步改脚本 `requirements()` / `allowances()` 与本文件。
- **`CONFIG` 两条判据**：改基准 dist 裁剪清单 → 同步脚本 `exportIgnoreRequirements()` 与本文件；
  新增「有意随包分发」的例外 → 写进 `allowances()` 的 `gitattributes:<path>` 键并写明理由。
  改完**必做咬合力验证**：故意去掉一条 `export-ignore` / 把某个 `versions` 改回约束式 → 确认报 `CONFIG` 且退出码 1 → 精确还原。
- 基准包（`moo-system` / `moo-<name>`）自身形状变化时，脚本的 `CANONICAL_PACKAGES` 与上面的说明要一起复核。
- **开发中的仓整仓剔除**走脚本的 `DEFERRED_TARGETS`（值里写理由 + **移除条件**）：它只决定「扫哪些仓」，
  不改变任何必需项判据；理由会随报告「跳过」段与 `--json.skipped` 打印，**不允许静默跳过、也不允许借它压掉真实偏离**；
  显式 `--package=<name>` 仍照常单查该仓。
- 已知的布局 / 命名待议项（`LAYOUT` / `NAME`）登记在 `../TODOS.md`，**不在本文件承诺**。
