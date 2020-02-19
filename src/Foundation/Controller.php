<?php

namespace Charsen\Scaffold\Foundation;

use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    protected $method;
    protected $transform_methods  = ['create' => 'store', 'edit' => 'update'];

    /**
     * Execute an action on the controller
     *
     * @param  string $method
     * @param  array $parameters
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function callAction($method, $parameters)
    {
        $this->method = $method;

        // 假如存在boot方法 就执行中间件之后 先执行boot 再执行action
        // https://github.com/laravel/framework/blob/5.8/src/Illuminate/Routing/ControllerDispatcher.php#L44
        if (method_exists($this, 'boot')) {
            $this->boot();
        }

        return call_user_func_array([$this, $method], $parameters);
    }

    /**
     * Check Authorization
     *
     * @param string $method
     * @return \Illuminate\Auth\Access\Response
     */
    protected function checkAuthorization()
    {
        $method     = $this->transform_methods[$this->method] ?? $this->method;
        // App\Http\Controllers\Enterprise\Personnels\DepartmentController@index
        // dump($controller . '@' . $action, substr(md5($controller . '@' . $action), 8, 16));
        $acl_name   = substr(md5(static::class . '@' . $method), 8, 16);
        $this->authorize('acl_authentication', $acl_name);
    }

    /**
     * 设置需要转换的动作
     *
     * @param  array $transform
     * @return void
     */
    protected function setTransformMethods(array $transform)
    {
        $this->transform_methods = \array_merge($this->transform_methods, $transform);
    }

    /**
     * 获取数据库字段的文字
     *
     * @param  array $fields  数据库字段
     * @param  array $append  附加的字段（不是已存在的数据库字段，需要额外添加多语言配置）
     * @return array
     */
    public function getDBFieldsTxt(array $fields, array $append = [])
    {
        $result = [];
        $fields = \array_merge($fields, $append);
        foreach ($fields as $key) {
            $result[$key] = __('db.' . $key); // __('validation.attributes.' . $key)
        }

        return $result;
    }

    /**
     * 获取通过某动作的验证规则转换的表单控件
     *
     * @param  \Illuminate\Foundation\Http\FormRequest $request
     * @param  array  $reset
     * @return array
     */
    public function getFormWidgets($request, array $reset = [])
    {
        $method         = $this->transform_methods[$this->method] ?? $this->method;
        $all_rules      = $request->getActionRules($method);
        $frontend_rules = []; //$request->getFrontendRules($all_rules);

        return $this->transformFormWidgets($all_rules, $frontend_rules, $reset);
    }

    /**
     * 根据验证规则转换成表单控件
     *
     * @param  array $all_rules
     * @param  array $frontend_rules
     * @param  array $reset
     * @return array
     */
    private function transformFormWidgets(array $all_rules, array $frontend_rules, array $reset = [])
    {
        if (empty($all_rules)) return [];

        $result = [];
        foreach ($all_rules as $field_name => $rules) {
            $tmp = [
                'id'        => $field_name,
                'required'  => ! (in_array('sometimes', $rules) OR in_array('nullable',  $rules)),
                //'rules'      => $frontend_rules[$field_name] ?? [],
            ];

            // 通过字段类型指定 控件类型, todo: datetime?
            if (in_array('data', $rules)) {
                $tmp['type'] = 'date-picker';
            }

            if (strstr($field_name, 'password')) {
                $tmp['type'] = 'password';
            }

            foreach ($rules as $r) {
                // 闭包函数
                if ( ! is_string($r)) {
                    continue;
                }

                if (preg_match('/^in\:[0-9\,]+$/i', $r)) {
                    // 判断 model 的字典字段
                    if (property_exists($this->model, "init_" . $field_name)) {
                        $tmp['type']        = 'radio';
                        $tmp['dictionary']  = true;
                        $tmp['options']     = $this->model->{"init_" . $field_name};
                    }
                }
            }

            // 直接替换，合并
            if (isset($reset[$field_name])) {
                $tmp = array_merge($tmp, $reset[$field_name]);
                unset($reset[$field_name]);
            }

            $result[$field_name] = $tmp;
        }

        // 若 reset 还存在数据，则是新增的，直接附加进去
        return \array_merge($result, $reset);
    }
}
