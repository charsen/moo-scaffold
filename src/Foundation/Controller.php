<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2026-06-02 09:23
 * @Description: Base Controller
 */

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
    // use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    protected string $method;

    /**
     * 设置需要转换的动作：把某 action 的鉴权转移到另一个 action 上，被转移的 action 不再作独立授权点
     * - 控制器内转换：             ['create' => 'index']
     * - 跨控制器·完整命名空间：     ['index' => 'App\Admin\Controllers\System\DepartmentController::index']
     * - 跨控制器·同模块简化写法：   ['index' => 'DepartmentController::index']
     * - 多权限点（任一命中即放行）：['store' => ['DepartmentController::index', 'PositionController::index']]
     *
     * 简化写法两条硬约束（getOtherControllerAction 解析所致）：
     * - 类名须含 Controller 后缀：'DepartmentController::index' ✓ / 'Department::index' ✗（拼出的类不存在）
     * - 完整命名空间须 App\ 根；vendor 等非 App\ 根不在支持范围
     */
    protected array $transform_methods = [];

    /**
     * Execute an action on the controller
     *
     * @param string $method
     * @param array  $parameters
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

        /**
         * 设置了多个可转移动作，示例：
         *  $this->transform_methods = [
         *    'destroyBatch' => ['LoginManagementController::index', 'AdminController::index'],
         *  ];
         */
        $role_actions = getUser()->getActions();
        foreach ($method as $item) {
            if (in_array('is_root', $role_actions, true) || in_array($item, $role_actions, true)) {
                return true;
            }
        }

        throw new AuthorizationException;
    }

    /**
     * 检查当前用户是否具有指定的能力（命令式：在 action 内分支判断，只消费已有授权点）
     * - 跨控制器·完整命名空间：   $this->hasAction('App\Admin\Controllers\System\DepartmentController::index')
     * - 跨控制器·同模块简化写法： $this->hasAction('DepartmentController::index')
     *
     * ⚠️ 必须写 'XxxController::action'：传裸 action 名（如 'update'）会丢掉当前 controller、
     *    拼成模块级幻影 key，非 root 恒为 false。
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
        $action = static::aclPlainKey($str);

        if (config('scaffold.authorization.md5') && ! $plain) {
            return substr(md5($action), 8, 16);
        }

        return $action;
    }

    /**
     * 把 controller target（FQCN::action）规整成 ACL 明文 key：<app>-<module>-<controller>-<action>。
     *
     * app / module 由 config('scaffold.controller') 的 path + extra_modules 反查决定，
     * 不依赖任何根命名空间字面（App\ / Mooeen\ ...）：
     *  - 命中某 app 的 path（如 App\Admin\Controllers）→ app 段取自 path 中间段（如 Admin），module 落在余段里
     *  - 命中某 app 的 extra_modules（vendor 包提供的模块）→ app 段同上，module 段 = extra_modules 的键名
     *
     * 生成器（fallback / route_plain_key 展示）也复用此静态方法，保证 gen↔runtime 同一套算法。
     */
    public static function aclPlainKey(string $target): string
    {
        [$class, $action] = array_pad(explode('::', $target, 2), 2, '');

        $segments   = self::resolveAclSegments(ltrim($class, '\\'));
        $segments[] = $action;

        $segments = array_filter($segments, static fn ($s) => $s !== '' && $s !== null);
        $segments = array_map(static fn ($s) => Str::snake((string) $s, '-'), $segments);

        return implode('-', $segments);
    }

    /**
     * 把 controller class 拆成原始 PascalCase 段序列 [app段..., module段..., 命名空间余段..., controller(去 Controller 后缀)]，
     * 由 aclPlainKey 统一 snake。未命中任何已配置 app 时退化成整条命名空间（确定性 + gen↔runtime 仍一致）。
     */
    private static function resolveAclSegments(string $class): array
    {
        foreach ((array) config('scaffold.controller', []) as $cfg) {
            if (! is_array($cfg) || ! isset($cfg['path'])) {
                continue;
            }

            $base = ucfirst(str_replace('/', '\\', trim((string) $cfg['path'], '/')));
            if ($base === '') {
                continue;
            }
            $appSegments = self::aclAppSegments($base);

            if (str_starts_with($class, $base . '\\')) {
                return array_merge($appSegments, self::aclControllerSegments(substr($class, strlen($base) + 1)));
            }

            foreach ((array) ($cfg['extra_modules'] ?? []) as $moduleName => $vendorNamespace) {
                $vendorNamespace = trim((string) $vendorNamespace, '\\');
                if ($vendorNamespace !== '' && str_starts_with($class, $vendorNamespace . '\\')) {
                    return array_merge(
                        $appSegments,
                        [(string) $moduleName],
                        self::aclControllerSegments(substr($class, strlen($vendorNamespace) + 1))
                    );
                }
            }
        }

        return self::aclControllerSegments($class);
    }

    /**
     * path 基命名空间（App\Admin\Controllers）→ app 段（[Admin]）：去掉根段(App) + 尾段(Controllers)。
     */
    private static function aclAppSegments(string $base): array
    {
        $parts = explode('\\', $base);
        array_shift($parts);
        if (! empty($parts) && end($parts) === 'Controllers') {
            array_pop($parts);
        }

        return $parts;
    }

    /**
     * 命名空间余段（System\DepartmentController）→ [System, Department]：末段去 Controller 后缀。
     */
    private static function aclControllerSegments(string $remainder): array
    {
        $parts      = explode('\\', $remainder);
        $controller = (string) array_pop($parts);
        $parts[]    = preg_replace('/Controller$/', '', $controller);

        return $parts;
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

        // 设置了多个可转移动作。每个 item 与上面字符串分支同口径：
        //   有 :: → 跨控制器（getOtherControllerAction）；无 :: → 当前控制器的方法（static::class.'::'.item）。
        return array_map(
            fn ($item) => $this->formatAclName(
                str_contains($item, '::') ? $this->getOtherControllerAction($item) : static::class . '::' . $item
            ),
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
