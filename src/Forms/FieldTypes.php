<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Forms;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use LogicException;
use Mooeen\Scaffold\Exceptions\BaseException;
use Mooeen\Scaffold\Support\FormFrontendRules;
use Mooeen\Scaffold\Support\FormWidgetTypes;

/**
 * 通用字段契约：规则、参数、归一化与展示由所有表单生产者共享。领域可通过 definitions() 追加特殊类型。
 *
 * **命名即契约（别顺手「统一」）**：本类只负责**表单字段**类型（`text` / `money` / `select` …）；
 * 管**数据库列**词汇分组（`int` / `varchar` / `date` …）的是另一个类 `Support\ColumnTypeGroups`
 * （2026-09-18 之前它也叫 `FieldTypes`，因为同名混淆才把**那个**改名）。
 * 本类保持 `FieldTypes` 不动 —— 它是**跨仓扩展契约**：下游用 `is_a($contract, FieldTypes::class, true)`
 * 校验子类、也有包直接 `extends` 它。改名会破坏这些仓，且换不来任何仓内收益。
 * 防复发见 `tests/Feature/Support/UniqueClassNamesTest.php`（同名类不得跨顶层目录）。
 */
class FieldTypes
{
    /** 单选项集上限；复杂业务主数据应由消费领域提供独立引用类型。 */
    protected const MAX_OPTIONS = 200;

    /**
     * 类型登记：类型标识 => 定义。
     *
     * `params` 是声明式元 schema，键为参数名，值为约束：
     *   kind        int | number | string | options | values
     *   default     缺省值；**不写 default = 必填参数**
     *   min / max   int / number 的取值范围（含端点）
     *   max_length  string 的最大长度
     *   max_items   options 的最大条目数
     *
     * `rules(array $params): array` 只产出**类型专属**规则，`required` / `nullable` 由 `rules()` 统一前置。
     *
     * @return array<string, array{
     *     label: string,
     *     widget: string,
     *     params: array<string, array<string, mixed>>,
     *     rules: callable,
     *     options: callable|null,
     *     readonly?: bool,
     *     retired?: bool
     * }>
     */
    protected static function definitions(): array
    {
        $definitions = [
            'text' => [
                'label'  => '单行文本',
                'widget' => 'input',
                'params' => [
                    'max_length'  => ['kind' => 'int', 'default' => 255, 'min' => 1, 'max' => 255],
                    'placeholder' => ['kind' => 'string', 'default' => '', 'max_length' => 64],
                ],
                'rules'   => static fn (array $p): array => ['string', 'max:' . $p['max_length']],
                'options' => null,
            ],
            'textarea' => [
                'label'  => '多行文本',
                'widget' => 'textarea',
                'params' => [
                    'max_length'  => ['kind' => 'int', 'default' => 1000, 'min' => 1, 'max' => 5000],
                    'placeholder' => ['kind' => 'string', 'default' => '', 'max_length' => 64],
                ],
                'rules'   => static fn (array $p): array => ['string', 'max:' . $p['max_length']],
                'options' => null,
            ],
            'integer' => [
                'label'  => '整数',
                'widget' => 'input',
                'params' => [
                    'min'  => ['kind' => 'int', 'default' => null, 'min' => -999999999, 'max' => 999999999],
                    'max'  => ['kind' => 'int', 'default' => null, 'min' => -999999999, 'max' => 999999999],
                    'unit' => ['kind' => 'string', 'default' => '', 'max_length' => 16],
                ],
                'rules' => static function (array $p): array {
                    $rules = ['integer'];
                    if ($p['min'] !== null) {
                        $rules[] = 'min:' . $p['min'];
                    }
                    if ($p['max'] !== null) {
                        $rules[] = 'max:' . $p['max'];
                    }

                    return $rules;
                },
                'options' => null,
            ],
            'decimal' => [
                'label'  => '定点小数',
                'widget' => 'input',
                'params' => [
                    'precision' => ['kind' => 'int', 'default' => 2, 'min' => 0, 'max' => 4],
                    'min'       => ['kind' => 'number', 'default' => null, 'min' => -999999999, 'max' => 999999999],
                    'max'       => ['kind' => 'number', 'default' => null, 'min' => -999999999, 'max' => 999999999],
                    'unit'      => ['kind' => 'string', 'default' => '', 'max_length' => 16],
                ],
                // decimal:0,N 同时守住「最多 N 位小数」与「数值形态」；min/max 按数值比较。
                'rules' => static function (array $p): array {
                    $rules = ['numeric', 'decimal:0,' . $p['precision']];
                    if ($p['min'] !== null) {
                        $rules[] = 'min:' . $p['min'];
                    }
                    if ($p['max'] !== null) {
                        $rules[] = 'max:' . $p['max'];
                    }

                    return $rules;
                },
                'options' => null,
            ],
            'date' => [
                'label'   => '日期',
                'widget'  => 'date-picker',
                'params'  => [],
                'rules'   => static fn (array $p): array => ['date_format:Y-m-d'],
                'options' => null,
            ],
            // 日期时间类型显式声明控件，避免 date_format 规则被推断成普通文本框。
            'datetime' => [
                'label'   => '日期时间',
                'widget'  => 'datetime-picker',
                'params'  => [],
                'rules'   => static fn (array $p): array => ['date_format:Y-m-d H:i:s'],
                'options' => null,
            ],
            'boolean' => [
                'label'   => '布尔',
                'widget'  => 'radio',
                'params'  => [],
                'rules'   => static fn (array $p): array => ['boolean'],
                'options' => static fn (array $p): array => [1 => '是', 0 => '否'],
            ],
            'select' => [
                'label' => '单选',
                // 组件名取 select 而非 radio：样例如「品牌 / 维保单位」选项可达上百条，
                // radio 会摊平整屏；radio 仍可用（boolean 就走 radio）。
                'widget' => 'select',
                'params' => [
                    'options'          => ['kind' => 'options', 'max_items' => static::MAX_OPTIONS],
                    'disabled_options' => ['kind' => 'values', 'default' => [], 'max_items' => static::MAX_OPTIONS],
                ],
                'rules'   => static fn (array $p): array => [Rule::in(array_keys($p['options']))],
                'options' => static fn (array $p): array => $p['options'],
            ],

        ];
        $definitions['money']                              = $definitions['decimal'];
        $definitions['money']['label']                     = '金额';
        $definitions['money']['params']['unit']['default'] = '元';
        $definitions['money']['params']['storage_scale']   = ['kind' => 'int', 'default' => 0, 'min' => 0, 'max' => 4];

        return $definitions;

    }

    /**
     * 登记的类型标识（保序，含已退役项）。
     *
     * @return list<string>
     */
    public static function types(): array
    {
        return array_keys(static::definitions());
    }

    /**
     * 可选用的类型标识（保序，已退役项除外）—— 配置页下拉用。
     *
     * @return list<string>
     */
    public static function activeTypes(): array
    {
        return array_keys(array_filter(
            static::definitions(),
            static fn (array $def): bool => ! ($def['retired'] ?? false),
        ));
    }

    /** 是否登记过该类型（含已退役）。 */
    public static function has(string $type): bool
    {
        return isset(static::definitions()[$type]);
    }

    /**
     * 后端导出的类型清单：供配置页下拉、`/scaffold/api/request` 调试器与前端一致性检查消费
     * （plan 61 §2.1：本包负责登记并提供**后端可导出**的清单，前端不靠人工同步）。
     *
     * @return list<array{value: string, label: string, widget: string, params: list<string>, readonly: bool}>
     */
    public static function export(): array
    {
        $export = [];
        foreach (static::definitions() as $type => $def) {
            if ($def['retired'] ?? false) {
                continue;
            }
            $export[] = [
                'value'  => $type,
                'label'  => $def['label'],
                'widget' => $def['widget'],
                'params' => array_keys($def['params']),
                // 参数**元 schema**（kind / default / min / max / max_length / max_items）：
                // 配置页的「新增/编辑字段」弹窗要用它渲染「该类型的专属属性」，
                // 有了它前端就不必再抄一份参数清单（plan 64 要收敛的就是这种平行清单）
                'param_schema' => $def['params'],
                'readonly'     => static::isReadOnly($type),
            ];
        }

        return $export;
    }

    /** 类型中文名；未登记返回类型标识本身（展示兜底，不报错）。 */
    public static function labelOf(string $type): string
    {
        return static::definitions()[$type]['label'] ?? $type;
    }

    /**
     * 前端渲染组件名。
     *
     * 未登记返回 **null**：调用方必须原样透传原始类型标识，让前端 `registry.ts` 走「未知类型 → 只读兜底」，
     * **不得**替换成 `input`（那正是它要防的「静默退化成可编辑文本框」）。
     */
    public static function widgetOf(string $type): ?string
    {
        return static::definitions()[$type]['widget'] ?? null;
    }

    /**
     * 是否只读：登记为只读的类型（公式）与**所有未登记类型**都算。
     *
     * 只读 = 不接受客户端输入；服务端既不按它收值，也不能因为 required 就要求用户填（详见 `rules()`）。
     */
    public static function isReadOnly(string $type): bool
    {
        $definition = static::definitions()[$type] ?? null;

        // 未登记 = 只读（前端也画不出来）；登记项默认可写，只有显式声明 readonly 的才只读。
        return $definition === null || (bool) ($definition['readonly'] ?? false);
    }

    /**
     * 校验并规范化 `field_params`：补默认、拒未知参数、守范围。
     *
     * @param string      $type  字段类型标识
     * @param mixed       $raw   数据库里的 `field_params`（json cast 后是 array|null）
     * @param string|null $label 字段名称，仅用于把错误信息定位到具体字段
     *
     * @return array<string, mixed> 只含该类型声明的参数
     */
    public static function normalizeParams(string $type, mixed $raw, ?string $label = null): array
    {
        $def = static::definitions()[$type] ?? null;
        if ($def === null) {
            // 未登记类型：参数形状未知，不解释也不报错（历史记录仍要能读出来）。
            return [];
        }

        if ($raw === null || $raw === '' || $raw === []) {
            $raw = [];
        }
        if (! is_array($raw)) {
            throw static::fail($type, $label, '参数必须是对象');
        }

        $where    = $label === null ? "字段类型「{$def['label']}」" : "字段「{$label}」";
        $declared = $def['params'];

        foreach (array_keys($raw) as $key) {
            if (! isset($declared[$key])) {
                $allowed = $declared === [] ? '无' : implode('、', array_keys($declared));
                throw new BaseException("{$where}的参数「{$key}」不受支持（可用参数：{$allowed}）");
            }
        }

        $params = [];
        foreach ($declared as $key => $spec) {
            if (! array_key_exists($key, $raw)) {
                if (! array_key_exists('default', $spec)) {
                    throw new BaseException("{$where}缺少必填参数「{$key}」");
                }
                $params[$key] = $spec['default'];

                continue;
            }

            $params[$key] = static::normalizeParam($type, $label, $key, $spec, $raw[$key]);
        }

        if ($type === 'select' && array_diff($params['disabled_options'], array_map('strval', array_keys($params['options']))) !== []) {
            throw new BaseException('停用选项必须属于已声明选项');
        }
        if ($type === 'money' && trim($params['unit'] ?? '') === '') {
            throw new BaseException('金额必须声明显示单位');
        }
        if (isset($params['min'], $params['max']) && $params['min'] > $params['max']) {
            throw new BaseException("{$where}的最小值不能大于最大值");
        }

        return $params;
    }

    /**
     * 运行时 Laravel 规则（与生成式 `FormRequest::rules()` 同形状：`字段 => list<规则>`）。
     *
     * 只读类型（含未登记类型）一律 `nullable` 且不带类型规则：记录必须存得下（前端不提交它的值，
     * 服务端也不收），**不能**因为配了必填就把用户卡死在一个他改不了的字段上。
     *
     * @return list<mixed>
     */
    public static function rules(string $type, array $params, bool $required): array
    {
        if (static::isReadOnly($type)) {
            return ['nullable'];
        }

        $rules = [$required ? 'required' : 'nullable'];

        foreach ((static::definitions()[$type]['rules'])($params) as $rule) {
            $rules[] = $rule;
        }

        return $rules;
    }

    /**
     * 选项集 `[值 => 标签]`（供 `RuntimeFormRequest::options()` → scaffold 的 dictionary 转换）。
     *
     * @return array<int|string, string>
     */
    public static function options(string $type, array $params): array
    {
        $options = static::definitions()[$type]['options'] ?? null;

        return $options === null ? [] : $options($params);
    }

    /**
     * 前端校验规则 `[{rule, msg}]`——与 `FormFrontendRules::fromRules()` 同形状、同规则词汇。
     *
     * 与那个生产者的两点刻意差异（见类注释）：
     * - msg 的 `:attribute` 换成**运行期 label**，不查 `validation.attributes`；
     * - `:max` / `:min` 保留占位符交给前端替换（下游 `Former` 就是这么做的），后端不预先展开。
     *
     * @return list<array{rule: string, msg: string}>
     */
    public static function frontendRules(string $type, array $params, string $label, bool $required): array
    {
        return FormFrontendRules::fromRules(['value' => static::rules($type, $params, $required)], ['value' => $label])['value'] ?? [];
    }

    /** 校验并归一化一个通用值；返回值可由固定或动态 Request 共同消费。 */
    public static function validateValue(string $type, array $params, mixed $value, bool $required = false, string $label = '字段'): mixed
    {
        if (static::isReadOnly($type)) {
            throw new BaseException('该字段类型不接受输入');
        }
        $params = static::normalizeParams($type, $params, $label);
        if ($type === 'select' && in_array((string) $value, $params['disabled_options'] ?? [], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['value' => $label . '不能选择已停用选项']);
        }
        $data = Validator::make(['value' => $value], ['value' => static::rules($type, $params, $required)], [], ['value' => $label])->validate();

        return static::normalizeValue($type, $params, $data['value'] ?? null);
    }

    /** 已验证输入转存储值；金额默认同单位，storage_scale=2 显式声明元转分。 */
    public static function normalizeValue(string $type, array $params, mixed $value): mixed
    {
        if ($value === null || ($value === '' && in_array($type, ['integer', 'decimal', 'money', 'boolean'], true))) {
            return null;
        }
        if (isset(static::definitions()[$type]['normalize'])) {
            return (static::definitions()[$type]['normalize'])($value, $params);
        }
        if (in_array($type, ['decimal', 'money'], true)) {
            $decimal = BigDecimal::of((string) $value)->toScale((int) ($params['precision'] ?? 2), RoundingMode::UNNECESSARY);

            return (string) $decimal->withPointMovedRight($type === 'money' ? (int) ($params['storage_scale'] ?? 0) : 0);
        }
        if ($type === 'select') {
            foreach (array_keys($params['options'] ?? []) as $key) {
                if (! is_int($key)) {
                    return (string) $value;
                }
            }
            foreach (array_keys($params['options'] ?? []) as $key) {
                if ((string) $key === (string) $value) {
                    return $key;
                }
            }
        }

        return match ($type) {
            'integer' => (int) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            default   => $value,
        };
    }

    /** 编辑回填只换单位，不把历史精度悄悄舍入后回写。 */
    public static function inputValue(string $type, array $params, mixed $value): mixed
    {
        if ($value === null || $value === '' || ! in_array($type, ['decimal', 'money'], true)) {
            return $value;
        }

        return (string) BigDecimal::of((string) $value)->withPointMovedLeft($type === 'money' ? (int) ($params['storage_scale'] ?? 0) : 0);
    }

    /** 存储值转表单/展示值；避免二次元分转换。 */
    public static function displayValue(string $type, array $params, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! in_array($type, ['decimal', 'money'], true)) {
            return $value;
        }

        return (string) BigDecimal::of((string) $value)
            ->withPointMovedLeft($type === 'money' ? (int) ($params['storage_scale'] ?? 0) : 0)
            ->toScale((int) ($params['precision'] ?? 2), RoundingMode::HALF_UP);
    }

    /**
     * 单个参数的值校验。
     *
     * @param array<string, mixed> $spec
     */
    private static function normalizeParam(string $type, ?string $label, string $key, array $spec, mixed $value): mixed
    {
        $where = $label === null ? '字段类型「' . static::labelOf($type) . '」' : "字段「{$label}」";

        switch ($spec['kind']) {
            case 'int':
            case 'number':
                // 前端 JSON 传来的数字可能是字符串（表单控件一律字符串），这里统一归一后再判范围。
                if ($value === '' || $value === null) {
                    if (array_key_exists('default', $spec)) {
                        return $spec['default'];
                    }
                    throw new BaseException("{$where}的参数「{$key}」不能为空");
                }
                if (! is_numeric($value) || ($spec['kind'] === 'int' && filter_var($value, FILTER_VALIDATE_INT) === false)) {
                    throw new BaseException("{$where}的参数「{$key}」必须是数字");
                }
                $number = $spec['kind'] === 'int' ? (int) $value : (float) $value;
                if (($spec['min'] ?? null) !== null && $number < $spec['min']) {
                    throw new BaseException("{$where}的参数「{$key}」不能小于 {$spec['min']}");
                }
                if (($spec['max'] ?? null) !== null && $number > $spec['max']) {
                    throw new BaseException("{$where}的参数「{$key}」不能大于 {$spec['max']}");
                }

                return $number;

            case 'string':
                // HTTP 中间件会把空字符串转为 null；仅恢复契约明确允许留空的文本参数。
                if ($value === null && ($spec['default'] ?? null) === '') {
                    $value = '';
                }
                if (! is_scalar($value)) {
                    throw new BaseException("{$where}的参数「{$key}」必须是文本");
                }
                $text = trim((string) $value);
                if (($spec['max_length'] ?? null) !== null && mb_strlen($text) > $spec['max_length']) {
                    throw new BaseException("{$where}的参数「{$key}」不能超过 {$spec['max_length']} 个字符");
                }

                return $text;

            case 'values':
                if (! is_array($value) || ! array_is_list($value) || count($value) > $spec['max_items']) {
                    throw new BaseException("{$where}的参数「{$key}」必须是选项值列表");
                }
                foreach ($value as $entry) {
                    if (! is_string($entry) && ! is_int($entry)) {
                        throw new BaseException('停用选项值必须是文本或整数');
                    }
                }

                return array_values(array_unique(array_map('strval', $value)));

            case 'options':
                if (! is_array($value) || $value === []) {
                    throw new BaseException("{$where}的参数「{$key}」不能为空");
                }
                if (count($value) > $spec['max_items']) {
                    throw new BaseException("{$where}的参数「{$key}」不能超过 {$spec['max_items']} 条");
                }
                $options = [];
                foreach ($value as $optionValue => $optionLabel) {
                    // 值与标签都必须是标量：数组值代表「想塞字典」，本表不支持（见 MAX_OPTIONS 注释）。
                    if (! is_int($optionValue) && ! is_string($optionValue)) {
                        throw new BaseException("{$where}的参数「{$key}」的选项值必须是整数或字符串");
                    }
                    if (! is_string($optionLabel) && ! is_int($optionLabel) && ! is_float($optionLabel)) {
                        throw new BaseException("{$where}的参数「{$key}」的选项标签必须是文本");
                    }
                    $text = trim((string) $optionLabel);
                    if ($text === '' || mb_strlen($text) > 64) {
                        throw new BaseException("{$where}的参数「{$key}」的选项标签不能为空且不能超过 64 个字符");
                    }
                    $options[$optionValue] = $text;
                }

                return $options;

            default:
                // 声明写错（元 schema 自身的问题），不是用户输入问题 —— 让它在开发期就炸出来。
                throw new LogicException("字段类型「{$type}」的参数「{$key}」声明了未知 kind：{$spec['kind']}");
        }
    }

    private static function fail(string $type, ?string $label, string $reason): BaseException
    {
        $where = $label === null ? '字段类型「' . static::labelOf($type) . '」' : "字段「{$label}」";

        return new BaseException("{$where}{$reason}");
    }

    /** 领域登记表可追加已在其前端注册的控件，通用登记表不接入业务组件。 */
    public static function supportedWidgets(): array
    {
        return FormWidgetTypes::FORMER;
    }

    /** @return list<string> 登记表自身违规描述；空数组 = 合规。 */
    public static function violations(): array
    {
        $violations = [];
        $seen       = [];

        foreach (static::definitions() as $type => $def) {
            if (isset($seen[$type])) {
                $violations[] = "类型标识「{$type}」重复登记";
            }
            $seen[$type] = true;

            if (! in_array($def['widget'], static::supportedWidgets(), true)) {
                $violations[] = "类型「{$type}」的组件名「{$def['widget']}」未在支持的控件清单内";
            }
            foreach ($def['params'] as $key => $spec) {
                if (! in_array($spec['kind'], ['int', 'number', 'string', 'options', 'values'], true)) {
                    $violations[] = "类型「{$type}」的参数「{$key}」声明了未知 kind「{$spec['kind']}」";
                }
                if ($spec['kind'] === 'options' && ! isset($spec['max_items'])) {
                    $violations[] = "类型「{$type}」的选项参数「{$key}」缺少 max_items 上限";
                }
            }
        }

        return $violations;
    }
}
