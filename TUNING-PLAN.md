# moo-scaffold 调优方案（2026-07-09）

> 审查方式：资深 PHP 架构师视角、单上下文全仓通读 + 热点深读 + grep 量化验证。
> 品味基准（用户定）：**简洁易懂、逻辑清晰、流程式顺读、不重复嵌套引用**——不是企业级模式堆砌。
> 分工：Fable 5 审查出方案（本文档），**Opus 4.8 执行**。逐项独立执行，每项一个 commit。

## 批准状态（执行者先读这里）

- ✅ **P1 四项（§二）已批（2026-07-09），直接执行**，顺序 1.3 → 1.4 → 1.1 → 1.2。
- ✅ **P2（§三）、P3.1、P3.2（§四）已批（2026-07-09 追加）**。用户验收条件一句话：**「改后业务都正常」**——落到本文档 = 各节验证标准全绿、端点/命令外部行为不变。顺序接在 P1 后：P2（分批）→ P3.1 → P3.2。
- ✅ **1.4 删除 `ConfigSourceScanner::rebuild()` 已批（2026-07-09 用户拍板"删"）**。至此本方案无任何待决项。
- 环境：包目录 `vendor/` 已于 2026-07-09 装好（pest/pint 可用）；`CreateModelGenerator.php:650` 的孤儿 TODO 属 1.3 范围——**报告用户处置，不擅删**。
- commit 前把完整 diff 展示给用户并获批准（CLAUDE.md 红线第 7 条）。每项独立 commit，一项翻车不拖累其余。

---

## 〇、总体判断（先说结论）

**这是一个健康度很高的代码库，本方案是"调优"，不是"抢救"。** 实证依据：

- 安全面扎实：proxy 双层校验 + origin 白名单 + 审计日志 + TLS 强制 + 禁 redirect + connectTimeout 快速失败（`ApiController.php:240-394`）；escape 三件套（PHP 字面量/YAML/docblock 注入，`Generator.php:99-124`）；写路径 LOCK_EX 防多 tab 互覆（`SchemaLoader.php:588`）。
- 测试网厚：61 个 Pest 文件 / 约 1.09 万行，Designer、Generator、Http、Support 四层全有覆盖，SSRF 有专项测试（`tests/Feature/Http/ApiControllerTest.php:59-80`）。
- 注释文化独特且高价值：带 plan 编号、日期、决策理由（"为什么"而非"是什么"），例如 `ScaffoldProvider.php:92-106` 的 SECURITY POLICY 块。**执行任何改动时必须保留这类注释。**
- 分层清晰：Command(交互) → Generator(生成) → Utility(配置/缓存读) → storage/scaffold 缓存；Designer(SchemaLoader 读写 YAML)；Http(后台)。大类多但方法小、单一职责、流程式——符合品味基准。

问题集中在**一致性**（同一逻辑多处手搓）和 **plan-53 双路径分支未收敛**，均为渐进式演进留下的毛边，不是设计缺陷。

---

## 一、执行红线（每一项都适用）

1. **codegen 字节稳定**（复盘校准 2026-07-09）：**P2 强制** byte-diff——改前后在 Testbench/fixtures 环境各跑一遍生成，产物目录 `diff -r` 为空；P1 各项内容字符串未经手（只动写入/报告的包装），逻辑等价由构造保证，pest 全绿 + 控制台文案不变即可，byte-diff 可豁免。
2. **控制台输出文案不变**：ConsoleUi 的 created/overwritten/exists 文案是用户肌肉记忆，收敛重复时逐字保持。
3. 每项完成跑 `vendor/bin/pint --dirty` + `composer test` 全绿（首次先 `composer install`，包 vendor 独立于宿主）。
4. **不动 `stubs/`**（模板即编码规范，本方案范围外）；不动 Blade 视图（前端另案）。
5. 保留全部"why 注释"（plan 编号/日期/决策理由）；迁移代码时注释跟着走。
6. 遇到方案未列的"顺手可改"，停下报告，不擅动（CLAUDE.md 红线第 6 条）。
7. **文档 commit 隔离**（复盘补充）：仓根三个未跟踪文档不得混进任何调优 commit——`CLAUDE.md` + `notes.md` 用户已批入仓，开工前单独发一个 docs commit；`TUNING-PLAN.md` **保持未跟踪**（工作文档，入仓与否等用户定）。
8. **零行为变更不变量**（用户 2026-07-09 明确要求）：本方案全部条目都是**纯重构，业务功能一丝不变**。执行中观察到任何行为差异（输出、报错、路由响应、生成物）——那不是"顺手修"的机会，是**停手报告**的信号；哪怕现状看着像 bug，也原样保持、另行报告（与红线 6 同源）。
9. **测试钉现状先行**：动到的逻辑若现有测试没钉住，**先补"钉死现状"的测试、跑绿，再动手改**——改完同一批测试原样全绿即为行为不变的机器证明。已核对的覆盖现状：P3.1 无需补（proxy 已有 7 项特征测试，见 §3.1）；P2 **必须补**（TargetContextTest 现有 6 例不含新方法，见 §三验证）；P3.2 按 §3.2 第 4 步迁移即可。

---

## 二、P1：低风险一致性收敛（建议全做）

### 1.1 收编 11 处手搓"三态写文件报告"到基类现成的 `putAndReport`

**证据**：基类助手 `Generator::putAndReport()`（`Generator.php:129-142`）功能完整，但**全仓 0 个调用方**；与此同时 11 处生成器手搓同一形状（先 `isFile` 记 exists → `put` → if exists ? overwritten : created）：

```
CreateSchemaGenerator.php:37    CreateTestGenerator.php:64     CreateViewGenerator.php:85
CreateResourceGenerator.php:106 CreateControllerGenerator.php:191,400,452
CreateModelGenerator.php:193,419,515                           CreateTSModelGenerator.php:94
```

**方案**：各处替换为 `$this->putAndReport($file, $relative_file, $content)`。注意：
- 替换前确认该处的 `$file_exists` 变量后文没有别的用途（有则保留变量、只收编写+报告段）。
- `CreateControllerGenerator.php:188` 写前有空行折叠 preg，折叠后再传给 putAndReport，不影响收编。
- force 拦截（exists + !force → 提示跳过）发生在写之前、与本助手职责无关，不动。

**验证**：byte-diff 空 + 控制台输出逐字不变 + 全测试绿。

**执行现场备注**（审查时已逐点核过，省去重新侦察）：

| 位置 | 形状与注意点 |
|---|---|
| CreateSchemaGenerator.php:34-40 | put(34) 与报告(36-40) 中间隔空行；`$schema_exists` 同时喂 26 行的 force 门 → **保留变量**，只收编 put+报告段 |
| CreateTestGenerator.php:62-64 | 报告是三元形式 `$existed ? overwritten : created`，语义同 if/else |
| CreateViewGenerator.php:82-88 | 标准形状，`$file_exists`(73) 无他用 |
| CreateTSModelGenerator.php:91-97 | 标准形状，`$file_exists`(80) 无他用 |
| CreateResourceGenerator.php:103-109 | 标准形状 |
| CreateControllerGenerator.php:185-194 | put 前有空行折叠 preg(188)，折叠后传 putAndReport；`$controller_exists`(175) 同时喂 176 force 门 → 保留变量 |
| CreateControllerGenerator.php:397-403 | buildRequest 内，`$request_exists` 需查其定义处是否兼作 force 门 |
| CreateControllerGenerator.php:449-455 | buildTrait 内，`$trait_exists`(441) 同时喂 443 force 门 → 保留变量 |
| CreateModelGenerator.php:190-196 | buildModel 内，`$file_exists` 定义在方法前段，替换前核后文无他用 |
| CreateModelGenerator.php:415-422 | buildFilter 内，put 与报告间有空行 |
| CreateModelGenerator.php:512-518 | buildFactory 内，标准形状 |

### 1.2 Command 基类加 `isForced()`，替换 7 处反直觉习语

**证据**：`$force = $this->option('force') === null;` 出现在 7 个命令（CreateApi/CreateResource/CreateController/Free/CreateView/CreateSchema/CreateTest）。`VALUE_OPTIONAL` 语义：传 `-f` 不带值 → null → force=true；不传 → 默认 false → force=false。**正确但每次读都要脑内推理一遍。**

**方案**：`src/Command/Command.php` 加：

```php
/**
 * -f/--force 是 VALUE_OPTIONAL:传了不带值 → option 为 null → 强制;
 * 没传 → 默认 false → 不强制。=== null 即"用户传了 -f"。
 */
protected function isForced(): bool
{
    return $this->option('force') === null;
}
```

7 处调用点替换。行为零变化。**前提已核（2026-07-09 复盘）**：7 个命令的 `force` 声明全部是 `VALUE_OPTIONAL` + 默认 `false`，语义完全一致，无一例外——执行者不必再逐个验。

### 1.3 琐碎清理（一个 commit 打包）

- `Utility.php:26-28`：类型化属性 `protected Filesystem $filesystem` 头上挂着陈旧的 `@var mixed` docblock → 删注释。
- `CreateControllerGenerator.php:42-47`：`foreach…break` 取首元素 → `$first = reset($all[$schema_name]); $origin = is_array($first) ? ($first['origin'] ?? null) : null;`（语义等价、意图直白）。
- `CreateModelGenerator.php:650`：孤儿 `// TODO: check in vue3` ——全仓唯一 TODO。**报告用户处置，不擅删。**

### 1.4 删除 `ConfigSourceScanner::rebuild()`（2026-07-09 用户拍板"删"）

**三重验证（2026-07-09 已核）**：① `rebuild()` 全仓（src+tests）零调用；② ConfigSourceScanner 唯一消费者是 `ConfigManager.php:40` 构造注入，且只调 `scanner->envKeyOf()`（ConfigManager.php:84 全文唯一 `scanner->`）；③ `rebuild()` 体内的 `scan()`/`writeCache()` 被 `map()` 共用——**只删 `rebuild()` 方法及其 docblock（ConfigSourceScanner.php:65-75 一带），不级联删私有方法**。

**验收**：pest 全绿（含 ConfigManagerCastTest）+ `grep -n "rebuild" src/Support/ConfigSourceScanner.php` 零命中。

---

## 三、P2：host/包双路径分支收敛（中风险，须 byte-diff 双保险）

**证据**：plan-53 引入 origin 后，`originCtx !== null ?` 三元散布 22 处——`CreateControllerGenerator`(10)、`ControllerAdder`(7)、`CreateModelGenerator`(3)、`CreateResourceGenerator`(1)、`UpdateMultilingualGenerator`(1)、`RouterAdder`(1)。每处都在就地重算"包走 TargetContext / host 走 config+module folder 拼接"。

**根因**：`Utility::targetContext()` 的 host 臂（`Utility.php:356-376`）只提供 model/resource 等 8 个 path key，**没有 controller/request 的 path+namespace**（host 这两者依赖每表的 `module.folder` 段，包是平铺）——所以生成器只能自己分叉。

**方案**：给 `TargetContext`（`src/Support/TargetContext.php`，现仅 73 行）补 host 感知的解析方法，把"module folder 差异"参数化：

```php
// 概念签名(执行者按现场调整):
public function controllerPath(string $moduleFolder): string;      // host: config path + folder;包: 平铺
public function controllerNamespace(string $moduleFolder): string; // 同上
public function requestPath(string $moduleFolder): string;
```

host 臂在 `Utility::targetContext()` 构造时把 `controller.{app}.path` 等一并注入，生成器统一 `$ctx->controllerPath($folder)` 单点调用，22 处三元逐步收敛。

**硬约束**：
- **⚠ app 维度（复盘补挖的坑，设计时先想清）**：host 的 controller 路径是 `controller.{app}.path`——**随 app 变**（admin/api/…多组），而包臂固定 `app='admin'`。上面概念签名只带了 `$moduleFolder`，落地时必须再带 `$app`（方法参数或 `targetContext($target, $app)` 工厂注入，二选一）。这是止损条款最可能触发的点——先在 CreateControllerGenerator 上把这个维度试通，再扩散其余 12 处。
- host 生成物路径/命名空间**字节不变**（plan-53 决议明文"host 隐含默认:字节不变"，`Utility.php:350` 注释）。
- 先跑 `CodegenOriginTest` + `TargetContextTest` 基线，改完 byte-diff 全量生成物。
- **新方法必须带新用例**（红线 9，2026-07-09 复盘核实缺口）：`TargetContextTest` 现有 6 例全是旧方法；`controllerPath`/`controllerNamespace`/`requestPath` 至少补 4 例——host×(admin/api 两个 app)×带模块段、包臂平铺忽略 folder 参数、host 未配 app 抛错。**先写用例（对着现状预期）再实现方法**。
- **止损条款**：若做到一半发现间接层反而变深、可读性下降（违背"流程式"品味），允许放弃并报告——用户品味优先于 DRY。分批做：先 CreateControllerGenerator（密度最高），验证手感再扩散。

---

## 四、P3：已批项（2026-07-09 用户批准）

### 3.1 ApiController 的 proxy 段析出为独立小控制器（约 160 行）

**背景**：`ApiController` 1376 行住着两个不相干的东西——接口文档/调试参数装配（主体 ~1200 行）+ **HTTP 转发代理**（`proxy` + 5 个私有方法 `buildProxyClient/isAllowedProxyUrl/getAllowedProxyOrigins/normalizeOrigin/buildOrigin`，240-394 行，自成闭环、零共享状态、SSRF 安全敏感）。

**方案**（不引入"Service 层"新概念，沿用本仓已有的小控制器习语——参照 AuthController 77 行 / CloudRedirectController 47 行）：

1. 新建 `src/Http/Controllers/ApiProxyController.php`，整体搬入 `proxy()` + 上述 5 个私有方法，**连同全部 plan 编号注释**。
2. `config()` 助手来自基类 `Http/Controllers/Controller.php:34`，新控制器同样继承即得，无需搬。
3. `buildScopedDebugCacheKey`（`ApiController.php:316`）服务于 cache/param，**留在 ApiController**——执行前 grep 确认 proxy 段没引用它。
4. 路由只换目标类：`routes.php:186-188` 的 `ApiController::class . '@proxy'` → `ApiProxyController`；**path `/api/proxy`、路由名 `api.proxy`、所处组、以及路由自带的 `->middleware('throttle:60,1')`（plan-40 防反射 DDoS）一概原样保留**（Blade/JS 引用路由名，动了会断）。
   （**已核 2026-07-09 复盘**：proxy 段不依赖 ApiController 构造注入的任何东西——`ApiSchemaService`/`AclActionResolver` 均未被 proxy 六方法使用，只用基类 `config()` + `Http`/`Log` facade + `$req`，新控制器走基类构造即可。）
5. 测试不改：`ApiControllerTest` 的 **proxy 七项特征测试**（:61-149——422 / 403 / file-scheme / Http::fake 命中白名单成功路径 / JSON 列表形状保留 / 标量 JSON 不误报 502 / 多值响应头全展示）打的是 HTTP 端点，路径未变应**原样零改动全绿**——body 整形那批 2026-06-10 微妙修复已被全部钉死，这就是"端点行为不变"的机器证明（复盘二核 2026-07-09：覆盖充分，无需补测）。

**验收**：pest 全绿（proxy 测试零改动）+ ApiController 行数减 ~160 且不再 import Http/Log 中 proxy 专用部分。**建议追加真机验收**（需用户/宿主环境配合）：接口调试器点一次"发送"，见 200；填个白名单外 URL，见 403——呼应"UI 必真机验"的既有教训。

### 3.2 拆除 Utility → SchemaLoader 反向依赖（用户拍板做，"有洁癖"）

**现状**：`Utility::getSchemaNames()/schemaOrigin()`（`Utility.php:637-648`）经 `app()` 反调 Designer 层的 SchemaLoader，成环。**调用方已全数摸清——12 处全在 Command 层**，Http/Designer/Generator 零调用：

```
src/Command/Command.php:78,243        src/Command/DbAuditCommand.php:112
src/Command/FreeCommand.php:97,109    src/Command/CreateModelCommand.php:76,93
src/Command/CreateViewCommand.php:51,53
src/Command/CreateTestCommand.php:51,52
tests/Feature/Generator/CodegenOriginTest.php:200-204(直接测 Utility 这俩方法)
```

**方案**（知识挪到正确的层——命令编排层调 Designer 名正言顺）：

1. `Command` 基类新增三个 protected 助手：
   - `schemaNames(): array` → `array_keys(app(SchemaLoader::class)->listSchemaFiles())`
   - `schemaOrigin(string $schema): ?string` → `app(SchemaLoader::class)->originOf($schema)`
   - `hostSchemaNames(): array` → 前者按 origin === null 过滤——**顺手收编** CreateViewCommand:51 与 CreateTestCommand:51 两处一模一样的过滤 lambda（额外去重红利）
2. 12 处调用点从 `$this->utility->getSchemaNames()` 改为 `$this->schemaNames()` 等。
3. **删除** Utility 上的这两个方法（洁癖目标：Utility 回归纯配置/路径/缓存读，不再知道 Designer 的存在）。
4. `CodegenOriginTest:200-204` 改测真源：`SchemaLoader::listSchemaFiles()/originOf()`（断言语义一比一等价，属**有意的测试迁移**，commit message 里说明）。

**验收**：pest 全绿 + `grep -rn "SchemaLoader" src/Utility.php` 零命中 + 抽一条命令真跑冒烟（如宿主里 `moo:free` 走到选 schema 一步能列出全部 schema 含包 schema）。

---

## 五、旧决定重新过堂（2026-07-09 用户授权：旧拍板可带新证据质疑）

2026-05-29 简化深扫用户曾拍板"都不动"。本次逐条重审，**有新证据的重开，没有的维持关闭**：

| 旧决定 | 本次独立复审结论 |
|---|---|
| 拆大类（SchemaLoader/CreateApiGenerator/ApiController） | **大体维持不拆，但破一个边缘个案**。SchemaLoader(2128) 和 CreateApiGenerator(1254) 复读后判定高内聚、方法小、流程顺——拆 = 加间接层，恰违品味基准，真心不拆。唯 ApiController 的 proxy 段是"两个东西住一个类"，见 §3.1（2026-07-09 用户已批准析出）。 |
| `putAndReport` 相关 | **必须重开**：新证据 = 基类助手 0 调用方 vs 11 处手搓——"保留助手"与"手搓遍地"逻辑上不能同时成立，要么收编（P1.1，推荐）要么删助手。二选一，不能再拖。 |
| Foundation 未引用方法 / `getOnlyActionKeys` | **维持不删**（host 面 API，仓内 grep 不可证死——该教训依然成立）。新增建议：这批方法 docblock 加 `@api`（host 消费面）标记，一次成本杜绝未来每轮审查反复误判。 |
| `ConfigSourceScanner::rebuild()` | 重问后用户拍板**"删"**（2026-07-09），已升格为 §二 1.4 执行项（附三重验证）。旧"选择不动"作废。 |
| snapshot 基线 / config UI 写回 / 三态桶 / publish-history | **维持关闭**：本次审查未产生任何新证据，不重开。（重开条件：下游消费面变化或实测数据。） |

---

## 六、明确不做（防执行者跑偏）

- 不引入接口层/事件总线/仓储模式等"企业化"改造——与品味基准直接冲突。
- 不做无热路径证据的性能优化（dev 工具，SchemaLoader 已有实例缓存 + listModules 缓存 + 失效钩子）。
- 不加 phpstan/larastan（2026-07-09 用户已选 pint+pest 口径）。
- 不动 `moo:fresh` 每命令开头刷缓存的设计（deliberate，见 moo-doctor 教训）。
- 不碰 plan-53 已 ship 决议（包 lang 加法混合、软链写权硬线等）。

---

## 七、执行顺序与验收

```
1.3 琐碎 → 1.4 删 rebuild() → 1.1 putAndReport 收编 → 1.2 isForced
  → 2 双路径收敛(分批:先 CreateControllerGenerator 验手感,止损条款有效)
  → 3.1 proxy 析出 → 3.2 依赖环拆除
```

每步验收：`pint --dirty` 干净 + `composer test` 全绿 + Generator 改动附生成物 byte-diff 为空的证明 + 控制台文案逐字不变。全部完成后把执行结果汇总报给用户，并提请真机验收 3.1（接口调试器发送 200/403 各一次）。

commit message 中文、不加 `Co-Authored-By:` 尾注；只 commit+push master，不 bump 版本不打 tag。

---

## 八、复盘披露（2026-07-09 二审：审查覆盖面的诚实边界）

本方案的审查是**主干深读 + 全仓模式 grep**，不是逐行通读。执行者与用户都应知道边界：

- **深读过**：ScaffoldProvider / Utility / Generator 基类（全文）；CreateControllerGenerator 主流程与 buildRequest/buildTrait；SchemaLoader 的 saveModule 写路径；ApiController proxy 全段；routes.php 头部；CreateControllerCommand。
- **只做了结构图（方法签名级）**：SchemaLoader 其余部分、CreateApi/CreateModel/FreshStorage 生成器、ApiController 其余部分、Command 基类。
- **只做了体量盘点，未读**：Designer 其余服务（SchemaDiffService/MigrationWriter/MigrationCompacter/TranslationService/GitInspector/SnapshotStore/YamlFormatter）、Http 其余控制器（Designer/Scaffold/Cloud/Route/Docs/Config/Account）、Adder 方法体、RouterTool、Auth、Foundation 方法体、ConfigManager 方法体、全部 Blade/JS。
- **但 P1/P2 的改造清单是完备的**：三态报告、force 习语、originCtx 分支、rebuild()/getSchemaNames 调用方——全部来自**全仓穷尽 grep**（含 .blade.php），不受抽样限制。清单外不会再冒同类点。
- 未读区**没有任何问题证据**，本方案不为其发明工作。若将来要二轮审查，上面第三条就是菜单。
