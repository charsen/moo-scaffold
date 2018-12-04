<?php
namespace Charsen\Scaffold\Generator;

use Route;

/**
 * UpdateACLGenerator
 *
 * @author Charsen https://github.com/charsen
 */
class UpdateACLGenerator extends Generator
{
    
    /**
     * 只做增量，不做替换，因为可能会有手工润色
     *
     */
    public function start()
    {
        $route_actions = $this->getActions();
    
        $code  = "<?php";
        $code .= "\n";
        $code .= "use Illuminate\Auth\Access\Gate;\n";
        $code .= "use Illuminate\Support\Facades\Auth;\n";
        $code .= "\n";
        
        foreach ($route_actions as $controller => $actions)
        {
            $controller = str_replace(['App\\Http\\Controllers\\', '\\', 'Controller'], ['', '.', ''], $controller);
            
            foreach ($actions as $action)
            {
                $code .= $this->getOneCode($controller, $action);
            }
        }
    
        $put  = $this->filesystem->put(app_path('ACL.php'), $code);
        if ($put)
        {
            return $this->command->info('+ ./app/ACL.php (Updated)');
        }
    
        return $this->command->error('x ./app/ACL.php (Failed)');
    }
    
    /**
     * 获取一个 action 的代码
     *
     * @param $controller
     * @param $action
     *
     * @return string
     */
    private function getOneCode($controller, $action)
    {
        $acl_action = md5($controller . '@' . $action);
        
        $code       = [
            "Gate::define('{$acl_action}', function() {",
            $this->getTabs(1) . "return Auth::guard('api')->user()->hasAction('{$acl_action}');",
            "});",
            "",
        ];
        
        return implode("\n", $code);
    }
    
    /**
     * 获取 动作
     *
     * @return array
     */
    private function getActions()
    {
        $routes         = Route::getRoutes();
        $controllers    = [];
        foreach ($routes as $route)
        {
            $action_name = ltrim($route->getActionName(), '\\');
            if ( ! strstr($action_name, 'App\\Http\\Controllers\\'))
            {
                continue;
            }
            list($controller, $action) = explode('@', $action_name);
            
            $controllers[$controller][] = $action;
        }
        
        return $controllers;
    }
}
