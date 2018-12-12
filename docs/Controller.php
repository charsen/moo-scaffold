<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;
    
    /**
     * 根据白名单检查是否启用 can 中间件
     */
    protected function checkAuthorization()
    {
        $whitelist = config('actions.whitelist');
        $acl_key  = $this->getAclActionName();
        if ( ! in_array($acl_key, $whitelist))
        {
            $this->middleware('can:' . $acl_key);
        }
    }
    
    /**
     * 获取当前 Gate 验证的动作名称
     *
     * @return string
     */
    protected function getAclActionName()
    {
        $controller = $this->getCurrentControllerName();
        $action     = $this->getCurrentMethodName();
        
        //把两个表单动作转换为 对应的功能动作
        $transform = ['create' => 'store', 'edit' => 'update'];
        $action    = isset($transform[$action]) ? $transform[$action] : $action;
    
        // App\Http\Controllers\Enterprise\Personnels\DepartmentController@index
        //dump($controller . '@' . $action, substr(md5($controller . '@' . $action), 8, 16));
        return substr(md5($controller . '@' . $action), 8, 16);
    }
    
    /**
     * 获取当前控制器名
     *
     * @return string
     */
    public function getCurrentControllerName()
    {
        return $this->getCurrentAction()['controller'];
    }
    
    /**
     * 获取当前方法名
     *
     * @return string
     */
    public function getCurrentMethodName()
    {
        return $this->getCurrentAction()['method'];
    }
    
    /**
     * 获取当前控制器与方法
     *
     * @return array
     */
    public function getCurrentAction()
    {
        $action = \Route::current()->getActionName();
        list($class, $method) = explode('@', $action);
        
        return ['controller' => $class, 'method' => $method];
    }
}
