<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Designer;

use Mooeen\Scaffold\Support\AppTargetRegistry;
use Mooeen\Scaffold\Support\ColumnTypeGroups;
use Mooeen\Scaffold\Support\ControllerName;

/**
 * `saveModule` 的「client save payload → yaml table 结构」归一计算（2026-09-20 自 SchemaLoader 外迁）。
 *
 * 为什么外迁（第 5 项）—— 判据是**垂直切片**，不是「文件行数多」：
 *   这一族 12 个方法原先都是 SchemaLoader 的 private 成员，用「每个私有方法的 public 入口可达集」
 *   反向闭包一扫就看得出它们**只被 `saveModule` 一个入口可达**，且除
 *   `applyEnums → sanitizeEnumLabel`（仍在族内）外不调任何族外方法、不读任何实例属性
 *   ⇒ 切它**调用次数不变、间接层不增加**（对比：同文件的 `normalize` 族被 8 个 public 入口可达，
 *   切它要动 8 个入口 = 「加间接层」，因此那一族**不动**）。
 *   补充证据：`SchemaLoader.php` 的 v6.3 #3 注释自己写着该段已从 200 行平铺拆成 6 个命名 sub-method
 *   ⇒ 「族」早已存在且已命名，本次只是把它换个住处，不是新发明一层抽象。
 *
 * 方法映射（旧 private 实例方法 → 新 public static）：
 *   applyModuleBlock     → applyModuleBlock          （&$raw：整份 yaml 的 module 块）
 *   changeSnapshot       → changeSnapshot            （扣 created_ / updated_ 前缀 audit 字段的语义快照）
 *   applyTableAttrs      → applyTableAttrs
 *   applyTableModel      → applyTableModel
 *   applyTableController → applyTableController
 *   applyRenameHints     → applyRenameHints          （&$yamlFields / &$yamlTable：两个引用参数）
 *   rebuildFieldRows     → rebuildFieldRows
 *   sortRowAttrs         → sortRowAttrs              （private，只给 rebuildFieldRows 用）
 *   rebuildTableIndex    → rebuildTableIndex
 *   applyEnums           → applyEnums
 *   sanitizeEnumLabel    → sanitizeEnumLabel         （public：SchemaLoader::sanitizeFieldAttrs 也调它）
 *   coerceFieldValue     → coerceFieldValue          （private，只给 rebuildFieldRows 用）
 *
 * 为什么 `final` + 全静态：与 `Paths` / `ControllerName` / `ApiParameterFormatter` 同形 ——
 * 零属性、不读 `config()`、不碰 FS/DB、不持有 memo。**跨请求敏感的状态一律留在调用方**
 * （`$cache` / `$listModulesCache` / origin 守卫 / `updated_*` stamp 全在 SchemaLoader::saveModule）。
 *
 * ⚠ 唯一一处非纯计算：`applyTableController` 在 `$origin === null`（host schema 语境）时会调
 *   `app(AppTargetRegistry::class)->assertConfigured(...)` 校验端名。这是**原样搬过来的既有行为**
 *   （属包的 schema 传非 null $origin 即不校验，端契约归包自身）。刻意不做「注入校验回调」的改造：
 *   那会改签名 + 在调用方加一层闭包，超出「只搬代码」的范围。要收口请另开一项。
 *
 * 历史瑕疵已单独收口（2026-09-20，第 5 项之后的小项）：`sanitizeEnumLabel` 之前那个 docblock 讲的
 *   其实是 `coerceFieldValue`（两个 docblock 历史上连着写、挂错了位置）。搬迁时逐字照搬**未修**，
 *   以免把「搬代码」与「排版清理」混在同一次改动里；现已在独立一项里把该 docblock 移回
 *   `coerceFieldValue` 头上，并由 `SchemaPayloadMergerTest` §13 的结构锚点守住（挪回去即红）。
 *   纯注释移动，零行为变化。
 */
final class SchemaPayloadMerger
{
    /** module 块:仅当 client 传了非空内容才 merge */
    public static function applyModuleBlock(array &$raw, array $client): void
    {
        if (is_array($client['module'] ?? null) && ! empty($client['module'])) {
            $raw['module'] = array_merge((array) ($raw['module'] ?? []), $client['module']);
        }
    }

    /**
     * 表语义快照 — 剔除 stamp 字段自身,用于 saveModule 判断"真改动"(避免 round-trip save 刷 updated_*)。
     * 包含 attrs(扣 audit)/ model / controller / fields / index / enums 全部业务语义块。
     */
    public static function changeSnapshot(array $yamlTable): array
    {
        $attrs = $yamlTable['attrs'] ?? [];
        unset($attrs['created_by'], $attrs['created_at'], $attrs['updated_by'], $attrs['updated_at']);

        return [
            'attrs'      => $attrs,
            'model'      => $yamlTable['model']      ?? null,
            'controller' => $yamlTable['controller'] ?? null,
            'fields'     => $yamlTable['fields']     ?? null,
            'index'      => $yamlTable['index']      ?? null,
            'enums'      => $yamlTable['enums']      ?? null,
        ];
    }

    /** 表 attrs:覆盖 name / desc / prefix(F29 字段前缀持久化) */
    public static function applyTableAttrs(array $yamlTable, array $cTable): array
    {
        if (array_key_exists('name', $cTable)) {
            $yamlTable['attrs']['name'] = $cTable['name'];
        }
        foreach (['desc', 'prefix'] as $attr) {
            if (! array_key_exists($attr, $cTable)) {
                continue;
            }
            $val = $cTable[$attr];
            if ($val === null || $val === '') {
                unset($yamlTable['attrs'][$attr]);
            } else {
                $yamlTable['attrs'][$attr] = $val;
            }
        }

        return $yamlTable;
    }

    /**
     * plan 19 v11:写 yaml.model.class(若 client 传)。class 为空 → 整个 model 节点删除。
     */
    public static function applyTableModel(array $yamlTable, array $cTable): array
    {
        if (! array_key_exists('model', $cTable) || ! is_array($cTable['model'])) {
            return $yamlTable;
        }
        $class = trim((string) ($cTable['model']['class'] ?? ''));
        // plan-37 后审 P1:class 清空时只删 class 这一个 key,保留 app/resource/factory 等子配置,
        // 避免「编辑 model 配置 modal 把 class 清空」这种操作 silently 把整节点 unset 导致数据丢失。
        $existing = (array) ($yamlTable['model'] ?? []);
        if ($class === '') {
            unset($existing['class']);
            $yamlTable['model'] = $existing;
            if ($yamlTable['model'] === []) {
                unset($yamlTable['model']);
            }
        } else {
            $existing['class']  = $class;
            $yamlTable['model'] = $existing;
        }

        return $yamlTable;
    }

    /**
     * plan 19 v11:写 yaml.controller.{class, app, resource}(若 client 传)。
     * - class 为空 → 整个 controller 节点删除
     * - app 数组(必填,空数组也保留)
     * - resource 数组(可选,空数组 → 删除 resource key)
     *
     * plan-37 后审 P1:class 清空时只删 class 这一个 key,保留 app/resource 等子配置,
     * 不再静默 unset 整个 controller 节点(数据丢失风险)。
     */
    public static function applyTableController(array $yamlTable, array $cTable, ?string $origin = null): array
    {
        if (! array_key_exists('controller', $cTable) || ! is_array($cTable['controller'])) {
            return $yamlTable;
        }
        $cCtrl    = $cTable['controller'];
        $class    = trim((string) ($cCtrl['class'] ?? ''));
        $existing = (array) ($yamlTable['controller'] ?? []);
        // 2026-05-20 用户反馈:user 选了「生成到:后台管理/接口」+「Resource 到」toggle 后 save,
        // 但 controller class 暂时为空 → 之前路径整段 unset,刷新后 toggle 状态丢失。
        // 修法:class 不再门控 app/resource — user 可以先选 app/resource(意图持久化),
        // 之后填 class 时直接生成。仅 class+app+resource 都空时整个 unset。
        if ($class === '') {
            unset($existing['class']);
        } else {
            // 2026-05-21 归一化:controller class 必带 Controller 后缀,跟 generator 端一致 ——
            // 收口到 ControllerName::ensure 单一真源。
            // designer GUI 用户漏写后缀(只填 "Memo")会让 routes 引用 MemoController 但文件名 Memo.php → 类找不到 → 接口调试 sidebar 缺该模块。
            $existing['class'] = ControllerName::ensure($class);
        }
        if (array_key_exists('app', $cCtrl) && is_array($cCtrl['app'])) {
            $appList = array_values(array_filter(
                array_map('strval', $cCtrl['app']),
                static fn ($s) => $s !== '',
            ));
            // host schema 由 host 注册表约束；扩展包 schema 的端契约归包自身。
            if ($origin === null) {
                app(AppTargetRegistry::class)->assertConfigured($appList, 'controller.app');
            }
            if ($appList === []) {
                unset($existing['app']);
            } else {
                $existing['app'] = $appList;
            }
        }
        if (array_key_exists('resource', $cCtrl)) {
            $resource = is_array($cCtrl['resource']) ? array_values(array_filter(
                array_map('strval', $cCtrl['resource']),
                static fn ($s) => $s !== '',
            )) : [];
            if ($origin === null) {
                app(AppTargetRegistry::class)->assertConfigured($resource, 'controller.resource');
            }
            if ($resource === []) {
                unset($existing['resource']);
            } else {
                $existing['resource'] = $resource;
            }
        }
        // 整段 controller 都空才 unset
        if ($existing === []) {
            unset($yamlTable['controller']);

            return $yamlTable;
        }
        $yamlTable['controller'] = $existing;

        return $yamlTable;
    }

    /**
     * client.confirmRename 把 {oldKey: newKey} 塞进 rename_hints。
     * 必须先把 yamlFields[oldKey] 搬到 newKey(继承 type / required 等 attrs),
     * 否则后续 attr 合并 newKey 查不到 row=[],原 attrs 丢光。
     * 同时 table.index 块里引用 oldKey 的 entry 同步改 newKey + idx name(若 name==oldKey)。
     */
    public static function applyRenameHints(array &$yamlFields, array &$yamlTable, array $renameHints): void
    {
        foreach ($renameHints as $oldKey => $newKey) {
            $oldKey = (string) $oldKey;
            $newKey = (string) $newKey;
            if ($oldKey === '' || $newKey === '' || $oldKey === $newKey) {
                continue;
            }
            // plan-38 P0-SEC-4 / plan-40 §二:rename_hints 后端零校验是 PHP/SQL 注入入口,
            // newKey 必须 ^[a-z_][a-z0-9_]*$ + 长度 <= 64,否则静默跳过(防恶意 session 污染)
            // 首 `_` 放行:Laravel-NestedSet `_lft / _rgt` 工业惯例字段名
            if (! preg_match('/^[a-z_][a-z0-9_]*$/', $newKey) || strlen($newKey) > 64) {
                continue;
            }
            // 字段真改名了才动:目标名已存在(撞名)或源名不存在 → 整条 hint 跳过。
            // 原先字段改名守在 if 里、索引重写却无条件执行 → 撞名时字段保留旧名但索引被指到新名
            // (一个已存在的别的字段)→ 索引落在错字段上(2026-06-10 修,防 client/DevTools 绕过)。
            $renamed = isset($yamlFields[$oldKey]) && ! isset($yamlFields[$newKey]);
            if (! $renamed) {
                continue;
            }
            $yamlFields[$newKey] = $yamlFields[$oldKey];
            unset($yamlFields[$oldKey]);

            $yamlIndex = (array) ($yamlTable['index'] ?? []);
            if ($yamlIndex === []) {
                continue;
            }
            $rewritten = [];
            foreach ($yamlIndex as $idxName => $entry) {
                $f = is_array($entry) ? ($entry['fields'] ?? null) : null;
                if ($f === $oldKey) {
                    // 单字段索引(string 形式)
                    $entry['fields']       = $newKey;
                    $nameAfter             = ($idxName === $oldKey) ? $newKey : $idxName;
                    $rewritten[$nameAfter] = $entry;
                } elseif (is_array($f) && in_array($oldKey, $f, true)) {
                    // 数组形式(单或多字段复合索引)—— 原来只处理 count===1,多字段复合索引会保留旧字段名
                    // → 改名后 yaml 索引引用不存在的列、破坏下次 diff/migration(2026-06-09 修)。
                    $mapped                = array_map(static fn ($c) => $c === $oldKey ? $newKey : $c, $f);
                    $entry['fields']       = count($mapped) === 1 ? reset($mapped) : array_values($mapped);
                    $nameAfter             = ($idxName === $oldKey) ? $newKey : $idxName;
                    $rewritten[$nameAfter] = $entry;
                } else {
                    $rewritten[$idxName] = $entry;
                }
            }
            $yamlTable['index'] = $rewritten;
        }
    }

    /**
     * 字段:client.fields 决定字段集 + 顺序,每个 field 保留 yaml 原 row,
     * 只覆盖 client 显式传的 attrs;client 传 null/'' 视为 unset。
     *
     * $writable = $allowNew(currently identical):index 走表级 table.index 块回写(F12);
     *   type 必须允许新增(F22),否则新字段无 type;
     *   required 允许新增(F26):client 用 dirty-tracking 只在 user 改过 nullable 时才发,
     *   所以这里加 required 不会被 shape 派生值污染 yaml。
     */
    public static function rebuildFieldRows(array $yamlFields, array $clientFields): array
    {
        $writable  = ['type', 'size', 'required', 'default', 'comment', 'unsigned', 'precision', 'format'];
        $newFields = [];
        foreach ($clientFields as $cField) {
            $key = $cField['name'] ?? null;
            if (! $key) {
                continue;
            }
            $keyStr = (string) $key;
            // plan-38 P0-SEC-1 / plan-40 §二:防字段名注入入口 — client.fields[].name
            // 必须 ^[a-z][a-z0-9_]*$ + 长度 <= 64,否则丢弃(后端兜底,防 DevTools 绕过 GUI 校验)。
            // 系统字段 id / created_at / updated_at / deleted_at:client 改动忽略,但要分两种:
            //   ① yaml 原本有 → 保留 yaml entry(plan-40 P1 Round 2 bugfix:之前 continue 跳过
            //      会让 saveModule 整行覆盖丢失 system field — platform_visitor_logs 2026-05-20 翻车)
            //   ② yaml 原本没,但 client 给了(user 在 GUI 加字段如 deleted_at)→ 写空 entry,
            //      normalize 阶段会派生 _system + type:timestamp(避免 user 加完 silent skip,
            //      yaml 不变 + 下次 load 又消失 — 2026-05-22 platform_regions 翻车)
            // client 给的位置序保留(rebuild 按 client.fields 遍历顺序)。
            if (in_array($keyStr, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                $newFields[$keyStr] = array_key_exists($keyStr, $yamlFields)
                    ? (array) $yamlFields[$keyStr]
                    : [];

                continue;
            }
            // 首 `_` 放行:Laravel-NestedSet `_lft / _rgt` 工业惯例字段名,
            // 严过滤会 silent drop 整字段(2026-05-22 platform_regions round-trip 翻车根因)
            if (! preg_match('/^[a-z_][a-z0-9_]*$/', $keyStr) || strlen($keyStr) > 64) {
                continue;
            }
            $row           = (array) ($yamlFields[$keyStr] ?? []);
            $effectiveType = $cField['type'] ?? ($row['type'] ?? null);

            foreach ($writable as $attr) {
                if (! array_key_exists($attr, $cField)) {
                    continue;
                }
                $val     = $cField[$attr];
                $yamlHas = array_key_exists($attr, $row);

                // 2026-05-23 P0 bug 修:client `_buildFieldEntry` 用 `f[attr] ?? null` 永远发 null
                // (无 dirty tracking),后端无法区分"未改"vs"清空" → 必须把 `null` 当"未改"保留
                // yaml 原值,只把 `''` 当"显式清空"unset。否则 user 改 A 字段会牵动 B 字段
                // 已有的 default: null / unsigned: false 等被 unset(P0 plan_priority bug 根因 #1)。
                if ($val === null) {
                    continue;     // "未改"信号 — yaml 原值原样保留
                }
                if (! $yamlHas && ($val === '' || $val === '__CLEAR__')) {
                    continue;
                }

                // 2026-05-23 P0 bug 修(round 2):Laravel 全局 ConvertEmptyStringsToNull 中间件把
                // POST body 内 `default: ""` 转成 null,跟 "user 没改" client `?? null` 信号撞 — 后端
                // 无法分辨。Client 端用 `__CLEAR__` sentinel 显式表达"清空",绕过中间件转换。
                if ($val === '__CLEAR__' || $val === '') {
                    unset($row[$attr]);
                } elseif ($attr === 'size' && is_string($val) && str_contains($val, ',')) {
                    // 2026-05-23 P0 bug 修(round 4):client 发完整 'min,max' 串(GUI 显示双值后)
                    // — 直接保留,不要 coerce 成 int 也不要再拼接 origMin(会变 '6,6,200' bug)
                    $row[$attr] = $val;
                } elseif ($attr === 'size' && $yamlHas
                                           && is_string($row[$attr]) && str_contains($row[$attr], ',')
                                           && in_array((string) $effectiveType, ['varchar', 'char'], true)) {
                    // 2026-05-23 P0 bug 修(round 1):varchar/char size '6,192' 紧凑语法 — yaml 原是
                    // string 含 ',',client 只发 max int(legacy GUI 没暴露 min input),overwrite 会丢
                    // min。保留 yaml 原 min,跟 client.max 合成新 'min,max' 串(向后兼容)。
                    // 2026-05-23 round 5 audit:此分支仅对 varchar/char 适用('min,max' 语义)。
                    // decimal/float/double 的 size 紧凑串 '10,2' 是 '{M},{D}'='{size},{precision}'
                    // 完全不同语义,误套这逻辑会把 D 当 min 保留,user 改 size 时 yaml 写出错位置。
                    // decimal 类型不进此分支,落入下面 else → coerce 成 int,yaml size 紧凑串首次
                    // save 后归一为 size: <int> + 独立 precision: <int>(split 形式,跟仓内主流 yaml 一致)
                    $origMin    = trim(explode(',', $row[$attr], 2)[0]);
                    $row[$attr] = $origMin . ',' . $val;
                } else {
                    // DOM input.value 永远是 string,这里按 effectiveType 反 cast 回 numeric/bool
                    $row[$attr] = self::coerceFieldValue($attr, $val, $effectiveType);
                }
            }
            // plan-51:client.index 三种 unique 派生 —
            //   - 'unique-app' → row.unique=true(app-level + soft-aware,不进 index 块)
            //   - 'unique-db'  → row.unique 不写(让 index 块单源 type:unique,经 promoteInlineUnique
            //                    在下次 load 时若 yaml 有 attr.db_unique 也会被吸进 index 块)
            //   - 'unique'     → legacy DB-level(等价 'unique-db')
            // 其它 'index' / 'primary' / 'none' → row.unique strip
            //
            // 2026-05-23 P0 bug 修(round 3 历史):"client 切 index 列 unique → index 后刷新又变 unique"
            // 根因是 attr.unique sugar 没同步删 — 这里继续守护
            $clientIdx = $cField['index'] ?? null;
            if ($clientIdx === 'unique-app') {
                $row['unique'] = true;
            } elseif (array_key_exists('unique', $row)) {
                // 不是 app-level → strip sugar(避免历史 attr.unique=true + 新 GUI 选了 db / 别的 → 双源歧义)
                unset($row['unique']);
            }
            // db_unique sugar 暂不写盘 — 让 index 块单源(verbose `type: unique`)表达;
            // 用户若手写 yaml `db_unique: true`,promoteInlineUnique 会在 load 时派生进 index 块

            // display_name → yaml.<key>.name(client 的 name 字段被字段 key 占用,所以用 display_name 传中文)
            if (array_key_exists('display_name', $cField)) {
                $val = $cField['display_name'];
                if (array_key_exists('name', $row)) {
                    if ($val === null || $val === '') {
                        unset($row['name']);
                    } else {
                        $row['name'] = $val;
                    }
                } elseif ($val !== null && $val !== '') {
                    $row['name'] = $val;
                }
            }
            // 2026-05-23 P0 round 5(user 指出 codegen 规则):
            // FreshStorageGenerator:227 `$attr['unsigned'] ?? true` — int/bigint/tinyint/decimal/float
            // 类型 yaml 没写 unsigned **默认 true**。这意味着 yaml 里的 `unsigned: true` 是冗余(跟
            // 默认一致),`unsigned: false` 才是 user 显式 signed 的唯一表达。
            //
            // 但 createTable 系统字段 creator_id/updater_id 历史就带 `unsigned: true`(line 278-279),
            // 已 ship 的项目 yaml 里也都有 — strip 会破坏 idempotent save(round-trip 把 yaml 改了
            // → 触发 updated_* stamp,即使语义没变)。
            //
            // 取舍:保留两种 value 写盘(let coerceFieldValue 处理),坚守:
            //   - client 发 false → yaml 写 `unsigned: false`(显式 signed,user 切换不会丢)
            //   - client 发 true  → yaml 写 `unsigned: true`(idempotent,跟历史 yaml 兼容)
            //   - client 不发(_unsigned_dirty=false → val=null)→ yaml 原值保留
            // R-14 兜底 non-numeric 类型上的 unsigned(无论 true/false 都 strip)。

            // plan-40 §三 R-14:save 端兜底,non-numeric 类型上的 unsigned 拒绝写盘
            // (loadNormalized 也兜底 strip,这里在 save 链路再卡一道,防 DevTools 绕过 GUI disabled)
            // 用 array_key_exists 而非 !empty:false 值也要 strip(varchar 上 unsigned: false 同样
            // 是非法组合 + 噪声)
            if (array_key_exists('unsigned', $row)) {
                $numericTypes = ColumnTypeGroups::NUMERIC;
                if (! in_array((string) $effectiveType, $numericTypes, true)) {
                    unset($row['unsigned']);
                }
            }
            // Round 2 真机 Test 3/5 polish:仅对**新字段**(yaml 原本没有)的 row 应用 canonical 顺序,
            // 已存在字段保留 yaml 原 attr 顺序(plan-36 yaml 是 source of truth — user 手写顺序须尊重)。
            // 真机 Test 10 暴露 regression:之前 unconditional sortRowAttrs 把 Order.yaml 全 yaml 重排,
            // user 改一个字段触发全表 diff 噪声。
            $isNewRow           = ! array_key_exists($keyStr, $yamlFields);
            $newFields[$keyStr] = $isNewRow ? self::sortRowAttrs($row) : $row;
        }

        return $newFields;
    }

    /**
     * Round 2 真机 Test polish:按 scaffold yaml 习惯重排 row attr 顺序,
     * 让 git diff 干净(避免 `{name, type}` vs `{type, name}` 这种顺序噪声)。
     * 顺序参考本仓多张 yaml 的人工习惯,unknown attr 保留在末尾。
     */
    private static function sortRowAttrs(array $row): array
    {
        static $canonicalOrder = [
            'required', 'name', 'type', 'size', 'min_size', 'precision',
            'unsigned', 'default', 'format', 'unique', 'comment', 'desc',
        ];
        $out = [];
        foreach ($canonicalOrder as $key) {
            if (array_key_exists($key, $row)) {
                $out[$key] = $row[$key];
                unset($row[$key]);
            }
        }
        // 未识别的 attr 保留原序追加在末尾
        foreach ($row as $k => $v) {
            $out[$k] = $v;
        }

        return $out;
    }

    /**
     * 重建 table.index 块(plan 19 v4 §index-roundtrip,plan 19 v7 B3 修保 yaml 原序)。
     * 策略:single 部分按 yaml 原 idx-name 出现顺序输出(client 仍要的),
     *       client 新增的 single index 按 client.fields 顺序追加在末尾;
     *       multi 部分由 client.multi_indexes 单源驱动(F30),或 fallback yaml 原 multi。
     *
     * v7 B3:之前 single 部分按 clientFields 顺序重建,会把 yaml 里手工排过的 index 顺序覆盖掉
     *        (任何 saveModule 触发都重排);此版改成保 yaml 原序,只增/删/改 type。
     */
    public static function rebuildTableIndex(array $yamlIndex, array $clientFields, array $cTable): array
    {
        // plan-51:client 端 dropdown 用 'unique-app' / 'unique-db' 区分语义;
        // index 块只关心 DB 层(unique-db / 旧 'unique' / 'index' / 'primary')。
        // 在 client 字段标签翻译为 yaml-native(canonical)前,先做归一化:
        //   - 'unique-app' → 不进 index 块(由 row.unique sugar 表达,见 rebuildFieldRows 上方修法)
        //   - 'unique-db' → 'unique'(canonical DB 强约束)
        //   - 'unique'(legacy)→ 'unique'(canonical,backward compat)
        $allowedTypes  = ['primary', 'unique', 'index'];
        $singleByField = [];     // fieldKey => ['name' => idxName, 'entry' => yaml entry]
        $singleOrder   = [];      // [fieldKey, ...] yaml 原出现顺序
        $multiKept     = [];      // idxName => entry(多字段索引,GUI 不动)
        foreach ($yamlIndex as $idxName => $entry) {
            $f           = is_array($entry) ? ($entry['fields'] ?? null) : null;
            $singleField = null;
            if (is_string($f)) {
                $singleField = $f;
            } elseif (is_array($f) && count($f) === 1) {
                $singleField = (string) reset($f);
            }
            if ($singleField !== null) {
                $singleByField[$singleField] = ['name' => (string) $idxName, 'entry' => (array) $entry];
                $singleOrder[]               = $singleField;
            } else {
                $multiKept[$idxName] = $entry;
            }
        }

        // client 当前需要 single index 的字段
        $clientNeedIdx = [];     // fieldKey => clientType(canonical)
        foreach ($clientFields as $cField) {
            $key = $cField['name'] ?? null;
            if (! $key) {
                continue;
            }
            $clientType = $cField['index'] ?? null;
            // plan-51 translate:client GUI 字面值 → yaml canonical
            $clientType = match ($clientType) {
                'unique-db'  => 'unique',
                'unique-app' => null,        // app-level 不进 index 块,由 row.unique sugar 单源
                default      => $clientType,
            };
            if (! in_array($clientType, $allowedTypes, true)) {
                continue;
            }
            $clientNeedIdx[$key] = $clientType;
        }

        $newIndex = [];
        $emitted  = [];
        // ① 按 yaml 原序输出仍需要的 entry(仅调 type)
        foreach ($singleOrder as $fieldKey) {
            if (! isset($clientNeedIdx[$fieldKey])) {
                continue;
            }     // client 删了这个 index
            $entry                                       = $singleByField[$fieldKey]['entry'];
            $entry['type']                               = $clientNeedIdx[$fieldKey];
            $entry['fields']                             = $fieldKey;
            $newIndex[$singleByField[$fieldKey]['name']] = $entry;
            $emitted[$fieldKey]                          = true;
        }
        // ② client 新增的 single index(yaml 原没有的)按 client.fields 顺序追加
        foreach ($clientFields as $cField) {
            $key = $cField['name'] ?? null;
            if (! $key || isset($emitted[$key]) || ! isset($clientNeedIdx[$key])) {
                continue;
            }
            $newIndex[$key] = ['type' => $clientNeedIdx[$key], 'fields' => $key];
        }

        // F30 多字段索引:client.multi_indexes 单源驱动;没传则 fallback yaml 原 multi
        if (array_key_exists('multi_indexes', $cTable) && is_array($cTable['multi_indexes'])) {
            foreach ($cTable['multi_indexes'] as $mi) {
                $miName   = (string) ($mi['name'] ?? '');
                $miType   = (string) ($mi['type'] ?? '');
                $miFields = (array) ($mi['fields'] ?? []);
                if ($miName === '' || ! in_array($miType, $allowedTypes, true) || count($miFields) < 2) {
                    continue;
                }
                $newIndex[$miName] = ['type' => $miType, 'fields' => array_values($miFields)];
            }
        } else {
            foreach ($multiKept as $name => $entry) {
                $newIndex[$name] = $entry;
            }
        }

        return $newIndex;
    }

    /** F36 枚举:client.enums 单源(完全替换 yaml.<table>.enums)。 $cEnums=null 时跳过(老 client 兼容)
     *  2026-05-21:enum 翻译辅助 — 允许 key 为空的 pending entry 写盘(yaml map key 不能空,
     *  用 sentinel __pending_<n> 占位)。codegen 端(moo:model buildEnum)看到 sentinel 报错引导
     *  user 去 designer AI 翻译。reader loadTableFull 反解 sentinel → key='' designer UI 显示空。
     */
    public static function applyEnums(array $yamlTable, ?array $cEnums): array
    {
        if (! is_array($cEnums)) {
            return $yamlTable;
        }
        $newEnums = [];
        foreach ($cEnums as $g) {
            $field = (string) ($g['field'] ?? '');
            if ($field === '') {
                continue;
            }
            $items = (array) ($g['items'] ?? []);
            if (empty($items)) {
                continue;
            }
            $entries    = [];
            $pendingIdx = 0;
            foreach ($items as $r) {
                $key = (string) ($r['key'] ?? '');
                // Round 2 P2 防下游 XSS:label_zh / label_en 收到 client 时 sanitize,
                // 禁 HTML 尖括号 + quote 字符 + 反斜杠 + control char,cap 64 长度。
                $labelEn = self::sanitizeEnumLabel((string) ($r['label_en'] ?? ''));
                $labelZh = self::sanitizeEnumLabel((string) ($r['label_zh'] ?? ''));
                // 空 key item:value/label 至少要有一个非空才保留(防 user 加一行什么都没填浪费占位)
                if ($key === '') {
                    $hasContent = ($r['value'] ?? '') !== '' || $labelEn !== '' || $labelZh !== '';
                    if (! $hasContent) {
                        continue;
                    }
                    $key = '__pending_' . $pendingIdx++;
                }
                // plan-40 §二 P1 防御纵深:enum value sanitize — int value 强 cast,string value
                // strip 控制字符 + quote/backslash 防写到 Enum case PHP 字面量时逃逸
                $rawVal = $r['value'] ?? '';
                $val    = is_string($rawVal) && ! ctype_digit(ltrim($rawVal, '-'))
                    ? self::sanitizeEnumLabel($rawVal)
                    : $rawVal;
                // yaml 形态:`{ <key>: [value, label_en, label_zh] }`
                $entries[$key] = [
                    $val,
                    $labelEn,
                    $labelZh,
                ];
            }
            if (! empty($entries)) {
                $newEnums[$field] = $entries;
            }
        }
        if (empty($newEnums)) {
            unset($yamlTable['enums']);
        } else {
            $yamlTable['enums'] = $newEnums;
        }

        return $yamlTable;
    }

    /**
     * Round 2 P2 防下游 XSS:enum label(label_en / label_zh)收 client 时 sanitize。
     * 策略:strip 控制字符 + HTML 尖括号 + quote + 反斜杠;cap 64(防写盘膨胀)。
     * 中文 / 标点 / 数字保留。label_en 还有 PascalCase 上游 regex,这里只是兜底。
     */
    public static function sanitizeEnumLabel(string $val): string
    {
        if ($val === '') {
            return '';
        }
        // strip < > " ' \  和 ASCII 控制字符
        $clean = preg_replace('/[<>"\'\\\\\x00-\x1F\x7F]/u', '', $val) ?? '';
        $clean = trim($clean);
        if (mb_strlen($clean, 'UTF-8') > 64) {
            $clean = mb_substr($clean, 0, 64, 'UTF-8');
        }

        return $clean;
    }

    /**
     * Coerce client-submitted field attr to the type that matches yaml conventions.
     *
     * GUI 上所有 input.value 都是 string('200' / '2' / '0' / 'true'),直接写 yaml 会被引号包起来,
     * 跟手写的 yaml(size: 192 / default: 0)不一致。这里按 effective type 反 cast 回原生类型。
     *
     * 只处理 size/default(其他 attr client 已是正确类型:type/index/comment 是 string,required 是 boolean)。
     */
    private static function coerceFieldValue(string $attr, mixed $val, ?string $type): mixed
    {
        // precision 永远 int(decimal/double/float 的小数位数)
        if ($attr === 'precision') {
            if (is_int($val)) {
                return $val;
            }
            if (is_string($val) && ctype_digit($val)) {
                return (int) $val;
            }

            return $val;
        }
        if (! in_array($attr, ['size', 'default'], true)) {
            return $val;
        }
        if (! is_string($val)) {
            return $val;
        }     // 已是 int/bool/float,不动

        $intTypes   = ColumnTypeGroups::INT;
        $floatTypes = ColumnTypeGroups::FLOAT;
        $boolTypes  = ['bool', 'boolean'];

        if ($attr === 'size') {
            // decimal size 是 'm,n' 形态(precision,scale),保 string 由 normalizeSize 后续解析
            if ($type === 'decimal' && preg_match('/^\d+\s*,\s*\d+$/', $val)) {
                return $val;
            }
            // 其他 size 都是 int(varchar/char/int 系列的数值长度)
            if (ctype_digit($val)) {
                return (int) $val;
            }

            return $val;
        }

        // default:跟 type 联动
        if (in_array($type, $intTypes, true) && preg_match('/^-?\d+$/', $val)) {
            return (int) $val;
        }
        if (in_array($type, $floatTypes, true) && is_numeric($val)) {
            return (float) $val;
        }
        if (in_array($type, $boolTypes, true)) {
            $lower = strtolower($val);
            if (in_array($lower, ['true', '1'], true)) {
                return true;
            }
            if (in_array($lower, ['false', '0'], true)) {
                return false;
            }
        }

        return $val;
    }
}
