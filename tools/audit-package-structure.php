#!/usr/bin/env php
<?php declare(strict_types=1);
/*
 * audit-package-structure —— 只读审计 moo 生态扩展包的**仓库骨架规范**。
 *
 * 目的：回答「哪些包的骨架偏离了 moo 系通行形态」，把「结构规范」从口头约定变成可重跑的闸门。
 * 基准（canonical）是既有基线包的**当前代码**，不是任何历史文档或 skill 文本；
 * 二者冲突时以代码为准。
 *
 * 用法（默认扫本仓同级目录）：
 *   php tools/audit-package-structure.php [--workspace=<同级目录>]
 *                                         [--package=moo-<name>]
 *                                         [--json] [--fail-on-drift]
 *
 * 只读：不写文件、不连数据库、不发网络请求。
 * 退出码：0 = 正常（含仅报告），1 = 传了 --fail-on-drift 且存在**未豁免**的 MISS / STYLE-DRIFT / CONFIG，
 *         2 = 参数或环境错误。
 *
 * 严重级（MISS / STYLE-DRIFT / CONFIG 会让 --fail-on-drift 失败）：
 *   MISS         必需文件 / 目录缺失（豁免清单命中则不计）
 *   STYLE-DRIFT  pint.json 规则集与 canonical 不一致；CLAUDE.md 不是纯入口
 *   CONFIG       机器可判、且会**实际坏事**的配置口径 ——
 *                ① path 仓库的 `versions` 写成约束式：无 composer.lock 的全新安装会被 Composer 直接拒绝
 *                   （`Invalid version string "^2.2.1"`）⇒ fresh clone / CI 装不上；
 *                ② `.gitattributes` 缺基准 `export-ignore` 条目：开发 / 文档 / 流程产物会随 dist
 *                   发进消费方 `vendor/`（只在该路径**本仓确实存在**时才要求，逐仓额外条目不算偏离）。
 *   LAYOUT       布局偏离红线（trait 放错位置、Requests 出现模块段）—— 改动即破坏 namespace，只报不判
 *   NAME         config stem / 命名空间 / provider 类名偏离命名约定 —— 判断题，只报不判
 *   OPTIONAL     GitHub Actions（**仅开源仓**需要）与 `.gitattributes` 裁剪清单 —— 只报不判：
 *                私有包没有 GitHub 仓、且 Gitee 依赖走 clone ⇒ `export-ignore` 当前不生效
 *   INFO         观察项（空骨架、host 发布状态等），无需动作
 *
 * 「MISS」的豁免集中在 ALLOWANCES：纯契约包、内核包、采集包、codegen 工具本身的残缺骨架是**设计如此**，
 * 不是待补。新增豁免必须写明理由，并在本文件留痕。
 * 「开发中」的仓（另会话从零开发、尚无基线）整仓剔除在 DEFERRED_TARGETS，理由随「跳过」段打印；
 * 它不改变任何必需项判据，移除条件写在条目里。
 *
 * 注意：LAYOUT / NAME 类偏差不等于「该改」。家族里存在刻意的例外（moo-monitor-vue 是姊妹前端包，
 * monitor 可能是共享产品 stem；moo-scaffold 的 config stem 是全生态宿主契约），先确认设计意图再动。
 */

const EXIT_OK    = 0;
const EXIT_DRIFT = 1;
const EXIT_USAGE = 2;

/** 严重级排序（报告用） */
const SEVERITY_ORDER = ['MISS' => 0, 'STYLE-DRIFT' => 1, 'CONFIG' => 2, 'LAYOUT' => 3, 'NAME' => 4, 'OPTIONAL' => 5, 'INFO' => 6];

/** 会让 --fail-on-drift 失败的严重级 */
const FAILING_SEVERITY = ['MISS', 'STYLE-DRIFT', 'CONFIG'];

/** 不参与扫描的目录名 */
const SKIP_DIRS = ['vendor', '.git', 'node_modules'];

/**
 * 开发中的目标：整仓剔除、不参与审计 —— **不等于「已合规」**。
 * 只用于「另一个会话正在从零开发、还没有任何基线」的仓；不得用它压掉真实的 MISS / STYLE-DRIFT。
 * 理由与**移除条件**写在值里，报告尾部的「跳过」段与 --json 的 skipped 段原样打印，绝不静默消失。
 * 显式 `--package=<name>` 仍照常审计该仓（用于单独看它的当前状态，不影响默认闸门）。
 *
 * **当前为空**：此前那个「开发中」的仓首次 commit 落地后，按它自己的移除条件纳入审计
 * （补 `allowances()` 条目与 `CLAUDE.md`）—— 它的豁免条目现由下面的私有覆盖文件提供。
 */
const DEFERRED_TARGETS = [];

/**
 * 生态专属数据（canonical 基准包 + 各包豁免）从**不入库**的同目录 `audit-package-structure.private.json` 读取：
 *   {"canonical": ["pkg-a", "pkg-b"], "allowances": {"<package>": {"<path>": "<reason>"}}}
 * 公开仓只保留**公开包**与通用理由模板；未开源包的条目放该文件（`.gitignore` 与 `.gitattributes` 均排除）。
 * 文件缺失时按公开默认运行 —— 私有包会多报 MISS，这是预期（公开克隆本来也拿不到它们的仓）。
 */
function privateOverrides(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $path  = __DIR__ . '/audit-package-structure.private.json';
    $cache = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];

    return $cache;
}

/** canonical 范本包（pint.json 基准取自第一个存在的）；可由私有覆盖文件覆盖 */
function canonicalPackages(): array
{
    $canonical = privateOverrides()['canonical'] ?? [];

    return $canonical !== [] ? $canonical : ['moo-scaffold'];
}

/**
 * 必需项。key = check id，value = [相对路径, 种类 file|dir, 判定说明]。
 */
function requirements(): array
{
    return [
        'composer.json'              => ['composer.json', 'file'],
        'pint.json'                  => ['pint.json', 'file'],
        '.gitignore'                 => ['.gitignore', 'file'],
        'README.md'                  => ['README.md', 'file'],
        'CHANGELOG.md'               => ['CHANGELOG.md', 'file'],
        'AGENTS.md'                  => ['AGENTS.md', 'file'],
        'CLAUDE.md'                  => ['CLAUDE.md', 'file'],
        'NOTES.md'                   => ['NOTES.md', 'file'],
        'TODOS.md'                   => ['TODOS.md', 'file'],
        'phpunit.xml'                => ['phpunit.xml', 'file'],
        'routes/admin.php'           => ['routes/admin.php', 'file'],
        'config/moo-<stem>.php'      => ['config', 'config-dir'],
        'docs/overview.md'           => ['docs/overview.md', 'file'],
        'src/<Provider>.php'         => ['src', 'provider'],
        'scaffold/database'          => ['scaffold/database', 'dir'],
        'lang/zh-CN'                 => ['lang/zh-CN', 'dir'],
        'lang/en'                    => ['lang/en', 'dir'],
        'database/migrations'        => ['database/migrations', 'dir'],
        'src/Models/Traits'          => ['src/Models/Traits', 'dir'],
        'src/Models/Filters'         => ['src/Models/Filters', 'dir'],
        'src/Http/Controllers/Admin' => ['src/Http/Controllers/Admin', 'dir'],
        'src/Http/Requests'          => ['src/Http/Requests', 'dir'],
        'src/Http/Resources'         => ['src/Http/Resources', 'dir'],
        'tests/TestCase.php'         => ['tests/TestCase.php', 'file'],
        'tests/Pest.php'             => ['tests/Pest.php', 'file'],
    ];
}

/**
 * 豁免清单：包名 => [check id => 理由]。
 * 命中即不计 MISS（仍会出现在报告的「豁免」段）。
 */
function allowances(): array
{
    $pureContract = [
        'routes/admin.php'           => '纯契约包：无 HTTP 路由',
        'config/moo-<stem>.php'      => '纯契约包：无配置文件',
        'docs/overview.md'           => '纯契约包：文档在 README 契约表',
        'src/<Provider>.php'         => '纯契约包：零框架依赖，无法继承 ServiceProvider',
        'scaffold/database'          => '纯契约包：无业务表',
        'lang/zh-CN'                 => '纯契约包：无词条',
        'lang/en'                    => '纯契约包：无词条',
        'database/migrations'        => '纯契约包：无迁移',
        'src/Models/Traits'          => '纯契约包：无 Eloquent 层',
        'src/Models/Filters'         => '纯契约包：无 Eloquent 层',
        'src/Http/Controllers/Admin' => '纯契约包：无后台控制器',
        'src/Http/Requests'          => '纯契约包：无请求验证层',
        'src/Http/Resources'         => '纯契约包：无资源层',
        'tests/TestCase.php'         => '纯契约包：测试为纯反射，不加载 Laravel',
        'tests/Pest.php'             => '纯契约包：测试为纯反射，不加载 Laravel',
    ];

    $kernel = [
        'routes/admin.php'           => '内核包：不快照 provider / 路由，装配权在 host',
        'config/moo-<stem>.php'      => '内核包：无配置',
        'src/<Provider>.php'         => '内核包：故意不注册 ServiceProvider',
        'lang/zh-CN'                 => '内核包：无词条',
        'lang/en'                    => '内核包：无词条',
        'database/migrations'        => '内核包：无迁移',
        'src/Models/Traits'          => '内核包：无 Eloquent 层',
        'src/Models/Filters'         => '内核包：无 Eloquent 层',
        'src/Http/Controllers/Admin' => '内核包：无后台控制器',
        'src/Http/Requests'          => '内核包：无请求验证层',
        'src/Http/Resources'         => '内核包：无资源层',
    ];

    $infra = [
        'routes/admin.php'           => '基础设施包：采集/上报能力，无后台管理面',
        'scaffold/database'          => '基础设施包：无自身业务表',
        'lang/zh-CN'                 => '基础设施包：无词条',
        'lang/en'                    => '基础设施包：无词条',
        'database/migrations'        => '基础设施包：无迁移',
        'src/Models/Traits'          => '基础设施包：无 Eloquent 层',
        'src/Models/Filters'         => '基础设施包：无 Eloquent 层',
        'src/Http/Controllers/Admin' => '基础设施包：无后台控制器',
        'src/Http/Requests'          => '基础设施包：无请求验证层',
        'src/Http/Resources'         => '基础设施包：无资源层',
    ];

    $tooling = [
        'routes/admin.php'           => 'codegen 工具本身：后台是 /scaffold/* 自建路由，不走包 admin 路由',
        'scaffold/database'          => 'codegen 工具本身：不是被纳管对象',
        'lang/zh-CN'                 => 'codegen 工具本身：词条随宿主合并，无包级 lang 目录约定',
        'lang/en'                    => 'codegen 工具本身：同上',
        'database/migrations'        => 'codegen 工具本身：自身无业务表',
        'src/Models/Traits'          => 'codegen 工具本身：无 Eloquent 业务层',
        'src/Models/Filters'         => 'codegen 工具本身：无 Eloquent 业务层',
        'src/Http/Controllers/Admin' => 'codegen 工具本身：控制器按 /scaffold/* 分区',
        'src/Http/Resources'         => 'codegen 工具本身：无资源层',
    ];

    $base = [
        'moo-contract'        => $pureContract,
        'moo-monitor-laravel' => $infra + ['name:config-stem' => '刻意共享的产品 stem：与姐妹前端包 moo-monitor-vue 同源，改名会连带发布标签与宿主配置'],
        'moo-scaffold'        => $tooling + [
            'gitattributes:docs' => 'docs/ 是 host 文档中心的包文档源（src/Support/DocsRepository.php 直接读包 basePath 下的 docs/），有意随包分发',
            'name:config-stem'   => '全生态宿主的既有契约：config/ 发布标签与 config(\'scaffold.*\') 读取点遍布所有宿主，改名是破坏性变更',
        ],
        'moo-upload'          => [
            'src/Models/Filters' => '轻控制器包：无列表筛选需求',
            'src/Http/Resources' => '轻控制器包：直接返回数组/DTO',
        ],
    ];

    // 未开源包的豁免条目来自私有覆盖文件（键即包名，值同本函数结构）
    foreach (privateOverrides()['allowances'] ?? [] as $package => $paths) {
        $base[$package] = $paths;
    }

    return $base;
}

/**
 * canonical 的 dist 裁剪清单（`.gitattributes` 的 `export-ignore` 条目）。
 *
 * **只在该路径于本仓确实存在时才要求**：例如 moo-scaffold 没有 .claude / .editorconfig / .phpunit.cache，
 * 要求它去 export-ignore 不存在的路径没有意义。逐仓的**额外**条目（某些包的 /tools、
 * 如 /HANDOFF.md、/.codegen）是刻意的，不算偏离、也不比对整份文件。
 * 有意的「保留」例外走 ALLOWANCES（键形如 `gitattributes:docs`）。
 */
function exportIgnoreRequirements(): array
{
    return ['.claude', '.editorconfig', '.github', '.gitattributes', '.gitignore', '.phpunit.cache', '.vscode',
        'CLAUDE.md', 'NOTES.md', 'TODOS.md', 'composer.ci.json', 'docs', 'phpunit.xml', 'pint.json', 'plans', 'tests'];
}

/**
 * `.gitattributes` 的 dist 裁剪是否达标 —— 缺一条 `export-ignore`，对应的开发/文档/流程产物
 * 就会随 composer dist 发进消费方 `vendor/`（2026-09-28 实测：`plans/` 与 `TODOS.md` 就这样漏过）。
 * 缺整个文件仍由 OPTIONAL 报（不重复计）；host 有自己的清单，不走本函数。
 */
function gitattributesFindings(string $dir, string $package): array
{
    $file = $dir . '/.gitattributes';
    if (! is_file($file)) {
        return [];
    }

    $content = (string) file_get_contents($file);
    $allow   = allowances()[$package] ?? [];
    $missing = [];

    foreach (exportIgnoreRequirements() as $path) {
        if (! file_exists($dir . '/' . $path)) {
            continue;   // 本仓没有这个路径
        }
        if (isset($allow['gitattributes:' . $path])) {
            continue;   // 有意随包分发，豁免里写了理由
        }
        if (preg_match('/^\/' . preg_quote($path, '/') . '\s+export-ignore$/m', $content) !== 1) {
            $missing[] = $path;
        }
    }

    if ($missing === []) {
        return [];
    }

    return ['缺 export-ignore：' . implode(', ', $missing)
        . '（会随 dist 发进消费方 vendor/；基准清单见 docs/package-skeleton.md）'];
}

/**
 * path 仓库的 `versions` 必须是**具体版本**，不能写约束式。
 *
 * 为什么是硬错误：Composer 会把约束式当成版本串去解析，直接抛 `Invalid version string "^2.2.1"`
 * ⇒ **没有 composer.lock 的全新安装（fresh clone / CI）装不上**。本仓有本地 lock 时会被掩盖，
 * 所以只能靠判据抓（2026-09-28：7 个包同时中招，其中一个因此完全装不上依赖）。
 * 兼容 `repositories` 的 list 与 dict 两种形态（生态里两种都有）。
 */
function repositoryVersionFindings(array $composer): array
{
    $raw   = $composer['repositories'] ?? [];
    $items = is_array($raw) && array_is_list($raw) === false ? array_values($raw) : $raw;
    $found = [];

    foreach ((array) $items as $repo) {
        if (! is_array($repo)) {
            continue;
        }
        foreach ((array) ($repo['options']['versions'] ?? []) as $name => $version) {
            if (preg_match('/[\^~*><=@| ]/', (string) $version) === 1) {
                $found[] = sprintf(
                    'repositories["%s"].options.versions["%s"] = "%s" 是约束式，必须写具体版本（如 "2.2.8"）；'
                    . '否则无 composer.lock 的 fresh install 会被 Composer 拒绝（Invalid version string）',
                    (string) ($repo['type'] ?? '?'),
                    (string) $name,
                    (string) $version,
                );
            }
        }
    }

    return $found;
}

/**
 * LAYOUT 检查：目录名不匹配任何控制器名时视为「模块段」。
 * Requests 规范是 src/Http/Requests/<Controller>/，<Controller> 应能在控制器目录里找到同名类。
 */
function layoutCheck(string $packageDir, string $package, array &$waived = []): array
{
    $findings = [];

    // ① model trait 放在 Models/Concerns（规范要求 Models/Traits，codegen 硬编码 emit {ns}Traits\）
    $concerns = glob($packageDir . '/src/Models/Concerns/*.php') ?: [];
    if ($concerns !== []) {
        $findings[] = sprintf(
            'src/Models/Concerns/ 有 %d 个 trait（规范要求 src/Models/Traits/）：%s',
            count($concerns),
            implode(', ', array_map('basename', $concerns)),
        );
    }

    // ② src/Concerns 下的 trait（scaffold / monitor-laravel 是各自的既有形态）
    if (! in_array($package, ['moo-scaffold', 'moo-monitor-laravel'], true)) {
        $srcConcerns = glob($packageDir . '/src/Concerns/*.php') ?: [];
        if ($srcConcerns !== []) {
            $findings[] = sprintf(
                'src/Concerns/ 有 %d 个类（model trait 应在 src/Models/Traits/）：%s',
                count($srcConcerns),
                implode(', ', array_map('basename', $srcConcerns)),
            );
        }
    }

    // ③ Requests 模块段：请求目录名在控制器里找不到同名类。
    //    scaffold 自己的后台是 /scaffold/* 分区式 UI，不走 Requests/<Controller>/ 约定，整体跳过；
    //    Concerns/ 是跨控制器复用的 Request trait（家族既有写法），不是模块段。
    if ($package !== 'moo-scaffold') {
        $controllerNames = [];
        foreach (array_merge(
                glob($packageDir . '/src/Http/Controllers/{Admin,Web}/*.php', GLOB_BRACE) ?: [],
                glob($packageDir . '/src/Http/Controllers/*.php') ?: [],
            ) as $file) {
            $controllerNames[] = strtolower(str_replace('Controller.php', '', basename($file)));
        }
        foreach (glob($packageDir . '/src/Http/Requests/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $segment = basename($dir);
            if (strtolower($segment) === 'concerns') {
                continue;
            }
            if (! in_array(strtolower($segment), $controllerNames, true)) {
                $allowKey = 'src/Http/Requests/' . $segment;
                if (isset(allowances()[$package][$allowKey])) {
                    $waived[$allowKey] = allowances()[$package][$allowKey];

                    continue;
                }
                $count      = count(glob($dir . '/*.php') ?: []);
                $findings[] = sprintf(
                    'src/Http/Requests/%s/ 是模块段而非 <Controller>/（%d 个 Request，规范按控制器分组）',
                    $segment,
                    $count,
                );
            }
        }
    }

    return $findings;
}

/**
 * 读 canonical pint.json 的 rules（语义比对：只比 rules 的键值，不比缩进/键序）。
 */
function canonicalPintRules(string $workspace): ?array
{
    foreach (canonicalPackages() as $name) {
        $path = $workspace . '/' . $name . '/pint.json';
        if (is_file($path)) {
            $json = json_decode((string) file_get_contents($path), true);
            if (is_array($json['rules'] ?? null)) {
                return $json['rules'];
            }
        }
    }

    return null;
}

/** 语义比对两个 rules 数组，返回差异描述。 */
function pintRulesDiff(array $actual, array $expected): array
{
    $missing = array_keys(array_diff_key($expected, $actual));
    $extra   = array_keys(array_diff_key($actual, $expected));

    $changed = [];
    foreach (array_intersect_key($expected, $actual) as $key => $value) {
        if ($value !== $actual[$key]) {
            $changed[] = $key;
        }
    }

    return [$missing, $extra, $changed];
}

/** 审计单个扩展包。 */
function auditPackage(string $dir, string $workspace, ?array $canonicalRules): array
{
    $package = basename($dir);
    $allow   = allowances()[$package] ?? [];

    $drift  = ['MISS' => [], 'STYLE-DRIFT' => [], 'CONFIG' => [], 'LAYOUT' => [], 'NAME' => [], 'OPTIONAL' => [], 'INFO' => []];
    $waived = [];

    $composer = json_decode((string) @file_get_contents($dir . '/composer.json'), true) ?: [];

    // 命名空间前缀（psr-4），用于推导 provider 类名
    $psr4     = $composer['autoload']['psr-4'] ?? [];
    $prefix   = (string) (array_key_first($psr4) ?? '');
    $psr4Path = $prefix !== '' ? (string) $psr4[$prefix] : '';

    $stem = $package;   // <package> → config/<package>.php

    foreach (requirements() as $id => [$rel, $kind]) {
        $exists = match ($kind) {
            'dir'        => is_dir($dir . '/' . $rel),
            'config-dir' => glob($dir . '/' . $rel . '/*.php') !== [],
            'provider'   => glob($dir . '/src/*Provider.php')  !== [],
            default      => is_file($dir . '/' . $rel),
        };

        if ($exists) {
            continue;
        }

        if (isset($allow[$id])) {
            $waived[$id] = $allow[$id];

            continue;
        }

        $drift['MISS'][] = $rel . ' 缺失';
    }

    // 插入标记：删了 codegen 插路由就断
    $routesAdmin = $dir . '/routes/admin.php';
    if (is_file($routesAdmin) && ! str_contains((string) file_get_contents($routesAdmin), ':insert_code_here:do_not_delete')) {
        $drift['LAYOUT'][] = 'routes/admin.php 缺 :insert_code_here:do_not_delete 插入标记（生成路由会断）';
    }

    // NAME：命名空间 / provider 类名 / config stem 的约定
    // 期望值从**包名**推导（不是从现况反推），否则永远自洽、查不出偏离。
    $expectedNs       = 'Mooeen\\' . str_replace('-', '', ucwords(substr($package, 4), '-')) . '\\';
    $expectedProvider = trim(str_replace('\\', '', $expectedNs)) . 'ServiceProvider';

    if ($prefix !== '' && $prefix !== $expectedNs) {
        $drift['NAME'][] = sprintf('psr-4 命名空间为 %s（按包名约定为 %s）', $prefix, $expectedNs);
    }
    if ($prefix !== '' && $psr4Path !== 'src/') {
        $drift['NAME'][] = sprintf('psr-4 target 为 %s（约定为 src/）', $psr4Path);
    }

    $providerFiles = glob($dir . '/src/*Provider.php') ?: [];
    if ($providerFiles !== []) {
        $actualProvider = basename($providerFiles[0], '.php');
        if ($actualProvider !== $expectedProvider) {
            $drift['NAME'][] = sprintf(
                'provider 类名为 %s（按包名约定为 %s）—— 改为破坏性变更，需同步消费方',
                $actualProvider,
                $expectedProvider,
            );
        }
    }

    $configFiles = glob($dir . '/config/*.php') ?: [];
    if ($configFiles !== []) {
        $configStem = basename($configFiles[0], '.php');
        if ($configStem !== $stem) {
            if (isset($allow['name:config-stem'])) {
                $waived['name:config-stem'] = $allow['name:config-stem'];
            } else {
                $drift['NAME'][] = sprintf(
                'config stem 为 %s（按 moo-<name> 约定应为 %s）—— 判断题：确认是否为刻意的共享产品 stem',
                    $configStem,
                    $stem,
                );
            }
        }
    }

    // STYLE-DRIFT：pint.json 规则集
    if ($canonicalRules !== null && is_file($dir . '/pint.json')) {
        $actual = json_decode((string) file_get_contents($dir . '/pint.json'), true);
        if (is_array($actual['rules'] ?? null)) {
            [$missing, $extra, $changed] = pintRulesDiff($actual['rules'], $canonicalRules);
            if ($missing !== []) {
                $drift['STYLE-DRIFT'][] = 'pint.json 缺规则：' . implode(', ', $missing);
            }
            if ($extra !== []) {
                $drift['STYLE-DRIFT'][] = 'pint.json 多出规则：' . implode(', ', $extra);
            }
            if ($changed !== []) {
                $drift['STYLE-DRIFT'][] = 'pint.json 规则取值不同：' . implode(', ', $changed);
            }
        } else {
            $drift['STYLE-DRIFT'][] = 'pint.json 无可解析的 rules';
        }
    }

    // STYLE-DRIFT：CLAUDE.md 只作入口 —— 规则一律写进 AGENTS.md，CLAUDE.md 只指明去读它。
    // 依据：全局 AGENTS.md「CLAUDE.md 等其它工具的入口文件只作入口，不复制规则」；
    // 且本工具只读 AGENTS.md 层级，写在 CLAUDE.md 里的规则对 Command Code 不可见，复制等于分叉出第二份真相。
    $claudePath = $dir . '/CLAUDE.md';
    if (is_file($claudePath)) {
        $claudeLines = substr_count((string) file_get_contents($claudePath), "\n") + 1;
        if (! str_contains((string) file_get_contents($claudePath), 'AGENTS.md')) {
            $drift['STYLE-DRIFT'][] = 'CLAUDE.md 未指向 AGENTS.md（约定：只作入口，不复制规则）';
        } elseif ($claudeLines > 8) {
            $drift['STYLE-DRIFT'][] = sprintf(
                'CLAUDE.md 有 %d 行（约定 ≤ 8 行：只指明去读 AGENTS.md，规则写进 AGENTS.md）',
                $claudeLines,
            );
        }
    }

    // CONFIG：path 仓库的 versions 必须是具体版本（约束式 ⇒ fresh install 装不上）
    foreach (repositoryVersionFindings($composer) as $finding) {
        $drift['CONFIG'][] = $finding;
    }

    // OPTIONAL：dist 裁剪清单 —— **只报告、不判失败**。仓库私有、无 GitHub 镜像，而 Composer 对 Gitee
    // 没有 dist driver ⇒ 依赖一律 `git clone`（实测 installed.json: dist=False source=True），
    // `export-ignore` 实际不生效（只有从 Packagist 装 dist 的公开包才受益）。留着无害且「将来开源/切 dist 即生效」，
    // 但不该把它当硬门禁去守一个当前无效的机制。
    foreach (gitattributesFindings($dir, $package) as $finding) {
        $drift['OPTIONAL'][] = $finding;
    }

    // OPTIONAL：分发方式相关的骨架，收益取决于是否走 dist / 是否启用 GitHub 镜像
    // 只有**开源包**需要 GitHub Actions：私有包没有 GitHub 仓，workflow 永远不会跑。
    // 开源集合（2026-09-28 用户确认 + 实测）：包 = moo-feedback / moo-scaffold；
    // 非包目标另有 moo-engine-skeleton（host，走 auditHost 不套本条）/ moo-chrome-dev-tool / moo-git-fleet /
    // moo-monitor-vue。开源仓一律走 Gitee + GitHub **双源直推**，5 份 mirror-from-gitee 定时镜像 workflow 已删。
    if (in_array($package, ['moo-feedback', 'moo-scaffold'], true) && glob($dir . '/.github/workflows/*.yml') === []) {
        $drift['OPTIONAL'][] = '缺 .github/workflows/（仅开源仓需要；范本 moo-scaffold/.github/workflows/quality.yml）';
    }
    if (! is_file($dir . '/.gitattributes')) {
        $drift['OPTIONAL'][] = '缺 .gitattributes（composer dist 裁剪；仅在按 dist 分发时有效，形状需先定）';
    }

    // LAYOUT
    foreach (layoutCheck($dir, $package, $waived) as $finding) {
        $drift['LAYOUT'][] = $finding;
    }

    // INFO：空骨架
    if (is_dir($dir . '/src/Models/Traits') && glob($dir . '/src/Models/Traits/*.php') === []) {
        $drift['INFO'][] = 'src/Models/Traits/ 为空：已初始化但代码尚未生成的骨架';
    }

    return [
        'package'  => $package,
        'path'     => $dir,
        'kind'     => 'PACKAGE',
        'composer' => (string) ($composer['name'] ?? ''),
        'drift'    => array_filter($drift),
        'waived'   => $waived,
    ];
}

/** 审计 host 骨架：三份 manifest 分流 + 接入面。 */
function auditHost(string $dir): array
{
    $engine = $dir . '/engine';
    $drift  = ['MISS' => [], 'STYLE-DRIFT' => [], 'CONFIG' => [], 'LAYOUT' => [], 'NAME' => [], 'OPTIONAL' => [], 'INFO' => []];

    $profiles  = ['composer.json', 'composer.test.json', 'composer.production.json'];
    $manifests = [];

    foreach ($profiles as $profile) {
        $path = $engine . '/' . $profile;
        if (! is_file($path)) {
            $drift['MISS'][] = 'engine/' . $profile . ' 缺失';

            continue;
        }
        $manifests[$profile] = json_decode((string) file_get_contents($path), true) ?: [];
    }

    if (count($manifests) === count($profiles)) {
        // 直接依赖集合必须三份一致
        $requireSets = array_map(
            static fn (array $m): array => array_keys(array_filter(
                $m['require'] ?? [],
                static fn (string $pkg): bool => str_starts_with($pkg, 'charsen/moo-'),
                1,
            )),
            $manifests,
        );
        $sets = array_map(static function (array $keys): string {
            sort($keys);

            return implode(',', $keys);
        }, $requireSets);
        if (count(array_unique($sets)) !== 1) {
            $drift['STYLE-DRIFT'][] = '三份 manifest 的 moo 包直接依赖集合不一致：'
                . implode(' | ', array_map(
                    static fn (string $p, string $s): string => $p . '=[' . $s . ']',
                    array_keys($sets),
                    $sets,
                ));
        }

        // extra.moo-private-packages 必须三份一致
        $extraSets = [];
        foreach ($manifests as $profile => $m) {
            $names = array_map(
                static fn (array $entry): string => (string) ($entry['name'] ?? ''),
                $m['extra']['moo-private-packages'] ?? [],
            );
            sort($names);
            $extraSets[$profile] = implode(',', $names);
        }
        if (count(array_unique($extraSets)) !== 1) {
            $drift['STYLE-DRIFT'][] = '三份 manifest 的 extra.moo-private-packages 不一致：'
                . implode(' | ', array_map(
                    static fn (string $p, string $s): string => $p . '=[' . $s . ']',
                    array_keys($extraSets),
                    $extraSets,
                ));
        }

        // 生产档约束不得伪装可发布
        foreach ($manifests['composer.production.json']['require'] ?? [] as $pkg => $constraint) {
            if (! str_starts_with((string) $pkg, 'charsen/moo-')) {
                continue;
            }
            if (preg_match('/(@| as |dev|DEV)/', (string) $constraint) === 1) {
                $drift['STYLE-DRIFT'][] = sprintf(
                    '生产档 %s 约束 %s 含 dev 分支语义（生产必须为已发布稳定 semver）',
                    $pkg,
                    $constraint,
                );
            }
        }

        // 本地档 repository 必须为 path
        foreach ($manifests['composer.json']['repositories'] ?? [] as $key => $repo) {
            if (($repo['type'] ?? '') !== 'path') {
                $drift['INFO'][] = sprintf('本地档 repository[%s] 的 type 为 %s（本地约定 path）', $key, $repo['type'] ?? '?');
            }
        }
    }

    // 接入面
    if (is_file($engine . '/bootstrap/providers.php')) {
        $drift['INFO'][] = 'bootstrap/providers.php 存在（host 胶水层注册点）';
    } else {
        $drift['MISS'][] = 'engine/bootstrap/providers.php 缺失';
    }
    if (! is_dir($engine . '/app/Moo')) {
        $drift['MISS'][] = 'engine/app/Moo/ 缺失（host 契约胶水层约定目录）';
    }
    foreach (['config/scaffold.php', 'config/moo-monitor.php'] as $published) {
        if (! is_file($engine . '/' . $published)) {
            $drift['INFO'][] = 'engine/' . $published . ' 未发布（若 host 未消费对应包属正常）';
        }
    }

    if (! is_file($dir . '/CHANGELOG.md')) {
        $drift['INFO'][] = '仓库根无 CHANGELOG.md（host 骨架，各包各自维护）';
    }

    return [
        'package'  => basename($dir),
        'path'     => $dir,
        'kind'     => 'HOST',
        'composer' => (string) ($manifests['composer.json']['name'] ?? ''),
        'drift'    => array_filter($drift),
        'waived'   => [],
    ];
}

// ---------------------------------------------------------------- 主流程

$options   = getopt('', ['workspace::', 'package::', 'json', 'fail-on-drift']);
$workspace = rtrim((string) ($options['workspace'] ?? dirname(__DIR__, 2)), '/');
$onlyPkg   = isset($options['package']) ? (string) $options['package'] : null;

if (! is_dir($workspace)) {
    fwrite(STDERR, "workspace 不存在: {$workspace}\n");
    exit(EXIT_USAGE);
}

$canonicalRules = canonicalPintRules($workspace);

$targets = [];
$skipped = [];

foreach (glob($workspace . '/moo-*', GLOB_ONLYDIR) ?: [] as $dir) {
    $name = basename($dir);
    if (in_array($name, SKIP_DIRS, true)) {
        continue;
    }
    if ($onlyPkg !== null && $name !== $onlyPkg) {
        continue;
    }

    if ($onlyPkg === null && isset(DEFERRED_TARGETS[$name])) {
        $skipped[] = [$name, '开发中，整仓剔除 —— ' . DEFERRED_TARGETS[$name]];

        continue;
    }

    $composerPath = $dir . '/composer.json';

    if ($name === 'moo-engine-skeleton') {
        $targets[] = auditHost($dir);

        continue;
    }

    if (! is_file($composerPath)) {
        $skipped[] = [$name, '无 composer.json'];

        continue;
    }

    $composer = json_decode((string) file_get_contents($composerPath), true) ?: [];
    $type     = (string) ($composer['type'] ?? '');
    $pkgName  = (string) ($composer['name'] ?? '');

    if ($type !== 'library' || ! str_starts_with($pkgName, 'charsen/moo-')) {
        $skipped[] = [$name, sprintf('非扩展包（type=%s, name=%s）', $type !== '' ? $type : '?', $pkgName !== '' ? $pkgName : '?')];

        continue;
    }

    $targets[] = auditPackage($dir, $workspace, $canonicalRules);
}

usort($targets, static fn (array $a, array $b): int => strcmp($a['package'], $b['package']));

// 汇总
$summary = ['targets' => count($targets), 'clean' => 0, 'MISS' => 0, 'STYLE-DRIFT' => 0, 'CONFIG' => 0, 'LAYOUT' => 0, 'NAME' => 0, 'OPTIONAL' => 0, 'INFO' => 0];
$failing = 0;

foreach ($targets as $target) {
    $hasFailing = false;
    foreach (FAILING_SEVERITY as $severity) {
        $count = count($target['drift'][$severity] ?? []);
        $summary[$severity] += $count;
        if ($count > 0) {
            $hasFailing = true;
        }
    }
    foreach (['LAYOUT', 'NAME', 'OPTIONAL', 'INFO'] as $severity) {
        $summary[$severity] += count($target['drift'][$severity] ?? []);
    }
    if ($hasFailing) {
        $failing++;
    } else {
        $summary['clean']++;
    }
}

if (isset($options['json'])) {
    echo json_encode([
        'workspace' => $workspace,
        'canonical' => canonicalPackages(),
        'summary'   => $summary,
        'targets'   => $targets,
        'skipped'   => array_map(static fn (array $s): array => ['dir' => $s[0], 'reason' => $s[1]], $skipped),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

    exit(isset($options['fail-on-drift']) && $failing > 0 ? EXIT_DRIFT : EXIT_OK);
}

echo "工作区: {$workspace}\n";
echo 'canonical 基准: ' . implode(' + ', canonicalPackages()) . "\n";
echo '扫描目标: ' . count($targets) . ' 个（跳过 ' . count($skipped) . " 个：非扩展包 / DEFERRED_TARGETS）\n\n";

foreach ($targets as $target) {
    $total = array_sum(array_map('count', $target['drift']));
    $mark  = $total === 0 ? '✓' : '·';

    echo "{$mark} [{$target['kind']}] {$target['package']}  ({$target['composer']})\n";

    foreach ($target['drift'] as $severity => $items) {
        foreach ($items as $item) {
            echo "    {$severity}  {$item}\n";
        }
    }

    if ($target['waived'] !== []) {
        echo '    豁免 ' . count($target['waived']) . ' 项：' . implode('；', array_map(
            static fn (string $id, string $reason): string => "{$id}（{$reason}）",
            array_keys($target['waived']),
            $target['waived'],
        )) . "\n";
    }
}

if ($skipped !== []) {
    echo "\n跳过：\n";
    foreach ($skipped as [$name, $reason]) {
        echo "  - {$name}：{$reason}\n";
    }
}

echo "\n汇总：目标 {$summary['targets']} 个；无 MISS/STYLE-DRIFT/CONFIG 的 {$summary['clean']} 个；"
    . "MISS {$summary['MISS']} / STYLE-DRIFT {$summary['STYLE-DRIFT']} / CONFIG {$summary['CONFIG']} / "
    . "LAYOUT {$summary['LAYOUT']} / NAME {$summary['NAME']} / "
    . "OPTIONAL {$summary['OPTIONAL']} / INFO {$summary['INFO']}\n";
echo 'MISS / STYLE-DRIFT / CONFIG 会判失败；LAYOUT / NAME / OPTIONAL / INFO 只报告'
    . "（改动即破坏 namespace 或跨 host 契约，需先确认设计意图）。\n";

if (isset($options['fail-on-drift']) && $failing > 0) {
    fwrite(STDERR, "\n{$failing} 个目标存在未豁免的 MISS / STYLE-DRIFT / CONFIG。\n");
    exit(EXIT_DRIFT);
}

exit(EXIT_OK);
