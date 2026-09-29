# LAYOUT / NAME / 中间件接线 —— 收口方案

> **来源**：`tools/audit-package-structure.php` 汇总的 `LAYOUT 11 / NAME 9`，以及 `TODOS.md` 的「LAYOUT 待议 / NAME 待议 / 环境与接线待议」三条。
> **为什么只报不判**：这三类改动会破坏 namespace、跨 host 契约或**安全边界**，必须先确认设计意图；审计脚本无法判断「刻意共享的产品 stem」这类语义。
> **本文状态**：方案（**未执行**）。事实采集于 2026-09-28，标注了「实测」与「待逐仓确认」。
> **建议顺序**：C（安全，独立）→ A1/A3（跨仓机械）→ A2（先确认语义）→ B1 → B2/B3（需拍板 / 破坏性最强）。

---

## C. host 中间件组接线（安全项，建议先做）

### C1. 现状（全部实测）

规则（生态条款 + host 自己的注释都写了）：**每个后台包使用独立完整的认证组 `moo-<name>`，不得借用宽松的 `admin`，也不得借别的包的组**。

| 方位 | 实测 |
| --- | --- |
| host 注册 | `moo-engine-skeleton/engine/bootstrap/app.php:73-87` 只注册 **3 组**：`moo-system` / `moo-upload` / `moo-feedback`（共用同一套 `$packageAdminMiddleware` 完整链）。注释已写明：「新增带后台路由的 moo 包时，在这里登记自己的 `moo-<name>` 组，并让包配置指向它」 |
| 包侧默认值 | **13 包已是 `moo-<name>`**：attachment / banner / certificate / cms / enterprise-information / media / meeting / mini-app / page / process-application / product / richtext / upload；**10 包仍是 `admin`**：camera-recognition / category / collect / comment / feedback / like / process / radar / system / trail |
| host 配置覆盖 | 只有 `engine/config/{moo-upload,moo-feedback,moo-system}.php` 三份，指向各自的 `moo-<name>` 组 ✓ |

**两个方向的缺口**：

1. 10 个默认 `admin` 的包挂到该 host 上时**借用宽松 `admin` 组**（违反「不得借用」）；
2. 13 个 `moo-<name>` 的包里，host **只注册了 `moo-upload`**；其余 12 个（attachment / banner / certificate / cms / enterprise-information / media / meeting / mini-app / page / process-application / product / richtext）的组**在 host 不存在** ⇒ 这些包的后台路由一旦被命中，中间件组解析失败；
3. 反向不一致：`moo-system` / `moo-feedback` 是「host 已有组、包默认还写 `admin`」。

附带一处 env 命名：`moo-<name>/config/moo-<name>.php:245` 用 `env('RADAR_ADMIN_MIDDLEWARE', 'admin')`，**缺 `MOO_` 前缀**（其余包统一 `MOO_<NAME>_ADMIN_MIDDLEWARE`）。（`moo-scaffold` 的 `SCAFFOLD_MIDDLEWARE` 属 `/scaffold/*` 自建路由，不在此列。）

### C2. 执行步骤

1. **host 扩组**：把 `app.php:73-87` 的注册块从 3 组扩到「有后台路由的包」全集（至少上表 13 个 `moo-<name>` + 即将改名的 10 个）。⚠ **不要手打清单** —— 发版那次已因手打列表漏过 `moo-system`；建议从「各包 `config/moo-*.php` 的 `middleware` 默认值」生成，或写一个 host 侧一致性测试盯着。
2. **10 包改默认值**：`config/moo-<name>.php` 的 `'middleware' => env('MOO_<NAME>_ADMIN_MIDDLEWARE', 'admin')` → 默认值改 `'moo-<name>'`（env 名已是规范形态，不用动）。
3. **消除反向不一致**：`moo-system` / `moo-feedback` 的包默认值改指 `moo-system` / `moo-feedback`（host 已有组）。
4. **`moo-<name>` env 改名**：`RADAR_ADMIN_MIDDLEWARE` → `MOO_RADAR_ADMIN_MIDDLEWARE`。这是**破坏性**（部署侧 env 必须同步；`config:cache` 后旧值失效）⇒ 要么保留旧名读一版（`env('MOO_RADAR_ADMIN_MIDDLEWARE', env('RADAR_ADMIN_MIDDLEWARE', 'moo-<name>'))`），要么与部署同步改。
5. **安全验证（逐包真实路由，不能只看配置）**：
   - `php artisan route:list --path=api/admin` 逐条核对中间件组 = `moo-<name>`（前缀取自各包 config 的 `prefix`，实测 `moo-<name>` 为 `api/admin`）；
   - 每条后台路由三态：**匿名 → 401**；已认证但无 ACL → **403**；授权后 → 正常响应；
   - 反向断言：包路由**不**落在 `admin` 或 `moo-system` 组下（防借用回归）。
6. **host 回归**：`cd moo-engine-skeleton/engine && ./vendor/bin/pest`（含 `FoodAclTest` 等 ACL 用例）。

### C3. 验收与边界

- 验收：13 个 `moo-<name>` 组在 host **全部存在**；10 包默认值改齐；三态安全验证逐包留证；host 套件 + `pint --test` 全绿。
- 边界：这是**安全边界**改动，必须真实路由验证；生产要同步 env（radar）与 `config:cache`。

---

## A. LAYOUT 11

### A1. model trait 迁移：`src/Models/Concerns/` → `src/Models/Traits/`（7 包 / 13 个 trait）

**定义方（实测）**：attachment（HasAttachments, TracksUploads）、category（BelongsToCategoryDomain, HasPublicCategoryScopes）、collect（Collectable, Collector）、comment（Commentable, Commenter）、feedback（Feedbackable）、like（Likeable, Liker）、trail（HasTrails, TracksTrails）。

**消费方是跨仓的**（这才是它「破坏性」的真正原因，实测抽样）：

| trait | 消费方（抽样，需逐仓 `grep` 补全） |
| --- | --- |
| `HasAttachments` | `moo-<name>`（Meeting, MeetingAgenda）、`某个内部 Host`（Contract, Publicity） |
| `BelongsToCategoryDomain` | `moo-<name>`（Support/CategoryRegistry）、`moo-<name>`、`moo-<name>`、`moo-<name>` |
| `HasPublicCategoryScopes` | `moo-<name>`、`moo-<name>`、`moo-<name>`、`moo-<name>` |
| `Collectable` / `Likeable` | `moo-<name>`（Information） |
| `Commentable` | `moo-<name>`、`某个内部 Host`（Contract, CustomerFollowUp, Solution） |
| `HasTrails` | `某个内部 Host`（WorkTable, Contract, Residence, Organization…） |
| `HasRichTextFields`（richtext） | `moo-<name>`、`moo-<name>`、`moo-<name>`、`moo-<name>` |

**⚠ 口径更正（重要）**：我先前记的「codegen 硬编码 emit `use {ns}Traits\…`」**对 model trait 不成立** —— 那是 `CreateControllerGenerator` 的 **controller** trait（`src/Generator/CreateControllerGenerator.php:138-142` 的 `Traits\HandlesResourceActions` / `BaseActionTrait`）。实测 scaffold 的 `src/` 与 `stubs/` 里**没有任何** `Models/Traits|Concerns` 的硬编码 ⇒ model trait 的目录**只是规范条文**，迁移不牵动生成器（也就不必「先修生成器再归一产出」）。

**执行步骤（每包一笔）**：

1. `git mv src/Models/Concerns/X.php src/Models/Traits/X.php`，并把文件内 `namespace ...\Models\Concerns` 改为 `...\Models\Traits`；
2. 包内引用更新：`grep -rn 'Models\\Concerns' <pkg>/src`（含 Support/Registry、同包 model）；
3. **消费方同轮更新**（逐仓提交）：上表各包 + host（`某个内部 Host/engine/app/Models/**`）；**其余 host**（`内部官网项目` / `某个内部 Host 项目` / `某个内部业务项目` / `某内部语言项目（后端）` 等）**待逐仓 grep 确认**，不要按名单想当然；
4. 每仓验证：包 `./vendor/bin/pest`、host `php artisan test`，外加 `pint --test`；
5. **发布顺序**：定义包先发（新路径可用），消费包随后（消费包先发会 `use` 到不存在的类）。若要平滑升级，定义包可临时保留旧路径的 `class_alias` 一版，下一版删除。

**验收**：审计 LAYOUT 中这 7 条消失；全生态 `grep -rn 'Models\\Concerns'` 只应剩空目录或注释。

### A2. Requests 模块段 → `<Controller>/` 分组（3 条）

- `moo-<name>/src/Http/Requests/Business/`：**19 个** Request，引用方在包内控制器（如 `ReferenceController` 的 `use ...Requests\Business\ReferenceChangeRequest`）。
- `moo-<name>/src/Http/Requests/{Export,WeWork}/`：各 1 个（`ExportDownloadController` → `Export\DownloadRequest`；`WeWorkCallbackController` → `WeWork\CallbackRequest`）。
- 改动：按控制器拆到 `Requests/<Controller>/`（对照生态既有形态 `Requests/Collect/`、`Requests/MyCollect/`），组内引用同步；纯包内改动 ⇒ 无发布顺序约束。
- **待确认（先定后改）**：`Business` 是否**有意的业务域分组**（19 个 Request 跨多控制器时按业务域而非控制器分组可能是刻意的）。若是刻意 ⇒ 应在 `docs/package-skeleton.md` 给「业务域分组」开明确例外并写进脚本 `ALLOWANCES`，而不是迁移。

### A3. richtext `src/Concerns/HasRichTextFields.php` → `src/Models/Traits/`（1 条）

与 A1 同法（消费方见上表），单独一笔。

---

## B. NAME 9

### B1. 机械项（无行为影响，建议直接做）

- `moo-monitor-laravel` / `moo-<name>` 的 psr-4 target `src` → **`src/`**（2 条）：仅补尾斜杠，Composer 语义等价。
- 验收：审计 NAME 少 2 条；两仓 `composer validate --strict --no-check-publish` 通过；套件不受影响。

### B2. 判断题（需拍板，代价小但涉及对外契约）

| 项 | 现状 | 影响面 | 建议 |
| --- | --- | --- | --- |
| `moo-monitor-laravel` config stem | `moo-monitor` | env 前缀 `MOO_MONITOR_*`、发布标签、`config('moo-monitor.*')` 读取点、host 的 `config/moo-monitor.php` 覆盖、文档 | `moo-monitor-vue` 是姊妹前端包，**可能刻意共享产品 stem** ⇒ 建议**保持现状**并在规范写明例外（零破坏） |
| `moo-scaffold` config stem | `config`（文件 `config/config.php`） | 全生态宿主的既有契约、发布标签 `config`、`config('scaffold.*')` | 同上：**保持现状** + 规范例外；改动面最大、收益最小 |

### B3. 破坏性项（需消费方协同 + 发布窗口）

| 项 | 现状 → 约定 | 必需动作 |
| --- | --- | --- |
| monitor 命名空间 | `Mooeen\Monitor\` → `Mooeen\MonitorLaravel\` | 包内改名 + 全仓 grep 消费方（`use Mooeen\Monitor\`、`MonitorProvider::class`、provider 注册、别名/配置引用）+ 迁移窗口（可 `class_alias` 过渡）+ `composer.json` autoload 与 `extra.laravel.providers` 同步 |
| monitor provider | `MonitorProvider` → `MooeenMonitorLaravelServiceProvider` | 同上（provider 类名是宿主 `bootstrap/providers.php` / `config/app.php` 的注册点） |
| richtext 命名空间/大小写 | `Mooeen\RichText\` → `Mooeen\Richtext\`；`MooeenRichTextServiceProvider` → `MooeenRichtextServiceProvider` | 同上；⚠ macOS 文件系统不区分大小写，注意 `core.ignorecase` 与 git 改名（需两段式改名或 `git mv` 到临时名） |
| scaffold provider | `ScaffoldProvider` → `MooeenScaffoldServiceProvider` | **排最后**：它是全生态宿主的 provider 契约，改动面最大 |

**验收**：审计 NAME = 0，或只剩已写进规范与 `ALLOWANCES` 的**确认例外**。

---

## D. 顺序、工作量与交付物

| 序 | 项 | 独立可交付 | 跨仓 | 风险 |
| --- | --- | --- | --- | --- |
| 1 | C 中间件接线（含 radar env） | ✓ 逐包 | host + 10 包配置 | 安全边界，需真实路由验证 |
| 2 | A1/A3 trait 迁移 | 需一次联动发版 | 7 定义包 + ≥8 消费包 + host | 破坏性（`use` 路径），需发布顺序 |
| 3 | A2 Requests 分组 | ✓ 逐包 | 仅包内 | 需先确认 `Business` 语义 |
| 4 | B1 psr-4 尾斜杠 | ✓ 逐包 | 无 | 无 |
| 5 | B2 stem 例外 / B3 改名 | B2 可零改动落地 | B3 需迁移窗口 | B3 最高（provider/命名空间是宿主契约） |

**完成后与审计的关系**：把确认的例外写进 `docs/package-skeleton.md` 与脚本 `ALLOWANCES`，避免审计长期重复报同一批；届时汇总应为 `LAYOUT 0（或仅余例外） / NAME 0（或仅余例外）`。
