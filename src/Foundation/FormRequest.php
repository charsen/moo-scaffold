<?php

namespace Mooeen\Scaffold\Foundation;

use Illuminate\Foundation\Http\FormRequest as BaseFormRequest;
use Illuminate\Validation\Rule;

class FormRequest extends BaseFormRequest
{
    protected string $action = '';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 获取模型枚举字段的 In 验证规则
     * 例：在列表全部时，手动新增加了一个 0 => 'all'， 这时需要让 $append = '0'
     */
    protected function getInEnums(array $values, string $append = ''): string
    {
        $append = ($append === '') ? '' : $append . ',';

        return 'in:' . $append . implode(',', $values);
    }

    protected function allInEnums(array $values, string $append = ''): string
    {
        if ($append !== '') {
            array_unshift($values, $append);
        }

        return Rule::in($values);
    }

    /**
     * 获取 Unique 的规则
     *
     * @param  $field  // 当是编辑动作时，要排除自己，必传
     * @param  $route_key  // 当是编辑动作时，要排除自己，必传
     */
    protected function getUnique(string $model_table, $field = null, $route_key = null): string
    {
        $rule = 'unique:' . $model_table;

        if ($field !== null && $route_key !== null) {
            $rule .= ",{$field}," . $this->route($route_key);
        }

        return $rule;
    }

    /**
     * 获取 模型主键 是否存在的规则
     */
    protected function getExistId(string $model): string
    {
        return 'exists:' . $model . ',id';
    }

    /**
     * 获取通过某动作的验证规则转换的表单控件
     */
    public function getFormWidgets(FormRequest $request, array $reset = [], array $exclude = [], bool $with_default = false): array
    {
        $rules          = $request->rules();
        $frontend_rules = [];

        // 创建表单，默认要带上 options 的默认值，比如：model 的 enum
        $with_default = ($this->route()->getActionMethod() === 'create' && ! $with_default) ? true : $with_default;

        return $this->transformFormWidgets($request, $rules, $frontend_rules, $reset, $exclude, $with_default);
    }

    public function options(string $field): array
    {
        return [];
    }

    /**
     * 根据验证规则转换成表单控件
     */
    private function transformFormWidgets($request, array $all_rules, array $frontend_rules, array $reset, array $exclude, bool $with_default): array
    {
        if (empty($all_rules)) {
            return [];
        }

        $result = [];
        foreach ($all_rules as $field_name => $rules) {
            // 对 .* 规则的特殊处理
            $new_field_name = str_contains($field_name, '.*') ? str_replace('.*', '', $field_name) : $field_name;

            // 排除指定的字段
            if (in_array($new_field_name, $exclude, true)) {
                continue;
            }

            $tmp = [
                'field'    => $new_field_name,
                'required' => in_array('required', $rules, true),
                //! (in_array('sometimes', $rules) OR in_array('nullable',  $rules)),
                'rules' => $frontend_rules[$new_field_name] ?? [],
            ];

            // 通过字段类型指定 控件类型
            // https://learnku.com/docs/laravel/8.5/validation/10378#189a36
            if (in_array('date', $rules, true)) {
                $tmp['type'] = 'date-picker';
            } elseif (str_contains($new_field_name, 'password')) {
                $tmp['type'] = 'password';
            }

            // model enum 字段处理
            if (method_exists($request, 'options') && ! empty($options = $request->options($new_field_name))) {
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
            if (isset($reset[$new_field_name])) {
                $tmp = [...$tmp, ...$reset[$new_field_name]];
                unset($reset[$new_field_name]);
            }

            $result[$new_field_name] = $tmp;
        }

        // 若 reset 还存在数据，则是新增的，直接附加进去
        return array_merge($result, $reset);
    }
}
