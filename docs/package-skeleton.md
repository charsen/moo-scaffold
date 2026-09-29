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
| `CONFIG` | 机器可判、且会**实际坏事**的配置口径：path 仓库 `versions` 写成约束式（无 lock 的 fresh install 被 Composer 拒绝 ⇒ 干净克隆装不上） | 是 |
| `LAYOUT` | 布局红线偏离（trait 放错位置、Requests 出现模块段） | 否，只报告 |
| `NAME` | config stem / 命名空间 / provider 类名偏离命名约定 | 否，只报告 |
| `OPTIONAL` | GitHub Actions（**仅开源仓**需要）与 `.gitattributes` 裁剪清单 | 否，只报告 |
| `INFO` | 观察项（空骨架、host 发布状态等） | 否 |

**为什么 `MISS` / `STYLE-DRIFT` / `CONFIG` 判失败**：它们都是「机器能判、且不修就真会坏事」——
前两者是骨架与规则集本身，`CONFIG` 目前只有一条（**干净克隆装不上**，实测踩过）。
而 `LAYOUT` / `NAME` 的改动会破坏 namespace 或跨 host 契约（消费方要同步升级），
`OPTIONAL` 的两项**当前收益接近零**（见下）。这三类都需先确认设计意图。

**`CONFIG` 判据边界**（在脚本里，改判据要同步本节）：

- **path 仓库 `versions` 只比语法**：值必须是具体版本（`2.2.8`），出现 `^ ~ * > < = @ |` 或空格即报。
  `repositories` 的 list / dict 两种形态都支持（生态里两种都有，脚本都吃）。

**`OPTIONAL` 为什么只报不判**（2026-09-28 用户确认的生态事实 + 实测）：

- **开源集合**（2026-09-28 用户确认 + 实测 GitHub 侧匿名可见）：**包**只有 `moo-feedback` / `moo-scaffold`；
  非包目标还有 `moo-engine-skeleton`（host 骨架）、`moo-chrome-dev-tool`、`moo-git-fleet`、`moo-monitor-vue`。
  其余包**私有、没有 GitHub 镜像**（曾定开源、后来撤销）。⇒ 私有仓的 `.github/workflows/` **永远不会跑**
  （写了也没用），所以脚本只对那两个开源**包**要求它（host 走 `auditHost` 路径，不套本条）。
- **开源仓走 Gitee + GitHub 双源直推**，不再用定时镜像：原先 5 份 `mirror-from-gitee.yml`
  （cron 每 6 小时 ——`GITEE_TOKEN` clone 一份 bare、`MIRROR_GITHUB_TOKEN` `push --mirror`）**已全部删除**；
  双源少两个长期 token 的暴露面，也没有最长 6 小时的同步滞后。
- **`.gitattributes` 的 `export-ignore` 对私有包不生效**：Composer 对 Gitee **没有 dist driver**，
  依赖一律 `git clone`（实测 `vendor/composer/installed.json`：私有包 `dist=False source=True`、
  有 `.git`；只有 Packagist 上的公开包是 `dist=True`）。所以「补 `/plans`、`/TODOS.md` 到裁剪清单」
  只对**从 Packagist 装 dist 的公开包**有意义。文件保留无害（将来开源 / 切 dist 即生效），
  但判据降级为只报告，免得守一个当前无效的机制。
- `.gitattributes` 的检查口径本身仍然有效：只比「必需条目是否齐」、**只在该路径本仓确实存在时才要求**
  （`moo-scaffold` 没有 `.claude`/`.editorconfig`/`.phpunit.cache` 就不要求）；逐仓的**额外**条目是刻意的
  （`moo-<name>` +`/tools`、`moo-system` +`/HANDOFF.md`、`moo-<name>` +`/.codegen`），不算偏离；
  「有意随包分发」的例外走脚本 `ALLOWANCES` 的 `gitattributes:<path>` 键（如 `moo-scaffold` 的 `docs/`）。
  host（`moo-engine-skeleton`）有自己的清单，不套本判据。

## 清单双轨（包仓的本地 / 干净克隆分流）

包仓只有一份 `composer.json`，而「本地要 `path`+`symlink`、干净克隆要能脱离同级目录装」是**互斥**的 ——
`path` 条目是**急切校验**的：目录不存在时 Composer 直接报
`The url supplied for the path (../moo-xxx) repository does not exist`，**vcs 兜底根本轮不到**
（2026-09-28 实测；这也是「vcs 兜底 + 保留 path」不可实现的原因）。因此用**双清单**：

| 文件 | 用途 | `repositories` |
| --- | --- | --- |
| `composer.json` | **本地默认**（跨包联调、日常测试） | `path` + `symlink` |
| `composer.ci.json` | 干净克隆 / CI / 发布 | **纯 vcs** |

- 干净目录跑：`COMPOSER=composer.ci.json composer update`。两份清单**各有自己的 lock**（lock 名跟随清单名：`composer.json` → `composer.lock`、`composer.ci.json` → `composer.ci.lock`），切换后要各自解析一次
  （lock 不入库，CI 每次自行解析，故 CI 侧不受影响）。
- `composer.dev.json` 必须进 `.gitattributes` 的 `export-ignore`（`CONFIG` 判据会查，见上）。
- **vcs 清单里公开包也要列**：`charsen/moo-scaffold` / `charsen/moo-monitor-laravel` 虽在 Packagist 上，
  但 **scaffold 的镜像只到 2.2.1**（Gitee 已有 2.2.8）⇒ 依赖 `^2.2.7/^2.2.8` 的包靠 Packagist 必失败
  （2026-09-28 实测：4 个包的干净目录失败全是这一条）。SSH 本来就要（私包全 SSH），不多背包袱。
  其余私包一律 `git@gitee.com:charsen/<name>.git`。
- **`repositories` 必须覆盖「传递私包闭包」**：Composer 只用**根包**的 repositories ⇒ 依赖的依赖
  （如 `moo-<name> → moo-<name>`）也要由本包声明，否则干净目录解析不到（`process-application` 原先就如此）。
- 提醒：切换清单后若 lock 与清单不符，`composer install` 会报错 —— 重跑一次 `composer update` 即可
  （lock 不入库，所以只在本地发生）。

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
  显式 `--package=<name>` 仍照常单查该仓。**当前该清单为空** —— `moo-<name>` 于 2026-09-28 首次 commit 落地后，
  按它自己那条移除条件纳入审计（它缺的不是「开发中」而是基建包豁免：已补 `allowances()` 与其 `CLAUDE.md`）。
- 已知的布局 / 命名待议项（`LAYOUT` / `NAME`）登记在 `../TODOS.md`，**不在本文件承诺**。

## 布局与命名的登记例外（2026-09-29 收口）

`LAYOUT` / `NAME` 是**判断题**（改了会破坏 namespace 或跨 host 契约），所以判据支持「**登记例外**」而不是长期报红。
当前**偏离已清零**（`LAYOUT 0 / NAME 0`），此前那批按下面的方式收口：

- **model trait 目录**：一律 `src/Models/Traits/`（`namespace ...\Models\Traits`）。这条已按**跨仓迁移**执行：
  定义包先改（`git mv` + 命名空间），消费方随后跟进 `use`；**发布顺序**必须是「定义包先发、消费包后发」，
  否则消费包会 `use` 到不存在的类。迁移**不牵动 codegen** —— 本仓 `src/`、`stubs/` 里没有 `Models/Traits|Concerns`
  的硬编码（被硬编码的是 **controller** trait，见 `CreateControllerGenerator`），所以这条是纯规范条文。
- **命名空间 / provider 类名**：按包名推导 —— `moo-<stem>` → `Mooeen\<Ucfirst 去连字符的 stem>\`，
  provider 为 `<Prefix>ServiceProvider`，psr-4 target 带尾斜杠 `src/`。改名是**破坏性变更**：
  需同步消费方（`use`、`bootstrap/providers.php`、composer `extra.laravel.providers`）与 host 侧
  `extra.moo-private-packages[].provider-rel`（该字段由 `ComposerProfiles::problems()` 校验，指错会当场报红）。
- **config stem 例外**（判据键 `name:config-stem`，写在 `allowances()` 里并附理由）：允许「刻意的共享产品 stem」
  与「全生态宿主的既有契约」两种情况登记豁免（如与姐妹前端包同源的 stem、以及宿主遍布 `config('<stem>.*')` 读取点的 stem）。
  登记后该仓不再报 `NAME`，理由随报告打印。
- **Requests 模块段例外**（判据键 `src/Http/Requests/<Segment>`）：默认按 `<Controller>/` 分组；
  **按业务域组织**（一个目录跨多个控制器、且经确认是刻意的）可登记豁免，键为 `allowances()[<pkg>]` 的目录路径。
  ⚠ 未开源包的豁免条目放**不入库**的私有覆盖文件，公开仓只保留公开包的条目与机制说明。
- **`layoutCheck` 的控制器名扫描**同时覆盖 `src/Http/Controllers/{Admin,Web}/*.php` 与**顶层** `src/Http/Controllers/*.php`
  —— 后者是雷达类平铺布局的既有形态，不收窄判据就会把「按控制器命名」的目录误判为模块段。

