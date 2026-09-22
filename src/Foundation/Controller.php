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
        // 2026-09-21：与单目标分支统一走 Gate。
        //
        // 原实现直接比对 `getUser()->getActions()`，有两个问题：
        // ① `getUser()` 是**宿主全局**，包内并不定义 —— 于是「继承了 ACL 目标」的动作在包级测试
        //    环境直接 `Call to undefined function`，任何包都无法为继承授权写测试；
        // ② 它绕开 Gate，与单目标分支口径分歧：不查白名单（`acl_authentication` 里先判 isRoot、
        //    再判 whitelist、最后比角色动作），新增判定也对多目标动作失效。
        // `Gate::any($abilities, $argument)` 的语义就是「任一命中即可」，与上面的循环等价且同源。
        if (! app(Gate::class)->any($method, 'acl_authentication')) {
            throw new AuthorizationException;
        }

        return true;
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
     * 「任一能力命中即可」的布尔判定（命令式，多个授权点之间是 OR）。
     *
     * 与 `checkAuthorization()` 的分工：后者是**终止型**守卫（不通过就抛 `AuthorizationException`
     * 结束请求），用于「本动作的授权口径固定」；本方法是**询问型**（返回 bool 不抛），用于
     * 「授权口径需在运行时才能确定」的场景 —— 例如目标来自配置、或要先读请求数据判断。
     *
     * 2026-09-21 新增。此前这类场景只能写成 `try { $this->checkAuthorization(); } catch
     * (AuthorizationException) { foreach (...) Gate::check(...) }` —— 用异常做布尔判断，且
     * `checkAuthorization()` 的多目标分支当时还不走 Gate（白名单/isRoot 口径分歧）。现统一：
     * 调用方先自行 `boot()` 登记 transform，再用本方法问「有没有」。
     *
     * @param list<string> $abilities 目标动作，完整命名空间或同模块简化写法（同 `hasAction`）
     */
    protected function hasAnyAction(array $abilities): bool
    {
        $keys = [];
        foreach ($abilities as $ability) {
            $key = $this->formatAclName($this->getOtherControllerAction($ability));
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return $keys !== [] && app(Gate::class)->any($keys, 'acl_authentication');
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
        // 完整类名直接放行（2026-09-21 修）：原实现只认 `App\` 根，其余一律按「同模块简化写法」
        // 前缀**当前**命名空间，于是引用其它命名空间的控制器（典型是宿主引用 `Mooeen\*` 包控制器，
        // 或任何 `Vendor\Pkg\...`）会被拼成
        // `App\Admin\Controllers\X\Mooeen\...` 这种幻影类名 —— 生成期与运行期都落在一个
        // 谁都拿不到的 key 上。判据用 `\\` 是否存在：含命名空间分隔符即已完整。
        if (str_contains($action, '\\')) {
            return $action;
        }

        // 同模块简化写法（仅方法名，如 'save'）→ 补当前控制器命名空间
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
