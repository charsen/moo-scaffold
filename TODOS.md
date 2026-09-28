# TODOS

本文件集中记录当前尚未完成且可执行的项目待办。复杂方案应链接到正式 plan；完成后及时勾选或清理。

## 通用字段交付待办

- [x] **已完成（2026-09-20 逐项核验）**。四步发布顺序全部到位：① scaffold 侧已随稳定版 `2.1.25` 发布
  （tag 已推两个远程）；② 两个消费包均已把最低依赖收紧到 `^2.1.25`；③ 消费包已发布
  （mini-app `0.1.9` / process `0.2.11`，tag 已推）；④ 宿主三份 composer manifest 合规
  （`ComposerProfilesTest` 6 passed）、SPA 控件类型注册表校验一致（18 / 18）。
  ⑤ 宿主**生产**档已把 mini-app / process 的 pin 由 `^0.1.8` / `^0.2.10` 显式抬到 `^0.1.9` / `^0.2.11`
  （caret 本已覆盖最新版，属「显式钉住已验证版本」的取舍），并经宿主侧
  `fix-production-pin-moo-packages` 分支合并进其 `dev` 与 `master`。
  仅余**一项**非阻塞遗留（不是本仓能收的）：
  1. mini-app 的 `repositories.moo-scaffold` 仍是 `path` sibling（`../moo-scaffold`），
     脱离同级目录无法独立 `composer install` —— 已由其自身 `TODOS.md` 登记为发布/CI 决策。
     （process 侧已是 `vcs` 形态：2026-09-20 已在无锁文件、无同级目录的干净克隆上实测
     `composer install` 通过，解析出本包 `2.2.0`，来源即其声明的 vcs 仓库。）

## 扩展包骨架规范对齐（2026-09-28）

**已批准方案（仓库内副本）**：[`plans/moo-package-structure-alignment.md`](plans/moo-package-structure-alignment.md) ——
  盘点结论（A/B/C/D 分级）、实施计划 Step 1–5 与「边界与不做」都在那里；本节的执行状态以本文件为准。

**接手复核（2026-09-28）**：同级目录出现了第 30 个目标 `moo-<name>`（`charsen/moo-<name>`，`feat-sequence`
  分支尚无任何 commit、全部 untracked），**另会话正在从零开发**，其 MISS 数在实时下降（15 → 12，别当基线）。
  按用户指示**整仓剔除**：脚本新增 `DEFERRED_TARGETS`，理由与移除条件随报告「跳过」段与 `--json.skipped` 打印
  （不静默跳过），**移除条件＝该包首次 commit 落地**；显式 `--package=moo-<name>` 仍可单查它的当前状态。
  剔除后 `--fail-on-drift` **exit=0**，本轮 **29 目标 0 MISS / 0 STYLE-DRIFT**。

**收口状态（2026-09-28）**：原本全部改动都裸躺在 24 个仓的发布基线（`main`/`master`）工作区里 —— 违反
  「发布基础分支不直接开发」。已逐仓 `fetch` 复核基线最新后，从基线各切出一个 `feat-package-skeleton-alignment`
  并提交（显式路径暂存，工作区已干净、各领先 `origin/<基线>` 1–2 个提交）。**未推送、未合入 `dev`/`master`**；
  将来发布时把**这一个分支分别合入两条线**，两条线互不经过对方。5 个他会话仓见下方待办。
  **第二批（同日）**：`.gitattributes` 的 dist 裁剪补齐 `/plans` 与 `/TODOS.md` —— 此前基准版注释写着「工具/文档不进包」，
  实测 `plans/`（4 仓 9 文件）与 `TODOS.md`（23 仓）仍会发进消费方 `vendor/`；22 个带该基准文件的基线仓各追加 1 个提交。
  逐仓用 `git archive HEAD` 回读：归档不再含 `plans/`、`TODOS.md`，且 `src/config/routes/database/lang/scaffold` 未受影响。
  **注意两条边界**：① `export-ignore` 只在「按 tag 装 dist」时生效，本地 `path`+`symlink` 联调与本机三个 host 的
  `vendor/charsen/*` 都看不到效果（写错只会在测试服/生产暴露）；② 属性取自**被归档的那棵树**，所以必须进提交/进 tag ——
  验证工作区改动要用 `git archive --worktree-attributes`。
  **第三批（同日）**：`moo-scaffold` 与 `moo-engine-skeleton` 也各起一份（同一分支上追加提交）——
  前者原文件只有 `*.ai binary`（另一种用途），今回归档实测 **610 → 422** 个文件；后者原本没有 `.gitattributes`，新增后 **232 → 223**。
  **两处刻意例外，别当遗漏**：① `moo-scaffold` 的 `docs/` 与 `tools/` 保留 —— `docs/` 是 host 文档中心的包文档源
  （`src/Support/DocsRepository.php:50` 读 `$pkg` base_path 下的 `docs/`），裁掉等于删掉每个 host 文档中心里的本包文档，
  而 `docs/package-skeleton.md` 又指向 `tools/audit-package-structure.php`；② `moo-engine-skeleton` 保留
  `NOTES.md`/`TODOS.md` —— host 骨架要给新项目**继承**协作文档，与扩展包裁剪口径相反（已在两份文件的注释里写明理由）。
  `moo-engine-skeleton` 是 `type=project`，`git clone` 安装时该文件完全不生效，只影响 `create-project` / 归档下载。

判据已固化为 `tools/audit-package-structure.php`（只读闸门，`--fail-on-drift`），规模口径见
`docs/package-skeleton.md`。**本轮只做非破坏性补齐**；下列事项需先确认设计意图或同步消费方，未动。

- [x] **3 个他会话仓已提交（挂在他的任务分支上，经用户显式许可）**：`moo-<name>`、`moo-<name>`
  （同处 `fix-category-business-status`）、`moo-<name>`（`fix-business-code-prefixes`）各一笔独立提交
  （`edeb59d` / `aea496a` / `8c866d0`，message 统一 `chore：对齐扩展包骨架规范`）。
  **为什么不再等**：那 3 个文件此前是**无保护的未暂存改动**，而对应会话正在逐笔提交，下一次 `git add -A` /
  `commit -a` 就会把它们卷进一笔业务提交（不可追溯、无法单独回滚）。提交时逐仓断言「暂存区恰好只有这 3 个路径」，
  未带走他们的业务改动（`moo-<name>` 脏项 25 → 22，其余文件原样保留）。
  ⚠ **这 3 笔提交混在他会话分支上**：会随他们的分支进 `dev`/发布线，而他们的发版记录不会提到它；
  他们若 `rebase`/`reset` 该分支，提交可能丢（改动机械，可重放）。

- [x] **5 个他会话仓全部提交完毕（各自挂在该仓既有任务分支上）**：前三个见上；`moo-<name>`（`b645f9f`）、
  `moo-<name>`（`b45c588`）按用户指示「commit + 在现有分支继续」补上 —— ⚠ 这使
  `fix-shared-sequence` 的**首个提交**成了骨架对齐提交（原计划避开的正是这点），未推送，
  `git reset --mixed HEAD~1` 可干净退回。
  ⚠ **我在这条上犯过一次错，记下来**：本节早就写明「补的时候用已更新的基准版，别照抄工作区里那份旧的 15 行版」，
  我第一遍仍按工作区原样提交了这 5 仓（13 条旧版、缺 `/plans` 与 `/TODOS.md`），复查提交树时才发现，
  随后 5 仓各补一笔 `chore：dist 裁剪补齐 plans/ 与 TODOS.md` ⇒ 现均为 15 条基准版，
  `git archive` 已排除 `plans/`、`TODOS.md`。
  **教训**：写在文档里的交接注意事项，落盘前要当**验收清单**逐条勾，不能当背景说明读过就算 ——
  否则同一份文档既是判据又被自己无视，比没写更坏。

- [x] **已完成**：补齐 `moo-feedback/AGENTS.md`（该包唯一缺失的项目指令文件）；补齐 5 个包的 `CLAUDE.md`
  （contract / feedback / meeting / richtext / upload，另 24 份改为纯入口）；`pint.json` **8 份**偏离
  （B1 1 + B2 3 + B3 4）已归一为 canonical 规则集，其余 21 份本就一致；`.gitattributes` **27 份**落实
  「dist 裁剪但保留 `/scaffold`」（23 份新增 + 4 份既有归一：process / radar / richtext / system；
  `moo-scaffold` 既有未改、`moo-engine-skeleton` 无此项）。其中 **24 份为同一基准版**，
  `moo-<name>`（+`/tools`）、`moo-system`（+`/HANDOFF.md`）、`moo-<name>`（+`/.codegen`、`/tools`）
  是在基准上按本仓增补 `export-ignore` —— **属有意定制，不是漂移，别再「统一」掉**。
  审计结果：本轮 **29 目标 0 MISS / 0 STYLE-DRIFT**
  （当前工作区第 30 个目标 `moo-<name>` 已整仓剔除，见上方接手复核）。

- [ ] **CI 骨架（阻塞，20 个包缺 `.github/workflows/`）**。前置不是「加 workflow」，而是
  **「脱离同级目录能 `composer install`」**：13 个包的 `repositories` 声明 sibling `path`（`../moo-*`），
  GitHub Actions 只 checkout 本仓 ⇒ 私包解析不到（也不在 Packagist）；已有 `quality.yml` 的 6 个包同样带 path 依赖、
  且 workflow 无 ssh-agent 步骤，另有包用 `git@gitee.com:...` 的 vcs 源 ⇒ **那几份多半本来就是红的**。
  与上面「mini-app 仍是 path sibling」是同一条根因。**收口顺序**：先把受影响包的 manifest 改成 vcs 形态
  （`moo-<name>` 的样板）+ 在 workflow 注入部署密钥，再谈批量补 CI。范本：`moo-<name>/.github/workflows/quality.yml`。
  **2026-09-28 实测细分（把「13 包」拆成可执行的粒度）**：有 `path` 条目的确 **13 包**，但要按「有没有 vcs 兜底」分两类 ——
  ✗ **只有 path、干净 checkout 必失败（4 包，硬阻塞）**：`moo-<name>`(11 条) / `moo-<name>`(4) /
  `moo-<name>`(3) / `moo-<name>`(2)；⚠ **vcs+path 混用（9 包，本地方便 + CI 有兜底）**：banner / certificate / cms /
  mini-app / process / process-application / product / radar / system。
  另 **10 包是纯 SSH vcs**（attachment / camera-recognition / category / collect / comment / feedback / flow /
  like / schedule / trail）⇒ CI 要 deploy key；只有 `moo-<name>` 走 HTTPS。
  ⇒ 真正的卡点是**两件事**：4 包补 vcs 兜底 ＋ **凭据方案（SSH deploy key vs HTTPS token）需用户定**。
  ⚠ 另有一条踩坑：`repositories` 有 **list 与 dict 两种形态**（15 list / 12 dict），写批量脚本必须两种都吃 ——
  第一版侦察只吃 list，把 dict 形态那一列打成了空白，被我读成「无 repositories」而得出错误结论。
  **⚠ 第三个独立根因（2026-09-28 实测）**：包测试套件跑在**内存 SQLite** 上时缺 `cache` 表
  （`SQLSTATE[HY000]: no such table: cache`），而缓存驱动走 database ⇒ **即使解决了 path 依赖，
  `composer ci` 的 test 步照样红**。实测 `moo-<name>`（本次完全未改动）同样中招，属既有环境缺口；
  跑测试时 `CACHE_STORE=array` 可一次性绕过。
  **⚠ 修法结论（2026-09-28，含一次自我纠正）**：**先纠正我上一版写在这里的错误结论** —— 我原写「`phpunit.xml` 里
  `<env name="CACHE_STORE">` 无效」，那是**误判**。真因是**运行 shell 里已导出了同名变量**
  （本机实测导出过 `CACHE_STORE=database` / `SESSION_DRIVER=database` / `DB_CONNECTION=sqlite` / `APP_DEBUG=true` / `APP_ENV=local`）：
  **PHPUnit 非 `force` 的 `<env>` 在变量「已存在于进程环境」时会被整条跳过**（这是 PHPUnit 的文档行为），
  于是 shell 值胜出、`phpunit.xml` 的值看不见 —— 干净 shell 里那行本来是**有效**的。
  交叉证据（engine-skeleton 的 A/B，同一份代码）：`env -u SESSION_DRIVER -u CACHE_STORE … pest` → **103 passed**；
  保留 shell 变量 → **1 failed**（`no such table: sessions`），**且给 `<env>` 加 `force="true"` 也压不住**。
  **可靠修法 = 在测试引导里用配置锁死**：扩展包用 `tests/TestCase.php` 的 `defineEnvironment()`
  设 `$app['config']->set('cache.default', 'array')`（与 shell 无关、也不受 `force` 语义影响）；
  host（`moo-engine-skeleton`）在测试基类里同理。本轮 9 个包按此修，**修法本身仍然正确**。
  另：`moo-<name>/phpunit.xml` 那行 `<env name="CACHE_STORE">` **不是假先例**（同属被 shell 压制的受害者）；
  本轮以 TestCase 配置替代它属**加固**（功能等价、更稳），不是「修掉一行错配置」。
  **通则**：跑套件做判断前先 `printenv | grep -E 'CACHE_STORE|SESSION_DRIVER|DB_CONNECTION|APP_'`；
  「换台机器/换个 shell 就红」先怀疑环境变量，别急着改断言或改产品码。

- [ ] **既有 pint 违规（改前就红，非本次引入）**：`moo-<name>` 35 处 / `moo-<name>` 2 处 /
  `moo-<name>` 1 处；**本仓自身 2 处**（`tests/Feature/Concerns/HasOperatorContextTest.php`、
  `tests/Feature/Concerns/OperatorResolverTest.php`，`class_attributes_separation`）。
  已用「提交态配置 A/B」与「文件未被本次修改」双重证实与本次 `pint.json` 归一无关，属独立过堂。
  **2026-09-28 实测：四仓性质不同，别当一件事做** —— cms（2 处：`ArticleController` 的 `no_extra_blank_lines` +
  `tests/Feature/FormOptionsTest.php`）、product（1 处：`ProductController` 同类）、scaffold（2 处：上述两个测试文件）
  **全在手写文件**，`pint --fix` + 定向测试即可验证；而 enterprise-information 的 35 处**含生成物**
  （`database/migrations/2026_09_23_*` 的 `class_definition` / `no_trailing_whitespace_in_comment` / `braces_position`）
  ⇒ 按「不手改生成物」的规则，需先定**重新生成还是接受现状**，不能跟着一起 `--fix`。
  **进展（2026-09-28）**：cms（`c8649ab`）/ product（`1e3d7c1`）/ scaffold（`324f54d`）三仓已修，
  `pint --test` 全绿（61 / 55 / 359 files），`php -l` 与定向测试通过；**enterprise-information 仍待你定**。
  另核过 `stubs/controller-admin.stub` 的 use 块**本身无空行** ⇒ 那两个 Controller 是文件落后而非 stub 违规，
  修完不会因重生成而 churn（这是 NOTES 坑②那条纪律的应用）。
  **追加**：`moo-<name>` 有 **1 处既有违规**（`src/Http/Controllers/Admin/BannerController.php` 的
  `class_attributes_separation`，最近改动是 2026-09-23，与本次无关）—— 它会让 banner 的 `composer ci` 的
  `pint:check` 步失败，**未修，待你定**（另 1 处 `AdminPresentationTest.php` 的 import 顺序已在本次顺带归位）。
  **收口（2026-09-28 第三批）**：**全部修完，四仓 `pint --test` 全绿** —— banner `37fb18f`（1 处）、
  radar `40457d8`（2 处，同上「文件落后于 stub」型）、enterprise-information `f663a5c`＋`3d6d0cd`
  （**31 处 / 35 文件：src + tests + lang**，pint PASS 165 files、套件 80 passed；6 个 `lang/**` 是生成物，
  已逐文件核对「`require` 出的数组与格式化前完全相等」）。enterprise 的根因**不在仓库而在生成器**，
  同批在 scaffold 侧修掉（见下条），所以这次不是「手改生成物」而是「先修生成器再归一产出」。

- [x] **生成器产出即合规（2026-09-28，本仓）**：enterprise-information 那批 pint 违规的**根因在本仓生成器**，
  按 NOTES 坑②的纪律「lint 规则约束到生成物形态时，它就是 stub 的隐式依赖」，先修生成侧再归一产出：
  ① `Generator::putAndReport()`（唯一写入点）统一剥掉行尾空白 —— stub 里对齐用的固定空格在占位符为空时
  会留下 `* @Author:      ` 这种行尾空白，pint 的 `no_trailing_whitespace_in_comment` 每次必报（生成的迁移就踩了）；
  ② `UpdateMultilingualGenerator` 的 lang 产出补 `<?php declare(strict_types=1);` 并按最长 key 对 `=>` 对齐
  （canonical pint 的 `declare_strict_types` + `binary_operator_spaces`），8 个 `stubs/lang/*.stub` 头部同步；
  ③ `CodegenOriginTest` 里两条硬编码「单空格 `=>`」的断言改为容忍空白的正则（语义不变）。
  验证：**1341 passed / 3 skipped、pint PASS（359 files）**。⇒ 以后「重生成即合规」，不必再手动补格式化。

- [x] **把这两类缺陷补进闸门（2026-09-28）**：它们此前都是「闸门看不见、只能人肉扫出来」的，现已成机器判据 ——
  新增严重级 **`CONFIG`**（**会判失败**，两项判据）：
  ① **path 仓库的 `versions` 必须是具体版本**（约束式 ⇒ 无 `composer.lock` 的 fresh install 被 Composer 拒绝）；
  ② **`.gitattributes` 必须含基准 `export-ignore` 条目**（**只在该路径本仓确实存在时才要求**；逐仓额外条目
  不算偏离；`moo-scaffold` 的 `docs/` 走 `ALLOWANCES` 的 `gitattributes:docs` 例外；host 不套本判据）。
  实现要点：`repositories` 的 **list / dict 两种形态都要吃**（我正是在这上面误判过一次，脚本里注释留了痕）。
  **咬合力验证**：删掉 `moo-<name>` 的 `/plans` 条目 → 报 `CONFIG` + `exit=1`；把 `moo-<name>` 的 versions
  改回 `"^2.2.1"` → 报 `CONFIG` + `exit=1`；两次都**精确还原**（`git status` 零差异、`exit` 回 0）。
  规范同步在 `docs/package-skeleton.md`（严重级表 + `CONFIG` 边界 + 维护条款里的咬合力要求）。

- [x] **host 骨架的两处失败（2026-09-28，`moo-engine-skeleton`）**：
  ① **真缺陷**：`engine/config/actions.php` 的 whitelist 在 2026-09-22 那次 `moo:auth` 整文件重写后丢了
  **8 个个人中心 key**（docs/09 坑 #25 的现场，`FoodAclTest` 正是它的守护）⇒ 按该文档规定的
  「手动合并（坑 #20/#25）」注释块补齐（依据＝历史原段 `git log -S` ＋ 测试里的 8-key 清单），`699ab9f`。
  ② **不是仓库缺陷**：`ExampleTest` 的 500 是本机 shell 导出了 `SESSION_DRIVER=database` 导致
  `no such table: sessions`（同代码清掉该变量即 **103 passed**）⇒ 只在 `engine/phpunit.xml` 写明排查方式（`64704a7`），
  **不留无效配置**（我先试的 `force="true"` 实测压不住 shell 变量，已撤回）。

- [x] **消费包测试夹具落后于契约（2026-09-28 已修 `moo-<name>` / `moo-<name>` / `moo-<name>`）**：
  `tests/Pest.php` 里匿名 `OperatorResolver` 实现**缺 `isPlatformRoot()`**（scaffold 的 operator-identity-contract
  新增的方法）⇒ 走到用该 helper 的测试就 `Pest\Exceptions\FatalException`。属「改契约必须扫四层引用」的
  **第四层（测试夹具）漏网**。已修：cms `c9619ca` / product `3a4c989` / banner `ff57680`
  （各补 `isPlatformRoot()` ＋ `TestCase::defineEnvironment()` 设 `cache.default=array`），
  三仓全量套件 **29 / 29 / 39 passed、`cache` 报错 0**，cms/product `pint --test` 亦 PASS。
  ⚠ **另一处不是「夹具缺绑定」而是「断言落后于已删行为」**：那三个用例原先断言「未绑定宿主时回退裸 ID」，
  而 2026-09-22 生态已明确**删掉空兜底**（`moo-<name>` `5d6df51`：「空兜底已删，断言须宿主绑定」）
  ⇒ 产码里的 `app(PersonnelNameResolver::class)` 是**刻意的显式失败**，**不该**给它加 `bound()` 守卫。
  正解是**改测试**：绑定先行 ＋ 钉住「无内置兜底」（`expect(app()->bound(...))->toBeFalse()` +
  `toThrow(BindingResolutionException::class)`），照抄 moo-<name> 的写法。**别再试图在产品码里加兜底。**

- [x] **同类问题（2026-09-28 已修 `moo-system` / `moo-feedback` / `moo-upload` / `moo-<name>`）**：
  四包各一笔修复提交 —— system `8a935f9`、feedback `fa74a61`、upload `3ccafa1`、richtext `79ca8f8`。
  **结果：四包从「整仓红」变全绿，且没有任何残余失败签名**：
  `moo-system` 155 failed/224 passed → **379 passed (1720 assertions)**；`moo-feedback` 31 → **70 passed**；
  `moo-upload` 26 → **59 passed**；`moo-<name>` 11 → **37 passed**；四包 `pint --test` 全部 PASS。
  **根因单一**：这些失败**全部**来自「内存 sqlite 缺 cache 表」，不是各自独立的缺陷 —— 换个修法（如在产品码加兜底）
  会既修不好又改错方向。另 `moo-upload` 多一处**非法实现**：`tests/Support/TestOperatorResolver` 只实现 `id()`，
  身份契约新增 `isPlatformRoot()` 后该类**加载即 fatal**（此前被 cache 失败掩盖）—— 已补。
  修法固化（三步，缺一不可）：① `tests/TestCase.php` 的 `defineEnvironment()` 设 `cache.default=array`；
  ② 夹具补 `isPlatformRoot()`；③ 落后断言改成「绑定先行 + 钉住无兜底」。
  另删除 `moo-<name>/phpunit.xml` 里那行**失效的** `<env name="CACHE_STORE">`（留着一行看似处理过、实则无效的配置
  比没有更坏）。
  **第二批（同日，9 个仓：`attachment` / `page` / `radar` / `collect` / `comment` / `like` /
  `enterprise-information` / `trail` / 本仓 `moo-scaffold`）**：先把 32 个有 pest 的仓全扫一遍，再逐个收口 ——
  `db67399` / `a33ff36` / `04e2426`＋`40457d8` / `1f66412` / `4903b2d` / `f2c697a` / `489fa76` / `5b1ecdd` / `2bce8d2`。
  **结果：九仓套件全绿**（attachment 106 / page 48 / radar 284 / collect 34 / comment 28 / like 37 /
  enterprise-information 80 / trail 65 / scaffold 1341＋3 skipped），pint 除 enterprise-information 外全 PASS。
  新暴露的三类根因（都属「测试环境 / 夹具落后」，**都不是产品缺陷，修法一律在测试侧**）：
  ① **本仓自己缺 session 与 cache**：`no such table: sessions`（348 行）＋ cache —— `/scaffold/*` 的 HTTP 用例与雪花
     resolver 分别要这两张表；修法与各包一致（`defineEnvironment` 里把 `session.driver` / `cache.default` 设为 array）。
  ② **契约/元数据演进后 mock 与断言未跟进**：radar 匿名 `OperatorResolver` 缺 `isPlatformRoot()`；
     enterprise-information 的 `OrgDirectory` mock 缺 `onJobPersonnelPostings()`；同一包的列类型断言仍写 `slot`
     而元数据（`65252df`）已改为 `options`（该包另一测试也断言 `options`）。
  ③ **跨包替身表**：radar 的待认领群自动升 ACTIVE 会按 `chat_liable_id` 校验「在职人员」（`staff_status = ON_JOB`），
     内存库没有 `system_personnels` / `system_personnel_position` ⇒ 按同仓 `AdminHttpTest` / `PersonnelBindingTest`
     既有先例建最小替身，并在用例里造一个在职责任人。
  ⚠ **环境因素**：运行 shell 若导出 `APP_DEBUG=true` / `APP_ENV=local`，「生产形态」类断言会变色（debug=true 时
  Laravel 给 JSON 错误体补 `exception`/`file`/`line`/`trace`）—— 已在 `FrameworkErrorShapeTest` 显式钉住 `app.debug=false`，
  详见 `NOTES.md` 同日条。

- [x] **第三批（2026-09-28，「仍红的可以修了」）—— 四类全部处理完，两处保留给对应会话**：
  **① 他会话四仓**：`certificate` 24→**31 passed**（夹具补 `isPlatformRoot` + cache）、
  `mini-app` 8→**302 passed**（三个服务层测试按包内写法绑 `FakePersonnelNameResolver`）、
  `process-application` 25→**44 passed / 2 failed**（cache 修好；剩余 2 处是**他们在途业务改动**：
  `ArchTest` 断言 `require charsen/moo-<name>` 仍写 `^0.2.8` 而 manifest 已 `^0.2.15`；522 响应顶层 `message` 为 null）、
  `process` 11 failed **未动**（同属 522 信封/业务断言，是他们在途重构）。
  **② `media` / `meeting` / `engine-skeleton`**：`meeting` 用 phpunit 且**本来就全绿**（17 tests / 165 assertions）；
  `media` 无依赖 → 装依赖时暴露出**新缺陷**（见下）→ 修后依赖装上、**19 passed** + pint PASS；
  `engine-skeleton` 的 pest 在 `engine/vendor/bin/pest`（上一轮路径找错）→ 2 failed 中：
  `FoodAclTest` 是**真缺陷**（`moo:auth` 冲掉了 whitelist 的 8 个 key，见下），`ExampleTest` 是**我 shell 环境串味**（下条）。
  **③ `enterprise-information` 的 pint（31 处 / 35 文件）**：已修 —— 见下方 pint 条与「生成器产出即合规」条。
  **④ 新发现的 manifest 缺陷（7 包）**：`composer.json` 的 path 仓库 `versions` 写成了**约束式** `"charsen/moo-scaffold": "^2.2.1"`；
  无 `composer.lock` 的全新安装（fresh clone / CI）会被 Composer 拒绝（`Invalid version string "^2.2.1"`）——
  `media` 因此根本装不上依赖。已按既有正确写法（`enterprise-information` / `mini-app` 用的 `2.2.8`）修 7 包：
  `media` / `certificate` / `cms` / `page` / `process-application` / `product` / `richtext`（各一笔 `chore`，**`require` 约束不动**）。
  ⇒ 这条直接补强上面的 CI 前置条：**fresh clone 装不上，不只是 path 依赖缺失，还有 versions 语法非法**。

- [ ] **仍红的（只剩他会话的在途业务）**：`moo-<name>`（`fix-shared-sequence`）11 failed、
  `moo-<name>` 2 failed —— 都是 522 响应的**顶层 `message` 为 null** 与依赖约束断言，
  属他们那次「共享序列」重构的进行中状态（状态码 522 正确、只有信封字段变了），**需其会话确认契约后自行收口**。
  另 `moo-engine-skeleton` 的 `ExampleTest` 在本机 shell 导出 `SESSION_DRIVER=database` 时会红
  （同代码清掉该变量即 103 passed），**不是仓库缺陷**，已在 `engine/phpunit.xml` 写明排查方式。

- [ ] **LAYOUT 待议（改动即破坏 namespace，需同步消费方与 codegen 重生成）**：
  7 包把 model trait 放在 `src/Models/Concerns/`（`moo-<name>` 2 / `moo-<name>` 2 / `moo-<name>` 2 /
  `moo-<name>` 2 / `moo-feedback` 1 / `moo-<name>` 2 / `moo-<name>` 2），规范位置是 `src/Models/Traits/`
  （codegen 硬编码 emit `use {ns}Traits\...`）；`moo-<name>/src/Concerns/HasRichTextFields.php` 同族；
  `moo-<name>/src/Models/Concerns/` 是空残留（可直接删）；
  `moo-<name>/src/Http/Requests/Business/`（19 个 Request）与 `moo-<name>/src/Http/Requests/{WeWork,Export}/`
  是模块段，规范按 `Requests/<Controller>/` 分组。

- [ ] **NAME 待议（判断题，可能是有意的）**：`moo-monitor-laravel` 用 config stem `moo-monitor`、
  namespace `Mooeen\Monitor`、provider `MonitorProvider`、psr-4 target 缺尾 `/` —— 但 `moo-monitor-vue` 是姊妹前端包，
  `monitor` 可能是**刻意共享的产品 stem**，需先确认；`moo-scaffold` 的 config stem `scaffold` / provider
  `ScaffoldProvider` / 发布标签 `config`、`public` 同理，且它是全生态宿主的既有契约，改动风险最高。

- [ ] **环境与接线待议**：`moo-<name>` 的 env 变量 `RADAR_ADMIN_MIDDLEWARE` 漏 `MOO_` 前缀；
  10 个包的后台中间件组默认值仍是 `admin`（banner 等 13 包已是 `moo-<name>`），而 host 只注册了
  `moo-system` / `moo-upload` / `moo-feedback` 三组 —— 与 host 自身注释要求的「逐包独立命名组」冲突，
  收口需 host 逐包注册并做安全验证（匿名 401 / 无 ACL 403 / 授权成功）。

- [ ] **`moo-<name>` 测试形态**：走 `tools/bootstrap.php` + `tests/bootstrap.php` 驱动，无 `TestCase.php` / `Pest.php`，
  另有 `.codegen/` 与 `tools/{bootstrap,generate,metadata}.php`。需确认是否有意为之，再决定是否收敛到家族统一样式。

- [ ] **host `moo-engine-skeleton`**：三份 manifest 的私包 repository 类型与版本分流**正确**，但
  `require-dev` 与 `scripts` 存在规范外差异（local 多 pest / sail / dump-server 与 `setup`、`dev`、`lint`，
  test / production 只有 `clear-all`）；仓库根无 `CHANGELOG.md`；`moo-feedback` 已被 require 且有 repository 条目，
  却未进 `extra.moo-private-packages`（`PRIVATE-COMPOSER-PACKAGES.md:63` 有说明，需复核是否仍成立）。
