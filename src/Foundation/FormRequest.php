<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2024-07-30 11:47
 * @LastEditors: Charsen
 * @LastEditTime: 2026-03-14 15:50
 * @Description: Form Request
 */

namespace Mooeen\Scaffold\Foundation;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\FormRequest as BaseFormRequest;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Mooeen\Scaffold\Exceptions\FormLayoutException;

class FormRequest extends BaseFormRequest
{
    /**
     * plan-51:Schema::hasColumn 调用走 information_schema,在 Request rule 派发链路
     * 每行 unique 字段触发一次。同表多字段 + 同进程内 idempotent,加 static cache 避免
     * 重复查询(php-fpm worker lifetime 内单次查询/表)。
     *
     * @var array<string,bool>
     */
    private static array $softDeleteColumnCache = [];
    /**
     * Determine if the user is authorized to make this request.
     */
    // public function authorize(): bool
    // {
    //     return true;
    // }

    /**
     * 获取模型枚举字段的 In 验证规则
     * 例：在列表全部时，手动新增加了一个 0 => 'all'， 这时需要让 $append = '0'
     */
    protected function getInEnums(array $values, string $append = ''): string
    {
        $append = ($append === '') ? '' : $append . ',';

        return 'in:' . $append . implode(',', $values);
    }

    protected function allInEnums(array $values, string $append = '')
    {
        if ($append !== '') {
            array_unshift($values, $append);
        }

        return Rule::in($values);
    }

    /**
     * 获取 Unique 的规则(plan-51:app-level soft-aware 默认 / db-level strict 可选)
     *
     * yaml `unique: true` 原设计意图是 **应用层唯一性检查 + 软删过滤**:
     * 软删除后的"同名"记录不应阻止新记录建立。
     * 旧实现 `unique:table,col` 不过 deleted_at,违反设计 — 本次修复改用 Rule::unique
     * builder + Schema::hasColumn 自动检测 deleted_at 加 whereNull('deleted_at')。
     *
     * yaml `db_unique: true`(DB 强约束)调用时传 $soft=false:跨软删唯一,跟 DB 行为对齐,
     * 避免 Request 通过但 DB 拒绝的尴尬 UX。
     *
     * 表无 deleted_at 列(join 表 / 系统表)时,无论 $soft 取值都 fallback Laravel 默认 unique。
     *
     * @param string      $model_table 表名
     * @param string|null $field       字段名(update 排除自己时必传)
     * @param string|null $route_key   路由参数 key(update 时必传,用于 ignore 自身 id)
     * @param bool        $soft        app-level soft-aware(默认 true);db-level strict 传 false
     */
    protected function getUnique(string $model_table, $field = null, $route_key = null, bool $soft = true): Unique
    {
        // field 没传时默认按 NULL 给(Laravel 会自动用 attr name) — 兼容历史 caller
        $rule = $field !== null
            ? Rule::unique($model_table, $field)
            : Rule::unique($model_table);

        if ($field !== null && $route_key !== null) {
            $rule->ignore($this->route($route_key), 'id');
        }

        // soft-aware:仅当表有 deleted_at 列 + 调用方未显式 opt-out 时,排除软删记录
        if ($soft && $this->tableHasSoftDelete($model_table)) {
            $rule->whereNull('deleted_at');
        }

        return $rule;
    }

    private function tableHasSoftDelete(string $table): bool
    {
        if (! isset(self::$softDeleteColumnCache[$table])) {
            try {
                self::$softDeleteColumnCache[$table] = Schema::hasColumn($table, 'deleted_at');
            } catch (\PDOException|QueryException $e) {
                // 仅吞 DB 元数据查询失败(连接挂 / 权限不足 / 表不存在等)→ 降级为"无软删"
                // 不吞其它异常,让真 bug(class missing / config 错)正常上抛
                self::$softDeleteColumnCache[$table] = false;
            }
        }

        return self::$softDeleteColumnCache[$table];
    }

    /**
     * 仅供测试用:清空 soft-delete 列缓存(避免跨 test 状态污染)
     *
     * @internal
     */
    public static function clearSoftDeleteCache(): void
    {
        self::$softDeleteColumnCache = [];
    }

    /**
     * 获取 模型主键 是否存在的规则
     */
    protected function getExistId(string $model_table): string
    {
        return 'exists:' . $model_table . ',id';
    }

    /**
     * 获取通过某动作的验证规则转换的表单控件
     */
    public function getFormConfig(array $reset = [], array $exclude = [], bool $with_default = false): array
    {
        $rules          = $this->rules();
        $frontend_rules = $this->getFrontendRules($rules);

        return $this->formatFormConfig($rules, $frontend_rules, $reset, $exclude, $with_default);
    }

    /**
     * 表单布局，子类可覆盖
     */
    public function formLayout(): array
    {
        return [];
    }

    /**
     * 依据 Request 中的验证规则，检查布局中的字段是否存在
     */
    public function checkFormLayout(array $layout): array
    {
        $rules = $this->rules();
        unset($rules['page'], $rules['page_limit']);

        // 对 .* 规则的特殊处理
        foreach ($rules as $field => $rule) {
            if (str_contains($field, '.*')) {
                unset($rules[$field]);
                $field         = str_replace('.*', '', $field);
                $rules[$field] = $rule;
            }
        }

        foreach ($layout as $row => $widgets) {
            // 一行一个控件
            if (count($widgets) === 1) {
                $field = array_key_first($widgets);
                $field = is_int($field) ? $widgets[$field] : $field;
                if (! isset($rules[$field])) {
                    throw new FormLayoutException(__('exception.form.layout_field_not_in_rules', ['field' => $field]));
                }
                unset($rules[$field]);
            }
            // 一行多个控件
            else {
                foreach ($widgets as $field => $config) {
                    $field = is_int($field) ? $config : $field;
                    if (! isset($rules[$field])) {
                        throw new FormLayoutException(__('exception.form.layout_field_not_in_rules', ['field' => $field]));
                    }
                    unset($rules[$field]);
                }
            }
        }

        // 反向检查 规则中 是否还有未设置布局的字段
        if (count($rules) > 0) {
            $field = implode(', ', array_keys($rules));
            throw new FormLayoutException(__('exception.form.layout_rules_field_unset', ['field' => $field]));
        }

        return $layout;
    }

    /**
     * 转换成前端验证规则
     */
    private function getFrontendRules(array $all_rules): array
    {
        if (empty($all_rules)) {
            return [];
        }

        // 若是给提前端提供验证规则，去掉 unique 规则
        $frontend_rules = [];

        foreach ($all_rules as $field_name => $rules) {
            // 对 .* 规则的特殊处理
            $field = str_contains($field_name, '.*') ? str_replace('.*', '', $field_name) : $field_name;

            foreach ($rules as $k => $rule) {
                if (! is_string($rule) || str_contains($rule, '$this->get') || str_contains($rule, 'exists:')) {
                    continue;
                }

                $key     = preg_replace('/\:.+/i', '', $rule);
                $message = $key === 'nullable' ? '' : __('validation.' . $key);
                $message = str_replace(':attribute', __('validation.attributes.' . $field), $message);

                $frontend_rules[$field][] = ['rule' => $rule, 'msg' => is_array($message) ? $message['string'] : $message];
            }
        }

        return $frontend_rules;
    }

    /**
     * 根据验证规则转换成表单控件
     */
    private function formatFormConfig(array $all_rules, array $frontend_rules, array $reset, array $exclude, bool $with_default): array
    {
        if (empty($all_rules)) {
            return [];
        }

        foreach ($exclude as $field) {
            unset($reset[$field]);

            if (str_contains($field, '.*')) {
                unset($reset[str_replace('.*', '', $field)]);
            }
        }

        $result = [];
        foreach ($all_rules as $field_name => $rules) {
            // 对 .* 规则的特殊处理
            $field = str_contains($field_name, '.*') ? str_replace('.*', '', $field_name) : $field_name;

            // 排除指定的字段
            if (in_array($field, $exclude, true) || in_array($field_name, $exclude, true)) {
                unset($reset[$field]);

                continue;
            }

            $tmp = [
                'field'    => $field,
                'required' => in_array('required', $rules, true),
                // ! (in_array('sometimes', $rules) OR in_array('nullable',  $rules)),
                'rules' => $frontend_rules[$field] ?? [],
            ];

            // 通过字段类型指定 控件类型
            // https://learnku.com/docs/laravel/10.x/validation/14856#189a36
            if (in_array('date', $rules, true)) {
                $tmp['type'] = 'date-picker';
            } elseif (str_contains($field, 'password')) {
                $tmp['type'] = 'password';
            }

            // model enum 字段处理, options() 是在业务的 FormRequest 中定义的
            if (method_exists($this, 'options') && ! empty($options = $this->options($field))) {
                $tmp['type']       = 'radio';
                $tmp['dictionary'] = true;
                $tmp['options']    = $options;

                if (str_contains($field_name, '.*')) {
                    $tmp['multiple'] = true;
                }
                if ($with_default) {
                    $tmp['default'] = array_key_first($options);
                }
            }

            // 直接替换，合并
            if (isset($reset[$field])) {
                $tmp = [...$tmp, ...$reset[$field]];
                unset($reset[$field]);
            }

            $result[$field] = $tmp;
        }

        // 若 reset 还存在数据，则是新增的，直接附加进去
        return array_merge($result, $reset);
    }
}
