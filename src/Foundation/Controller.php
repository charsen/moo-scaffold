<?php

namespace Mooeen\Scaffold\Foundation;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Str;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    protected string $method;

    /**
     * 设置需要转换的动作
     * - 控制器内的转换 : ['create' => 'index']
     * - 跨控制器的转换 (完整命名空间): ['index' => 'App\Admin\Controllers\System\DepartmentController::index']
     * - 跨控制器的转换 (同模块简化写法): ['index' => 'DepartmentController::index']
     */
    protected array $transform_methods = [];

    /**
     * Execute an action on the controller
     *
     * @param  string  $method
     * @param  array  $parameters
     */
    public function callAction($method, $parameters)
    {
        $this->method = $method;

        // 假如存在boot方法 就执行中间件之后 先执行boot 再执行action
        // https://github.com/laravel/framework/blob/5.8/src/Illuminate/Routing/ControllerDispatcher.php#L44
        if (method_exists($this, 'boot')) {
            $this->boot();
        }

        return $this->{$method}(...array_values($parameters));
    }

    /**
     * Check Authorization
     *
     * @throws AuthorizationException
     */
    protected function checkAuthorization(): Response|bool
    {
        if (! config('scaffold.authorization.check')) {
            return true;
        }

        $method = $this->getAclMethodName();
        if (is_string($method)) {
            return app(Gate::class)->authorize('acl_authentication', $method);
        }

        $role_actions = getUser()->getActions();
        // 设置了多个可转移动作
        foreach ($method as $item) {
            if (in_array('is_root', $role_actions, true) || in_array($item, $role_actions, true)) {
                return true;
            }
        }

        throw new AuthorizationException();
    }

    /**
     * 检查当前用户是否具有制定的能力
     * 跨控制器的转换 (完整命名空间):  $this->hasAction('App\Admin\Controllers\System\DepartmentController::index')
     * 跨控制器的转换 (同模块简化写法):  $this->hasAction('DepartmentController::index')
     */
    protected function hasAction($ability)
    {
        $ability = $this->getOtherControllerAction($ability);

        return app(Gate::class)->check('acl_authentication', $this->formatAclName($ability));
    }

    /**
     * 根据配置获取 action 的 acl name
     */
    protected function formatAclName(string $str, bool $plain = false): string
    {
        $str                  = str_replace('\\', '', Str::snake($str, '-'));
        [$namespace, $action] = explode('-controllers-', $str);
        $action               = str_replace('app-', '', $namespace) . '-' . $action;
        $action               = str_replace('-controller::', '-', $action);

        if (config('scaffold.authorization.md5') && ! $plain) {
            return substr(md5($action), 8, 16);
        }

        return $action;
    }

    /**
     * 获取当前动作对应的权限验证名称
     */
    protected function getAclMethodName(): string|array
    {
        $transform_methods = $this->getTransformMethods();
        if (! isset($transform_methods[$this->method])) {
            $method = static::class . '::' . $this->method;

            return $this->formatAclName($method);
        }

        if (is_string($transform_methods[$this->method])) {
            if (str_contains($transform_methods[$this->method], '::')) {
                $method = $this->getOtherControllerAction($transform_methods[$this->method]);
            } else {
                $method = static::class . '::' . $transform_methods[$this->method];
            }

            return $this->formatAclName($method);
        }

        // 设置了多个可转移动作
        return array_map(
            fn ($item) => $this->formatAclName($this->getOtherControllerAction($item)),
            $transform_methods[$this->method]
        );
    }

    /**
     * 获取另一个控制器的完整动作
     */
    private function getOtherControllerAction(string $action): string
    {
        if (Str::startsWith($action, 'App\\')) {
            return $action;
        }

        // 同模块简化写法 转换
        $class     = get_called_class();
        $namespace = substr($class, 0, strrpos($class, '\\'));

        return $namespace . '\\' . $action;
    }

    /**
     * 获取当前动作名称，默认情况下优先获取需要转换的名称（便于做权限验证）
     */
    protected function getMethod(bool $real = false): string
    {
        if ($real) {
            return $this->method;
        }

        return $this->getTransformMethods()[$this->method] ?? $this->method;
    }

    /**
     * 获取所有转换动作
     */
    protected function getTransformMethods(): array
    {
        return array_merge(['create' => 'store', 'edit' => 'update', 'restore' => 'trashed'], $this->transform_methods);
    }
}
