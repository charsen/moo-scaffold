<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Designer;

use Mooeen\Scaffold\Support\ColumnTypeGroups;

/**
 * `loadTableFull` 族的**字段形状归一** —— 把 normalize 后的 `$attr` 摊成 UI 模板可直接属性访问的 `$shape`。
 *
 * **为什么独立成类**（而不是继续当 `SchemaLoader` 的私有方法），判据三条：
 *   - 这四个方法**只被 `SchemaLoader::loadTableFull` 一个 public 入口可达**，族外接触面为 0；
 *   - 族内**零实例状态**：只用入参 + `ColumnTypeGroups::` 静态常量，不碰 `$this`、容器、文件系统；
 *   - 搬完两边各自成立 —— 本类是**纯函数 shaper**，`SchemaLoader` 回归它的本职（yaml load / normalize / merge / write I/O）。
 * 注意「单入口」是族级读数：`CreateApiGenerator` 那种「整族持 14 处状态」也同样是单入口，所以判定必须
 * 逐方法量到**零状态子集**这一层，不能停在族级。
 *
 * **分工**：本类只产「字段形状」。`index` 的反向映射（表级 index 块 / `attr.unique` → 字段 `index` 列）
 * 与 `index_disabled` 的补写都留在 `loadTableFull` —— 那两步要读**整表**（index 块、其它字段），
 * 不属于单字段形状。这个分工由 `tests/Feature/Designer/FieldShaperTest.php` §5 用
 * 「`loadTableFull` 的字段键集 = 本类产出 + 恰好一个 `index_disabled`」钉死。
 *
 * **搬家保真**：这一族外迁前在 `tests/` 里零直接覆盖，故先补「同一批断言跨两个宿主」的钉尸测试
 * 再搬（TUNING-PLAN 红线 9）。搬运逐字进行，只做两类机械替换：`$this->computeX(` → `self::computeX(`、
 * 签名加 `static`（入口同时 `private` → `public`，因为调用方在另一个类）。行为一个字节没改。
 *
 * ⚠ **已知遗留，刻意保持现状、不属本次搬家范围**：`computeDefaultClass` 的 `$type === 'bool'` 支
 * 只认字面 `bool`，而 `SchemaLoader` 归一后的 type 是 canonical 的 `'boolean'`
 * （见 `ColumnTypeGroups::canonicalize`）⇒ 真实链路上 bool 字段的 default 校验**从未生效**。
 * 与 `ColumnTypeGroups` 记过的「更窄的 inline 列表让整类列静默丢校验」同型。修它属行为变更，须单独过堂。
 */
final class FieldShaper
{
    /**
     * Shape a single field row for UI consumption (deliverables: 'Field shape returned').
     */
    public static function shapeField(string $name, array $attr, bool $tableLocked): array
    {
        // plan 19 §2.6:tableLocked 只锁表 key / 删表 / 模块 folder,字段编辑(包括加/改/改名)一律允许。
        $isSystem     = isset($attr['_system']);
        $rowReadonly  = $isSystem || ($name === 'id');
        $nameReadonly = $rowReadonly;  // 字段 key 改名走 rename 流程,system 行直接 readonly
        // 2026-05-23:行末删除按钮入口(替代 ⋯ popover 菜单);改名走 key 列行内 input → setFieldKey
        //   id 永远不可删;system timestamps 可删(user 主动砍掉时间戳/软删除字段时用)
        $isIdField = ($name === 'id');
        $canRemove = ! $isIdField;
        // v6 批次 B:系统字段 hint(tooltip),user hover key 列时提示"可删但不可改"
        if ($isIdField) {
            $rowTitle = 'ID 主键，固定不可改不可删';
        } elseif ($isSystem) {
            $rowTitle = match ($name) {
                'deleted_at' => '软删除时间戳。行内只读，但可整行删除（行末 × 按钮）',
                'created_at' => '自动维护的创建时间。行内只读，但可整行删除（行末 × 按钮）',
                'updated_at' => '自动维护的更新时间。行内只读，但可整行删除（行末 × 按钮）',
                default      => '系统字段，行内只读',
            };
        } else {
            $rowTitle = '';
        }
        $shape = [
            // __rowId:session 内 stable 标识(由 yaml field key 派生,初始化后不变);
            // 用户改 key 时 f.key 会变,而 __rowId 保持不变,做 :key 防 DOM 重 mount + closest('tr').data-rk 反查行用
            '__rowId'   => $name,
            'key'       => $name,
            '_orig_key' => $name,           // F39:yaml 原 key 锚点,setFieldKey 时跟 f.key 不同就塞 rename_hint
            'name'      => $attr['name'] ?? null,
            'type'      => $attr['type'] ?? null,
            // 2026-05-23 P0 bug 修(round 4):size 紧凑写法 'min,max' GUI 显示
            // - yaml load 经 parseSize 拆成 size + min_size 两字段(in-memory)
            // - GUI input 直接显示 size 只看到 max(192),user 看不到 min(6)
            // - 修法:有 min_size 时 shape 派生 size 合成 '{min},{max}' string,user 看到完整双值
            // - save 流 rebuildFieldRows 已支持 size 字符串带 ',' 保留(round 1 fix #3)
            'size' => isset($attr['min_size']) && $attr['min_size'] !== null
                            ? ($attr['min_size'] . ',' . ($attr['size'] ?? ''))
                            : ($attr['size'] ?? null),
            'min_size' => $attr['min_size'] ?? null,
            // precision:decimal(M,D) / float / double 的小数位数(D),仅这几种类型可编辑
            'precision'          => $attr['precision'] ?? null,
            'precision_disabled' => ! in_array($attr['type'] ?? '', ColumnTypeGroups::FLOAT, true),
            // format:跟 type 平行的自定义,如 'float:100' 让 model 自动整 ↔ 浮点 cast(见 CreateModelGenerator::getFloatAttribute)
            'format'   => $attr['format']  ?? null,
            'default'  => $attr['default'] ?? null,
            'required' => (bool) ($attr['required'] ?? true),
            'unique'   => (bool) ($attr['unique'] ?? false),
            // 2026-05-23 P0 round 5 视觉 bug:之前默认 false → bigint/int 字段 GUI 显示 unsigned 未勾选,
            // 但 FreshStorageGenerator:225 给 int/bigint/tinyint/decimal/float yaml 没写 unsigned 派生
            // 默认 true(codegen 规则)。UI 跟 codegen 反 — user 看到"未勾选"但 migration 出来是 unsigned,
            // 满屏诡异。修法:派生口径对齐 codegen 实际行为。
            'unsigned' => (bool) ($attr['unsigned'] ?? in_array($attr['type'] ?? '', ColumnTypeGroups::UNSIGNED_DEFAULT, true)),
            'nullable' => ! (bool) ($attr['required'] ?? true),
            // dirty-tracking 锚点(F26 nullable / F32 unsigned):client 设 dirty=true 后 _buildSavePayload 才发,
            // 避免 shape 派生值污染 yaml
            '_nullable_dirty' => false,
            '_unsigned_dirty' => false,
            // F32:unsigned 是否可编辑(只对 numeric 类型开放)
            'unsigned_disabled' => ! in_array($attr['type'] ?? '', ColumnTypeGroups::NUMERIC, true),
            'index'             => 'none',                       // resolved by loadTableFull from table-level index block
            'comment'           => $attr['comment'] ?? null,
            // CSP-friendly 预算 boolean / string(view 模板里只能属性访问,不能 method call)
            'row_readonly'  => $rowReadonly,
            'name_readonly' => $nameReadonly,
            'row_class'     => $rowReadonly ? 'is-readonly' : '',
            // 2026-05-21:字段 key 前缀 strip 按钮可见性 — client 在 init / setTablePrefix / setFieldKey
            // 后通过 _recomputeAllFieldsPrefixStrip 重算(此处给默认值,避免模板 undefined)
            //   不可 strip 行也渲按钮(visibility:hidden 占位)保所有行 key 列宽度齐
            'prefix_strippable'      => false,
            'prefix_strip_btn_class' => 'p-designer-fields__row-btn p-designer-fields__row-btn--strip is-placeholder',
            'prefix_strip_disabled'  => true,
            // 2026-05-21:DeepSeek 拼写检查结果(默认无 warning) — aiSpellCheckFields 后 client 写入
            'spell_warning'     => '',
            'spell_suggestion'  => '',
            'spell_reason'      => '',
            'spell_warn_class'  => 'p-designer-fields__spell-warn is-placeholder',
            'has_spell_warning' => false,
            // 行末删除按钮显隐:id 隐(can_remove=false),system timestamps 可删
            'can_remove' => $canRemove,
            // v6 批次 B:系统字段提示文字(空字符串 = 普通字段,无 tooltip)
            'row_title' => $rowTitle,
            // v6.2 round 4:size 列校验初始 class(varchar/char/decimal 必须有 size)
            'size_class' => self::computeSizeClass($attr['type'] ?? null, $attr['size'] ?? null),
            'size_title' => self::computeSizeClass($attr['type'] ?? null, $attr['size'] ?? null) === 'is-invalid'
                ? '此类型需要 size，请填（如 varchar 64）'
                : '',
            // #30:default 值跟 type 兼容性校验
            'default_class' => self::computeDefaultClass($attr['type'] ?? null, $attr['default'] ?? null),
            'default_title' => self::computeDefaultTitle($attr['type'] ?? null, $attr['default'] ?? null),
        ];

        return $shape;
    }

    /**
     * v6.2 round 4:size 必填校验(varchar/char/decimal 不能空)。
     * 返回 '' = OK,'is-invalid' = 红框警告。
     */
    private static function computeSizeClass(?string $type, $size): string
    {
        $needsSize = in_array($type, ['varchar', 'char', 'decimal'], true);
        if (! $needsSize) {
            return '';
        }
        if ($size === null || $size === '' || $size === 0 || $size === '0') {
            return 'is-invalid';
        }

        return '';
    }

    /**
     * #30:default 值 vs type 兼容性校验。空值永远 OK(由 nullable 控制)。
     * 数字类型(int/bigint/.../decimal/float/double):default 必须是数字
     * bool:default 必须 0/1/true/false
     * 其余(varchar/text/timestamp/etc):宽松,啥都行
     */
    private static function computeDefaultClass(?string $type, $default): string
    {
        if ($default === null || $default === '') {
            return '';
        }
        $numTypes = ColumnTypeGroups::NUMERIC;
        if (in_array($type, $numTypes, true)) {
            return is_numeric($default) ? '' : 'is-invalid';
        }
        if ($type === 'bool') {
            return in_array((string) $default, ['0', '1', 'true', 'false'], true) ? '' : 'is-invalid';
        }

        return '';
    }

    private static function computeDefaultTitle(?string $type, $default): string
    {
        if (self::computeDefaultClass($type, $default) !== 'is-invalid') {
            return '';
        }
        if ($type === 'bool') {
            return 'bool 默认值须 0/1/true/false';
        }

        return $type . ' 默认值须数字';
    }
}
