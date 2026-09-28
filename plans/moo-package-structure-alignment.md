# moo-* 包仓库结构规范对齐

> 本文件是 2026-09-28 **已批准方案**的仓库内副本（原稿存于会话私有目录，为跨工具交接落进仓库）。
> 执行状态、待议项与接手复核见 [`../TODOS.md`](../TODOS.md) 的「扩展包骨架规范对齐」一节；
> 可执行判据见 [`../tools/audit-package-structure.php`](../tools/audit-package-structure.php) 与 [`../docs/package-skeleton.md`](../docs/package-skeleton.md)。
> 按公开仓纪律脱敏：不含本机绝对路径与内部项目名。

**范围**：本仓同级目录下 29 个目标 = 28 个 Composer 扩展包 + `moo-engine-skeleton`（host 骨架）。
**本轮尺度**：非破坏性优先 —— 只做补齐与判据固化；重命名 / 目录搬迁 / namespace 变更只登记待议，不实施。
**规范基准**：以 `moo-system` + `moo-<name>` **当前代码**为准（已确认优于 moo-php-pkg skill 的骨架文本，二者冲突时以代码为准）。

---

## 一、盘点结论

### 1.1 29 个目标的分类

| 类别 | 数量 | 成员 |
| --- | --- | --- |
| CRUD 功能包（完整骨架） | 21 | attachment, banner, category, certificate, cms, collect, comment, enterprise-information, feedback, like, media, mini-app, page, process, process-application, product, richtext, schedule, trail, upload, **camera-recognition**（骨架空壳） |
| 基础设施 / 内核包 | 3 | monitor-laravel（采集，无 CRUD）、flow（状态机内核，无 provider）、contract（纯契约，零框架依赖） |
| codegen 工具本身 | 1 | scaffold（无 `routes/admin.php`、无 `scaffold/database`） |
| 组织数据所有方 | 1 | system（canonical 范本） |
| 领域引擎（自持 libs） | 1 | radar（自持 Archive/Libs，require `moo-system` 的唯一豁免） |
| host 骨架 | 1 | engine-skeleton（`engine/` 为 Laravel 根，三份 composer manifest） |

出队（本轮不管）：应用仓、内部工具仓与姊妹前端仓共 6 个目录 —— **不逐一举名**（各自身份见脚本 `--json` 输出的「跳过」段）。

### 1.2 已确认**合规**的面（无需动作）

- **基础设施三件套**：`UsingSnowFlakePrimaryKey` / `BaseFilter` / `MergingLoader` 全仓只有 scaffold 一份定义，各包均 `use Mooeen\Scaffold\*`；**零** `registerSnowflake`；无包自持雪花 config 段。
- **`moo-system` 依赖面**：只有 `moo-<name>` require（已豁免）+ `moo-system` 自身。
- **`moo-contract` 依赖面**：凡 `src/` 引用 `Mooeen\Contract\*` 的包都已在 composer require（无漏 require）。
- **`extra.laravel.providers`**：有 provider 的包全部登记；无 provider 的 contract / flow 正确未登记（与其「不自启动」设计一致）。
- **`routes/admin.php` 插入标记**：29 个目标全部含 `:insert_code_here:do_not_delete`。
- **`.gitignore`**：所有在队包均含 `vendor` + `composer.lock`。
- **`lang/`**：所有在队包都有 `zh-CN` + `en` 两个 locale。
- **migrations 命名**：除 `moo-system`（`0002_*` 历史特例，已记录）外全部日期命名。
- **`src/Models/` 平铺**：无 `Modules/` 模块层。
- **`docs/overview.md`**：在队包全部具备（contract / flow / monitor-laravel / scaffold 按定位豁免）。

### 1.3 偏差清单

**A 级 — 真缺文件（零语义风险，直接补）**

| # | 包 | 缺什么 |
| --- | --- | --- |
| A1 | `moo-feedback` | `AGENTS.md`（**唯一**缺项目指令文件的包） |
| A2 | `moo-contract`、`moo-feedback`、`moo-<name>`、`moo-<name>`、`moo-upload` | `CLAUDE.md` |
| A3 | 20 个包 | `.github/workflows/`（CI 骨架；现仅 banner/certificate/cms/feedback/page/product/scaffold/system 有） |
| A4 | 23 个包 | `.gitattributes`（现仅 process/radar/richtext/scaffold/system 有） |

**B 级 — 文件内容口径不一致（有代码格式影响，独立提交）**

| # | 包 | 偏差 |
| --- | --- | --- |
| B1 | `moo-upload` | `pint.json` 缺 `phpdoc_separation` |
| B2 | `moo-<name>`、`moo-<name>`、`moo-<name>` | `pint.json` 多出 `ordered_traits: true` |
| B3 | `moo-<name>`、`moo-feedback`、`moo-<name>`、`moo-<name>` | `pint.json` 用 2 空格缩进（其余统一 4 空格）—— 纯格式，应按 canonical 重排 |

**C 级 — 布局红线偏离（改动即破坏 namespace，本轮只登记）**

| # | 位置 | 说明 |
| --- | --- | --- |
| C1 | 7 包 13 文件 `src/Models/Concerns/` | attachment(2)、category(2)、collect(2)、comment(2)、feedback(1)、like(2)、trail(2)；规范要求 `src/Models/Traits/`（codegen 硬编码 emit `use {ns}Traits\...`） |
| C2 | `moo-<name>/src/Models/Concerns/` | 空目录（残留） |
| C3 | `moo-<name>/src/Concerns/HasRichTextFields.php` | 是 model trait，应在 `src/Models/Traits/` |
| C4 | `moo-<name>/src/Http/Requests/Business/`（19 个 Request） | 模块段，规范是 `Requests/<Controller>/` |
| C5 | `moo-monitor-laravel/src/Concerns/` | 采集器辅助 trait（非 model trait），目录名仍非标准 |

**D 级 — 命名 stem / 环境差异（判断题，本轮只登记）**

| # | 项 | 说明 |
| --- | --- | --- |
| D1 | `moo-monitor-laravel` | config stem `moo-monitor`、namespace `Mooeen\Monitor`、provider `MonitorProvider`、psr-4 target 缺尾 `/`；**但 `moo-monitor-vue` 是姊妹前端包，`monitor` 可能是刻意共享的产品 stem**，需先确认设计意图 |
| D2 | `moo-scaffold` | config stem `scaffold`、provider `ScaffoldProvider`、发布标签 `config`/`public`；改它等于改所有 host 的 `config/scaffold.php`，风险最高 |
| D3 | `moo-<name>` | env 变量 `RADAR_ADMIN_MIDDLEWARE` 漏 `MOO_` 前缀 |
| D4 | 10 个包 | 后台中间件组默认值仍是 `admin`（system/process/camera-recognition/like/trail/feedback/radar/collect/comment/category），其余 13 包已是 `moo-<name>`；host 目前只注册了 `moo-system`/`moo-upload`/`moo-feedback` 三组，与 host 自身注释（要求逐包独立命名组）冲突 |
| D5 | `moo-<name>` | tests 走 `tools/bootstrap.php` + `tests/bootstrap.php`，无 `TestCase.php`/`Pest.php`；另有 `.codegen/`、`tools/{bootstrap,generate,metadata}.php` —— 是另一套测试形态，需确认是否有意为之 |
| D6 | `moo-engine-skeleton` | 三份 manifest 的私包 repository/版本分流**正确**，但 `require-dev` 与 `scripts` 存在规范外差异：local 多 `pest/sail/dump-server` + `setup/dev/lint`，test/production 只有 `clear-all` |
| D7 | `moo-engine-skeleton` | 根目录无 `CHANGELOG.md`；`moo-feedback` 已被 require 且有 path/vcs repo 条目，却未进 `extra.moo-private-packages`（`PRIVATE-COMPOSER-PACKAGES.md:63` 有说明，需复核是否仍成立） |

---

## 二、规范基准（写成可执行判据）

`moo-system` = canonical，`moo-<name>` = 次范本。落地清单：

**所有在队扩展包必需**
```
composer.json  pint.json  .gitignore  README.md  CHANGELOG.md
AGENTS.md  NOTES.md  TODOS.md  phpunit.xml  [CLAUDE.md 若仓库使用 Claude Code]
config/moo-<stem>.php            routes/admin.php（含插入标记）
scaffold/database/<X>.yaml       docs/overview.md
lang/zh-CN/  lang/en/            database/migrations/（日期命名）
src/<Provider>.php               tests/{TestCase.php,Pest.php}
src/Models/{Traits,Filters}/     src/Http/{Controllers/Admin,Requests,Resources}/
```

**on-disk 命名注意**：项目记忆文件是 `NOTES.md`（大写）—— `moo-*` 全仓无 `notes.md`；skill 文本里的 `notes.md` 是旧写法。

**豁免清单（写进审计脚本，带理由）**

| 包 | 豁免项 | 理由 |
| --- | --- | --- |
| contract | config/routes/scaffold/lang/migrations/src 三件套/tests harness | 纯契约，零框架依赖，无 provider |
| flow | provider/config/routes/lang/migrations | 内核；领域定义留 host，host 负责注册 |
| monitor-laravel | routes/scaffold/lang/migrations，`Models/` 层 | 采集包，无自身业务表 |
| scaffold | routes/admin.php、scaffold/database、lang、migrations | codegen 工具本身 |
| camera-recognition | migrations（暂） | 已初始化未落地的空骨架，登记为待落地 |

---

## 三、实施计划

### Step 1 · 落审计脚本（本轮的判据产物）

新增 `moo-scaffold/tools/audit-package-structure.php`。

- 落 scaffold 而非 moo-contract：`moo-contract/AGENTS.md` 明确本包「不含 Model/Service/Provider/配置/迁移/路由」，骨架判据属 scaffold 领域（`stubs/` 就是下游生成规范），且 scaffold 已有 `tools/`。
- CLI 形状对齐 `moo-contract/bin/audit-contracts.php`（生态内既有惯例）：
  ```
  php tools/audit-package-structure.php [--workspace=<同级目录>]
                                        [--package=moo-<name>]
                                        [--json] [--fail-on-drift]
  ```
- 零依赖、只读、不连库不发网络（复用 `--workspace` 扫同级目录的做法）。
- 结构：
  - `CHECKS`：必需文件/目录/glob 表（来自 §二），每项一个 id + 严重级（`MISS` / `LAYOUT` / `STYLE` / `NAME`）。
  - `ALLOWANCES`：§二 豁免表（包 → 豁免项 + 理由），命中豁免不进 drift。
  - `CANONICAL`：从 `moo-system` 现读 `pint.json` / `.gitignore` 作为基准，或内置快照；比对做 **JSON 语义比对**（`rules` 键集合与值），不比字节 —— 这样缩进差异（B3）单列 `STYLE-INFO`，缺/多规则（B1/B2）列 `STYLE-DRIFT`。
  - `EXTRA_RULES`：`src/Models/Concerns/`（非空）、`src/Concerns/` 下的 model trait、`src/Http/Requests/<模块段>` → 输出 `LAYOUT-DRIFT`（C 级，只报不改）。
  - `glob` 跳过 `vendor/` `.git/` `node_modules/`。
- 退出码：有未豁免 `MISS`/`STYLE-DRIFT` 且带 `--fail-on-drift` → 1，否则 0（供后续 CI/发版前核对）。

### Step 2 · 零风险补齐（A 级，纯新增文件）

- `moo-feedback/AGENTS.md`（A1）：按 `moo-system/AGENTS.md` 的分节骨架（包定位 / schema 与生成 / 边界 / 验证与交付），内容从 `moo-feedback` 的 `README.md`、`NOTES.md`、`config/moo-feedback.php`、`src/Contracts/FeedbackTypeResolver.php` 与 registry 里 feedback 的登记理由（「反馈类型由宿主业务定义，不能合入组织目录」）提炼；`moo-feedback` 是 host 直接消费包（host 骨架与多个应用仓），需写明跨包边界。
- `CLAUDE.md`（A2，5 个）：沿用最简「指针 + 高风险提醒」形态（参照 `moo-<name>`/`moo-<name>` 的 1–2 行版，或 `moo-system` 的 5 条版），首句统一指向 `AGENTS.md`。
- `.github/workflows/tests.yml`（A3，20 个）：以 `moo-system/.github/workflows/tests.yml` 为模板（PHP 8.2/8.3/8.4 × Laravel ^12 matrix → `composer install` → `pint --test` → `pest`），按各包调整；larastan `static-analysis` job 保留但 `continue-on-error`，或对新包先省略该 job。**注意**：这些仓 Gitee 为主、GitHub 为镜像，workflow 只在镜像上跑 —— 若镜像未启用 Actions，此项无实际收益，实施前逐仓确认。
- `.gitattributes`（A4，23 个）：沿用 `moo-system/.gitattributes` 内容（`* text=auto eol=lf` + `export-ignore` 段）。

### Step 3 · `pint.json` 口径统一（B 级，独立提交 + 复核 diff）

- 逐包把 `rules` 对齐 `moo-system`：`moo-upload` 补 `phpdoc_separation`；attachment/enterprise-information/trail 去掉 `ordered_traits`（**先确认是否刻意**：这三包是手写深化最重的包，`ordered_traits` 会重排 trait `use` 块，可能是刻意加的）；cms/feedback/media/product 重排缩进。
- 每包改完必跑 `./vendor/bin/pint --test`，若出现格式化差异，逐个复核是否本包已有代码需要同时 reformat —— **只处理本次规则涉及的文件，不顺手格式化**。
- 与 Step 2 分开提交，便于单独回滚。

### Step 4 · 文档沉淀

- 新增 `moo-scaffold/docs/package-skeleton.md`：§二 的必需清单 + 豁免表 + 审计脚本用法，作为骨架规范的**唯一真值源**。
- 在 `moo-scaffold/NOTES.md` 记一条：骨架规范已固化为 `tools/audit-package-structure.php`，基准为 moo-system 现况，含豁免清单。

### Step 5 · 待议清单（登记，不实施）

把 C1–C5、D1–D7 写进 `moo-scaffold/TODOS.md`（C 级按包列文件路径，D 级按仓列），标明「需先确认设计意图 / 需同步消费方」，不在本轮动。

---

## 四、验证

**审计脚本本身**
```bash
php tools/audit-package-structure.php --workspace=<同级目录>   # 人工可读报告
php ... --json                                   # 机器读
php ... --fail-on-drift; echo $?                 # 期望 1（Step 2/3 前）
```

**Step 2 后**：`php ... --fail-on-drift` 的 `MISS` 应为 0（豁免项不计）。
**Step 3 后**：每改包 `cd <pkg> && ./vendor/bin/pint --test && ./vendor/bin/pest`（定向，不跑全量；若某包规则改动引发大范围 diff，逐个复核并单独报告）。
**纯文档 / 元文件新增**（AGENTS.md、CLAUDE.md、.gitattributes、workflows）：`git diff --check` + 核对内部链接（如 `[AGENTS.md](AGENTS.md)`）真实存在。
**host（若动 D6/D7）**：`engine/` 下三份 profile 各跑 `composer validate --strict` 与既有 `ComposerProfilesTest`；生产 profile 约束不得出现 `dev`/`@`/` as `。

**收口标准**：审计脚本在 29 个目标上零未豁免 drift；每个改动包 `pint --test` + 定向 `pest` 全绿；`git diff` 只含本轮声明的改动。

---

## 五、边界与不做

- **不**做 C 级 namespace/目录搬迁（会让 codegen 重生成断 `use` 行、并影响消费方），只登记。
- **不**改 D1（monitor stem）与 D2（scaffold stem）——破坏性且跨 host。
- **不**动 D4 的 host 中间件组接线 —— 需 host 逐包注册 + 安全验证（匿名 401 / 无 ACL 403），属独立任务。
- **不**跑 E2E、全量测试或 Admin 双 smoke；不动 taste 文件。
- 不主动 commit / push / tag / 发版：每步完成展示完整 diff 并取得确认（按包或按批次）。
- `moo-<name>` 的空骨架不补业务内容（无真实表设计前不发明 schema）。
