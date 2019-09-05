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
    public function rules()
    {
        //list($class, $action) = explode('@', \Route::current()->getActionName());
        list($class, $action) = explode('@', \Route::currentRouteAction());

        return $this->getActionRules($action);
    }

    /**
     *  获取指定 action 的验证规则
     *
     * @param  $action
     * @return array
     */
    public function getActionRules($action = NULL)
    {
        if ($action === NULL) return [];

        $result = \method_exists($this, $action . "Rules") ? $this->{$action . "Rules"}() : [];

        return $result;
    }

    /**
     * 转换成前端验证规则
     *
     * @param  array $all_rules
     * @return array
     */
    public function getFrontendRules(array $all_rules)
    {
        if (empty($all_rules)) return [];

        // 若是给提前端提供验证规则，去掉 unique 规则
        $frontend_rules = [];

        foreach ($all_rules as $field => $rules) {
            foreach ($rules as $k => $r) {
                if ( ! is_string($r)) {
                    continue;
                }

                if (strstr($r, 'unique:')) {
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
     * @param  $model
     * @param  $filed
     * @return string
     */
    public function getDictKeys($model, $filed)
    {
        return implode(',', array_keys($model->{"init_{$filed}"}));
    }

    /**
     * 获取 Uniquue 的规则
     *
     * @param  $model
     * @param  $field           // 当是编辑动作时，要排除自己，必传
     * @param  $route_key       // 当是编辑动作时，要排除自己，必传
     * @return string
     */
    public function getUnique($model, $field = null, $route_key = null)
    {
        $rule = 'unique:' . $model->getTable();
        if ($route_key != null)
        {
            $rule .= ",{$field}," . $this->route($route_key);
        }

        return $rule;
    }
}
