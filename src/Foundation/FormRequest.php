<?php

namespace Charsen\Scaffold\Foundation;

use Illuminate\Foundation\Http\FormRequest as BaseFormRequest;

class FormRequest extends BaseFormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        //list($class, $action) = explode('@', \Route::currentRouteAction());
        $action = $this->route()->getActionMethod();
        return $this->getActionRules($action);
    }

    /**
     *  获取指定 action 的验证规则
     *
     * @param  $action
     * @return array
     */
    public function getActionRules($action = NULL): array
    {
        if ($action === NULL) return [];

        $result = \method_exists($this, $action . "Rules") ? $this->{$action . "Rules"}() : [];

        return $result;
    }

    /**
     * 获取通过某动作的验证规则转换的表单控件
     *
     * @param  array  $reset
     * @return array
     */
    public function getFormWidgets($method, array $reset = [], $excute = [])
    {
        $all_rules      = $this->getActionRules($method);
        $frontend_rules = []; //$this->getFrontendRules($all_rules);

        return $this->transformFormWidgets($all_rules, $frontend_rules, $reset, $excute);
    }

    /**
     * 根据验证规则转换成表单控件
     *
     * @param  array $all_rules
     * @param  array $frontend_rules
     * @param  array $reset
     * @return array
     */
    private function transformFormWidgets(array $all_rules, array $frontend_rules, array $reset = [], $excute = [])
    {
        if (empty($all_rules)) return [];

        $result = [];
        foreach ($all_rules as $field_name => $rules)
        {
            // 排除指定的字段
            if (in_array($field_name, $excute)) continue;

            $tmp = [
                'name'      => $field_name,
                'required'  => ! in_array('nullable',  $rules), //! (in_array('sometimes', $rules) OR in_array('nullable',  $rules)),
                'rules'     => $frontend_rules[$field_name] ?? [],
            ];

            // 通过字段类型指定 控件类型, todo: datetime?
            if (in_array('date', $rules))
            {
                $tmp['type'] = 'date-picker';
            }
            elseif (in_array('time', $rules))
            {
                $tmp['type'] = 'time-picker';
            }
            elseif (in_array('date-time', $rules))
            {
                $tmp['type'] = 'date-time-picker';
            }
            elseif (strstr($field_name, 'password'))
            {
                $tmp['type'] = 'password';
            }

            foreach ($rules as $r)
            {
                // 闭包函数
                if ( ! is_string($r))
                {
                    continue;
                }

                if (preg_match('/^in\:[0-9\,]+$/i', $r))
                {
                    // 判断 model 的字典字段
                    if (property_exists($this, "init_" . $field_name))
                    {
                        $tmp['type']        = 'radio';
                        $tmp['dictionary']  = true;
                        $tmp['options']     = $this->{"init_{$field_name}"};
                    }
                }
            }

            // 直接替换，合并
            if (isset($reset[$field_name]))
            {
                $tmp = array_merge($tmp, $reset[$field_name]);
                unset($reset[$field_name]);
            }

            $result[$field_name] = $tmp;
        }

        // 若 reset 还存在数据，则是新增的，直接附加进去
        return \array_merge($result, $reset);
    }

    /**
     * 转换成前端验证规则
     *
     * @param  array $all_rules
     * @return array
     */
    private function getFrontendRules(array $all_rules): array
    {
        if (empty($all_rules)) return [];

        // 若是给提前端提供验证规则，去掉 unique 规则
        $frontend_rules = [];

        foreach ($all_rules as $field => $rules) {
            foreach ($rules as $k => $r) {
                if ( ! is_string($r)) {
                    continue;
                }

                if (in_array($r, ['sometimes', 'nullable'])) {
                    $frontend_rules[$field][$r] = '';
                    continue;
                }

                $key        = preg_replace('/\:.+/i', '', $r);
                $message    = __('validation.' . $key);
                //$message    = str_replace(':attribute', __('validation.attributes.' . $field), $message);

                $frontend_rules[$field][$r] = is_array($message) ? $message['string'] : $message;
            }
        }

        return $frontend_rules;
    }

    /**
     * 获取模型某字典字段的键值
     *
     * @param  $filed
     * @return string
     */
    private function getDictKeys($filed): string
    {
        //return implode(',', array_keys($this->{"init_{$filed}"}));
        return implode(',', array_keys($this->{"init_{$filed}"}));
    }

    /**
     * 获取模型字典字段的 In 验证规则
     *
     * 例：在列表全部时，手动新增加了一个 0 => 'all'， 这时需要让 $append = '0'
     *
     * @param string $filed
     * @return string
     */
    protected function getInDict($filed, $append = ''): string
    {
        $append = ($append == '') ? '' : $append . ',';
        return 'in:' . $append . $this->getDictKeys($filed);
    }

    /**
     * 获取 Uniquue 的规则
     *
     * @param  $field           // 当是编辑动作时，要排除自己，必传
     * @param  $route_key       // 当是编辑动作时，要排除自己，必传
     * @return string
     */
    protected function getUnique($field = null, $route_key = null): string
    {
        $rule = 'unique:' . $this->model_table;

        if ($field != null && $route_key != null)
        {
            $rule .= ",{$field}," . $this->route($route_key);
        }

        return $rule;
    }

}
