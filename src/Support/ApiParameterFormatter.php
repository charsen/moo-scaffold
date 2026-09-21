<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use Illuminate\Support\Arr;

/**
 * 调试页 / 文档页**参数形状归一**的唯一口径（2026-09-20 自 `Http\Controllers\ApiController` 外迁，第 4 项）。
 *
 * **它是什么**：把「FormRequest 验证规则」与「API YAML 里手写的 `url_params` / `body_params`」
 * 两种来源，归一成调试页/文档页共用的同一种参数行形状
 * （`require` / `name` / `value` / `desc` / `rules` / `type` / `display_key` / `send_key` / `sendable`，
 * 枚举字段另有 `options`）。
 *
 * **为什么外迁**：这一族 15 个方法 / 466 行，原先是 `ApiController`（1237 行）里**最大的一块同族聚集**
 * —— 只被 4 个入口方法调用、彼此只互相调用、与控制器其余部分零往来。属于「两个东西住一个类」，
 * 与 §3.1 析出 proxy 段同一条判据。重开 `TUNING-PLAN.md` §五「拆大类」旧决定的四条新证据见 `NOTES.md` 同日条。
 *
 * 迁移映射（旧名 → 新名；**方法体逐字未动**，只换了接收者 `$this` → `self`）：
 * - 4 个入口：`formatRules()` / `formatYamlParams()` / `mergeDebugParams()` / `formatToFaker()`
 * - 11 个私有助手：`inheritMissingDebugParamMeta()` / `resolveParameterLabel()` /
 *   `applyParameterFieldMeta()` / `resolveParameterFieldKey()` / `formatParameterDisplayKey()` /
 *   `isRuleParameterRequired()` / `isRuleParameterSendable()` / `isScalarArrayElement()` /
 *   `resolveRuleParameterType()` / `buildRuleParameterDescription()` / `appendParameterHint()`
 *
 * **为什么是 `final` + 全静态 + 无构造函数**（与 {@see Paths} / {@see StorageRegistry} / {@see ActionMeta} 同形）：
 * 本类**零状态**、不碰文件系统、不读 `config()`；唯一的外部依赖是 `Arr` 这个无状态 façade（方法内静态调用）。
 * 归一所需的**数据全部由调用方传入**，故既不需要容器解析、也不需要构造注入，调用点不能替换实现 —— 但本仓
 * 对这些方法的既有测试本来就是「给确定的入参、断确定的出参」（见 `tests/Feature/Support/ApiParameterFormatterTest.php`），
 * 没有替换需求。
 *
 * **两处刻意留在调用方、别顺手搬进来的东西**（都涉及「跨请求敏感」的状态）：
 * - **`$metadata`**（`enums` / `fields` / `lang_fields`）：由调用方 `ApiController::getParameterMetadata()`
 *   归一后传入。**方法本身没跟来** —— 它持有 memo（`private ?array $parameterMetadata`）与 `Utility` 依赖
 *   （`Utility::getLangFields()`）。memo 若做成本类的 `static` 会**跨请求残留**
 *   （`StorageRegistryTest` 正有一条守卫挡这个：重写缓存文件后下一次必须读到新值）；把 `Utility` 注进来又会让
 *   本类从「纯计算」变成「有依赖」，上一条同形的理由就没了。
 * - **`$latestIdResolver`**（`callable`）：`formatRules` 里「`exists:Model,id` ⇒ 默认填最新 ID」这一步需要
 *   Eloquent 查询 + `$latestModelIds` memo（跨请求敏感，同上），且原实现 `resolveLatestModelIdFromRules()` /
 *   `resolveExistsModelClass()` / `getLatestModelIdByClass()` **另有调用方**
 *   （`ApiController::resolveRouteParamValue()`）⇒ 三个方法一并留在控制器，这里只收一个**解算器回调**。
 *   签名 `callable(array $rules): int|string|null`。
 *
 * **有意保留的死变量**：{@see formatYamlParams()} 里 `$enums` / `$fields` 两个局部变量**没有任何读取方**
 * （外迁前就没有）—— 原样搬、不顺手删，与 3b-3 保留 `StorageRegistry::controllers()` 的 `$merge_all = true`
 * 死分支同一条纪律。
 */
final class ApiParameterFormatter
{
    /**
     * 把 FormRequest 验证规则归一成调试页参数行。
     *
     * @param string   $actionName       真实 action 名（`destroy` / `destroyBatch` 会改 `force` 的措辞）
     * @param array    $rules            `field => [rule, ...]`，来自 FormRequest::rules()
     * @param array    $metadata         调用方归一好的 `enums` / `fields` / `lang_fields`
     * @param callable $latestIdResolver 解算「`exists:Model,id` ⇒ 最新 ID」，签名 `(array $rules): int|string|null`
     */
    public static function formatRules(
        string $actionName,
        array $rules,
        array $metadata,
        callable $latestIdResolver,
    ): array {
        $ruleKeys = array_keys($rules);

        $data = [];
        foreach ($rules as $key => $attr) {
            // 数组-标量元素(field.*: numeric/string 等,无 field.*.xxx 深层)→ 被父数组吸收,不单列成参数。
            // (原先 field + field.* 各出一个参数 → 调试页一个数组被错解析成两行)
            if (self::isScalarArrayElement($key, $ruleKeys) && in_array(substr($key, 0, -2), $ruleKeys, true)) {
                continue;
            }
            $sendable   = self::isRuleParameterSendable($key, $attr, $ruleKeys);
            $displayKey = self::formatParameterDisplayKey($key);
            $data[$key] = [
                'require' => self::isRuleParameterRequired($attr),
                'name'    => self::resolveParameterLabel($key, $metadata),
                'value'   => $sendable ? '' : (str_ends_with($key, '.*') ? '{}' : '[]'),
                // 2026-06-20:desc 与 rules 拆开 —— desc=填写/语义提示(下方 ids/force/最新ID 赋值);
                //   rules=验证约束(长度/必填条件等)。调试表说明列只显 desc,约束移到 VALUE hover;
                //   文档页仍合并 rules+desc 显示(外观不变)。
                'desc'        => '',
                'rules'       => self::buildRuleParameterDescription($key, $attr, $ruleKeys, $sendable),
                'type'        => self::resolveRuleParameterType($key, $attr, $metadata),
                'display_key' => $displayKey,
                'send_key'    => $sendable ? $displayKey : '',
                'sendable'    => $sendable,
            ];

            if ($key === 'page') {
                $data[$key]['value'] = 1;
            } elseif ($key === 'page_limit') {
                $data[$key]['value'] = 10;
            } elseif ($key === 'ids') {
                $data[$key]['value'] = '2,3';
                $data[$key]['desc']  = '使用半角逗号（,）分隔为数组';
            } elseif ($key === 'force') {
                $data[$key]['require'] = false;
                $data[$key]['name']    = in_array($actionName, ['destroy', 'destroyBatch']) ? '强制删除' : '强制';
                $data[$key]['value']   = 1;
                $data[$key]['desc']    = '{0: false, 1: true}';
            }

            // 数组-标量(父,有 field.* 标量子键)→ 可单发,提示按数组填(逗号分隔或 [..] JSON)
            if ($sendable && in_array('array', $attr, true) && in_array($key . '.*', $ruleKeys, true)) {
                $data[$key]['desc'] = self::appendParameterHint($data[$key]['desc'], '数组，逗号分隔或 JSON');
            }

            $latestModelId = $latestIdResolver($attr);
            if ($latestModelId !== null && $latestModelId !== '') {
                $data[$key]['value'] = (string) $latestModelId;
                $data[$key]['desc']  = self::appendParameterHint($data[$key]['desc'], '默认最新 ID');
            }

            self::applyParameterFieldMeta($data[$key], $key, $metadata);
        }

        return $data;
    }

    /**
     * 把 API YAML 里手写的参数归一成调试页参数行。
     *
     * 格式：
     * field: [false]                         - 非必填
     * field: []                              - 必填
     * field: [Name, value]                   - 必填，名称，默认值
     * field: [false, Name, value]            - 非必填，名称，默认值
     * field: [false, Name, value, desc]      - 非必填，名称，默认值，描述
     *
     * @param array $metadata 调用方归一好的 `enums` / `fields` / `lang_fields`
     */
    public static function formatYamlParams(array $params, array $metadata): array
    {
        if (empty($params)) {
            return [];
        }

        $enums  = $metadata['enums'];
        $fields = $metadata['fields'];
        $data   = [];

        foreach ($params as $key => $attr) {
            if ($key === '_method') {
                $data[$key] = [
                    'require' => true, 'name' => '',
                    'value'   => strtoupper($attr[0]), 'desc' => '兼容处理',
                ];

                continue;
            }

            if (! is_array($attr)) {
                continue;
            }

            $attr[0] = $attr[0] ?? true;

            if ($attr[0] === false) {
                $name       = $attr[1] ?? self::resolveParameterLabel($key, $metadata);
                $data[$key] = [
                    'require'     => false, 'name' => $name,
                    'value'       => $attr[2] ?? '', 'desc' => $attr[3] ?? '',
                    'display_key' => $key, 'send_key' => $key, 'sendable' => true,
                ];
            } else {
                $name       = is_string($attr[0]) ? $attr[0] : self::resolveParameterLabel($key, $metadata);
                $data[$key] = [
                    'require'     => true, 'name' => $name,
                    'value'       => $attr[1] ?? '', 'desc' => $attr[2] ?? '',
                    'display_key' => $key, 'send_key' => $key, 'sendable' => true,
                ];
            }

            self::applyParameterFieldMeta($data[$key], $key, $metadata);
        }

        return $data;
    }

    /**
     * 合并「规则参数」与「YAML 手写参数」：同键时以覆盖方为准，但覆盖方**缺失/为空**的元信息从基准继承。
     */
    public static function mergeDebugParams(array $baseParams, array $overrideParams): array
    {
        if (empty($overrideParams)) {
            return $baseParams;
        }

        $merged = array_merge($baseParams, $overrideParams);

        foreach ($overrideParams as $key => $overrideParam) {
            if (
                ! isset($baseParams[$key], $merged[$key])
                || ! is_array($baseParams[$key])
                || ! is_array($merged[$key])
            ) {
                continue;
            }

            self::inheritMissingDebugParamMeta($merged[$key], $baseParams[$key]);
        }

        return $merged;
    }

    /**
     * 用 Faker 伪造参数示例值
     *
     * 跳过三类：调用方已填值、`sendable === false`、`_method`（它由请求方法推导，不该伪造）。
     */
    public static function formatToFaker($faker, array $params): array
    {
        if (empty($params)) {
            return [];
        }

        foreach ($params as $fieldName => &$attr) {
            if (($attr['sendable'] ?? true) === false || $attr['value'] !== '' || $fieldName === '_method') {
                continue;
            }

            $type = $attr['type'] ?? null;

            if (str_contains($fieldName, '_ids')) {
                $attr['value'] = $faker->numberBetween(1, 3) . ',' . $faker->numberBetween(4, 7);
            } elseif (str_contains($fieldName, 'media_file')) {
                $attr['value'] = 'temp/demo/example.jpg';
            } elseif ($fieldName === 'password' || str_contains($fieldName, '_password')) {
                $attr['value'] = $faker->password;
            } elseif ($fieldName === 'address' || str_contains($fieldName, '_address')) {
                $attr['value'] = $faker->address;
            } elseif ($fieldName === 'mobile' || str_contains($fieldName, '_mobile')) {
                $attr['value'] = $faker->phoneNumber;
            } elseif ($fieldName === 'email' || str_contains($fieldName, '_email')) {
                $attr['value'] = $faker->safeEmail;
            } elseif ($fieldName === 'user_name' || $fieldName === 'nick_name') {
                $attr['value'] = $faker->userName;
            } elseif ($fieldName === 'id_card_number') {
                $attr['value'] = '';
            } elseif ($fieldName === 'real_name') {
                $attr['value'] = $faker->name(Arr::random(['male', 'female']));
            } elseif (str_contains($fieldName, '_code')) {
                $attr['value'] = $faker->numerify('C####');
            } elseif (in_array($type, ['int', 'tinyint', 'bigint'], true)) {
                $attr['value'] = 1;
            } elseif ($type === 'varchar' || $type === 'char') {
                $attr['value'] = implode(' ', $faker->words(2));
            } elseif ($type === 'text') {
                $attr['value'] = $faker->text(100);
            } elseif ($type === 'date') {
                $attr['value'] = $faker->date();
            } elseif ($type === 'datetime' || $type === 'timestamp') {
                $attr['value'] = $faker->date() . ' ' . $faker->time();
            } elseif ($type === 'boolean') {
                $attr['value'] = rand(0, 1);
                $attr['desc']  = '{1: true, 0: false}';
            }
        }

        return $params;
    }

    /**
     * 只继承覆盖方**没给**的元信息：第一组按「空串视为没给」，第二组按「键不存在视为没给」。
     *
     * 注意两组判据不同 —— `sendable => false` 是**有效覆盖值**，不算「没给」，故第一组里没有它。
     */
    private static function inheritMissingDebugParamMeta(array &$target, array $source): void
    {
        foreach (['value', 'desc', 'name'] as $field) {
            if (($target[$field] ?? '') === '' && ($source[$field] ?? '') !== '') {
                $target[$field] = $source[$field];
            }
        }

        foreach (['type', 'options', 'require', 'display_key', 'send_key', 'sendable'] as $field) {
            if (! isset($target[$field]) && isset($source[$field])) {
                $target[$field] = $source[$field];
            }
        }
    }

    /**
     * 参数显示名：`lang_fields` → `fields` → 归一后的字段键的 `lang_fields` → 其 `fields` → 显示键本身。
     */
    private static function resolveParameterLabel(string $key, array $metadata): string
    {
        $fieldKey = self::resolveParameterFieldKey($key, $metadata);

        return $metadata['lang_fields'][$key]['zh-CN']
            ?? ($metadata['fields'][$key]['zh-CN'] ?? null)
            ?? ($metadata['lang_fields'][$fieldKey]['zh-CN'] ?? null)
            ?? ($metadata['fields'][$fieldKey]['zh-CN'] ?? null)
            ?? self::formatParameterDisplayKey($key);
    }

    /**
     * 按元数据富化单个参数行：**枚举命中 ⇒ 覆盖成 radio + options + 示例值**；否则只用 `type` 补空。
     */
    private static function applyParameterFieldMeta(array &$parameter, string $key, array $metadata): void
    {
        $fieldKey = self::resolveParameterFieldKey($key, $metadata);

        if (isset($metadata['enums'][$key]) || isset($metadata['enums'][$fieldKey])) {
            $enumKey = isset($metadata['enums'][$key]) ? $key : $fieldKey;

            $parameter['value']   = Arr::random(Arr::pluck($metadata['enums'][$enumKey], 0));
            $parameter['options'] = Arr::pluck($metadata['enums'][$enumKey], 2, 0);
            $parameter['type']    = 'radio';

            return;
        }

        $parameter['type'] = $parameter['type']
            ?? $metadata['fields'][$key]['type']
            ?? $metadata['fields'][$fieldKey]['type']
            ?? null;
    }

    /**
     * 把参数键归一到「元数据里可能存在的字段键」：整体命中即原样，否则剥掉 `*` / 纯数字段后取**末段**。
     */
    private static function resolveParameterFieldKey(string $key, array $metadata): string
    {
        if (
            isset($metadata['fields'][$key])
            || isset($metadata['enums'][$key])
            || isset($metadata['lang_fields'][$key])
        ) {
            return $key;
        }

        $segments = preg_split('/[.\[\]]+/', $key, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $segments = array_values(array_filter($segments, static function (string $segment): bool {
            return $segment !== '*' && ! ctype_digit($segment);
        }));

        return $segments === [] ? $key : (string) end($segments);
    }

    /**
     * 点号键 → 方括号显示键（`ids.0` ⇒ `ids[0]`、`field.*` ⇒ `field[0]`）。
     */
    private static function formatParameterDisplayKey(string $key): string
    {
        $segments = explode('.', $key);
        if ($segments === []) {
            return $key;
        }

        $displayKey = array_shift($segments) ?: $key;
        foreach ($segments as $segment) {
            $displayKey .= '[' . ($segment === '*' ? '0' : $segment) . ']';
        }

        return $displayKey;
    }

    /**
     * 是否必填：`required` 优先；`sometimes` / `nullable` / `required_*`（条件必填）都不算；无标记默认必填。
     */
    private static function isRuleParameterRequired(array $rules): bool
    {
        if (in_array('required', $rules, true)) {
            return true;
        }

        if (in_array('sometimes', $rules, true) || in_array('nullable', $rules, true)) {
            return false;
        }

        foreach ($rules as $rule) {
            if (! is_string($rule)) {
                continue;
            }

            if (str_starts_with($rule, 'required_')) {
                return false;
            }
        }

        return true;
    }

    /**
     * 该键能否「单独填一个值发出」：`x.*` 恒否；非数组恒是；数组则看子键是标量元素还是对象/关联元素。
     */
    private static function isRuleParameterSendable(string $key, array $rules, array $allRuleKeys): bool
    {
        if (str_ends_with($key, '.*')) {
            return false;
        }

        if (! in_array('array', $rules, true)) {
            return true;
        }

        // array 类型:有「对象元素」子键(field.*.xxx)或关联子键(field.xxx)→ 父不可单发(改填子字段);
        // 只有「标量元素」子键(field.* 且无更深)→ 数组-标量(如 ids 数组),父可单发(逗号/JSON 一次填),
        // 该 field.* 在 formatRules 里被父吸收、不单列。
        $prefix  = $key . '.';
        $starKey = $key . '.*';
        foreach ($allRuleKeys as $ruleKey) {
            if ($ruleKey === $key || ! str_starts_with($ruleKey, $prefix)) {
                continue;
            }
            if ($ruleKey === $starKey && self::isScalarArrayElement($starKey, $allRuleKeys)) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * field.*(数组元素规则)是否「标量元素」—— 数组里装 id/数字/字符串等标量,
     * 而非嵌套对象(没有更深的 field.*.xxx 子键)。
     */
    private static function isScalarArrayElement(string $key, array $allRuleKeys): bool
    {
        if (! str_ends_with($key, '.*')) {
            return false;
        }
        $deeper = $key . '.';   // field.*.
        foreach ($allRuleKeys as $ruleKey) {
            if ($ruleKey !== $key && str_starts_with($ruleKey, $deeper)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 推断参数类型：规则里的类型标记优先，其次回落元数据 `fields[$key]['type']`，都没有给 `null`。
     */
    private static function resolveRuleParameterType(string $key, array $rules, array $metadata): ?string
    {
        if (in_array('array', $rules, true)) {
            return 'array';
        }

        if (in_array('integer', $rules, true) || in_array('numeric', $rules, true)) {
            return 'int';
        }

        if (in_array('boolean', $rules, true)) {
            return 'boolean';
        }

        if (in_array('date', $rules, true)) {
            return 'date';
        }

        $fieldKey = self::resolveParameterFieldKey($key, $metadata);

        return $metadata['fields'][$key]['type']
            ?? $metadata['fields'][$fieldKey]['type']
            ?? null;
    }

    /**
     * 把验证约束翻成中文人话（进 `rules` 列，不进 `desc`）。
     *
     * `max` / `min` 的措辞**按是不是数组分家**（「最多 3 项」vs「最大长度 10」）；不可单发且有子键时
     * 追加一句引导，让调试者知道该去填子字段。
     */
    private static function buildRuleParameterDescription(string $key, array $rules, array $allRuleKeys, bool $sendable): string
    {
        $parts = [];
        $type  = null;

        if (in_array('array', $rules, true)) {
            $type    = 'array';
            $parts[] = str_ends_with($key, '.*') ? '数组元素对象' : '数组';
        }

        foreach ($rules as $rule) {
            if (! is_string($rule)) {
                continue;
            }

            if (str_starts_with($rule, 'required_with:')) {
                $fields  = array_filter(explode(',', substr($rule, strlen('required_with:'))));
                $parts[] = '传 ' . implode('、', $fields) . ' 时必填';

                continue;
            }

            if (str_starts_with($rule, 'required_without:')) {
                $fields  = array_filter(explode(',', substr($rule, strlen('required_without:'))));
                $parts[] = '缺少 ' . implode('、', $fields) . ' 时必填';

                continue;
            }

            if (str_starts_with($rule, 'max:')) {
                $max     = substr($rule, strlen('max:'));
                $parts[] = in_array($type, ['array'], true) ? '最多 ' . $max . ' 项' : '最大长度 ' . $max;

                continue;
            }

            if (str_starts_with($rule, 'min:')) {
                $min     = substr($rule, strlen('min:'));
                $parts[] = in_array($type, ['array'], true) ? '至少 ' . $min . ' 项' : '最小长度 ' . $min;

                continue;
            }

            if (str_starts_with($rule, 'exists:')) {
                $parts[] = '需为有效 ID';

                continue;
            }
        }

        if (! $sendable) {
            $children = array_values(array_filter($allRuleKeys, static fn (string $ruleKey): bool => str_starts_with($ruleKey, $key . '.')));
            if ($children !== []) {
                $parts[] = '结构说明，调试时请填写子字段';
            }
        }

        $parts = array_values(array_unique(array_filter(array_map('trim', $parts))));

        return implode('；', $parts);
    }

    /**
     * 追加提示：空描述直接取提示；已含该提示不重复；否则用「；」接上。
     */
    private static function appendParameterHint(string $description, string $hint): string
    {
        $description = trim($description);
        if ($description === '') {
            return $hint;
        }

        if (str_contains($description, $hint)) {
            return $description;
        }

        return $description . '；' . $hint;
    }
}
