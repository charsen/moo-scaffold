<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

class AclActionResolver
{
    /**
     * Resolve the ACL key that a controller action will check at runtime.
     */
    public function resolve(string $controllerClass, string $actionName): array
    {
        if (! class_exists($controllerClass)) {
            return $this->emptyResult();
        }

        try {
            $controller = $this->makeController($controllerClass);
            $this->bootWithoutAuthorization($controller, $actionName);

            $targets    = $this->resolveTargetActions($controller, $controllerClass, $actionName);
            $keys       = [];
            $plainKeys  = [];
            $targetKeys = [];

            foreach ($targets as $target) {
                $keys[]      = $targetKeys[$target] = $this->formatAclName($target, false);
                $plainKeys[] = $this->formatAclName($target, true);
            }

            $keys      = array_values(array_filter(array_unique($keys)));
            $plainKeys = array_values(array_filter(array_unique($plainKeys)));

            return [
                'keys'       => $keys,
                'plain_keys' => $plainKeys,
                'key'        => implode(' | ', $keys),
                'plain_key'  => implode(' | ', $plainKeys),
                'targets'    => $targets,
                // keys 会独立去重，调用方不可再用 keys 的下标配对 targets。
                'target_keys'            => $targetKeys,
                'target'                 => implode(' | ', $targets),
                'transformed'            => $targets !== [$controllerClass . '::' . $actionName],
                'uses_default_transform' => $this->usesDefaultTransform($controller, $actionName),
            ];
        } catch (Throwable $e) {
            Log::warning('ACL action resolution failed', [
                'controller' => $controllerClass,
                'action'     => $actionName,
                'exception'  => $e::class,
            ]);

            return [...$this->emptyResult(), 'error' => $e::class];
        }
    }

    public function targetMethodInfo(string $target): ?array
    {
        [$class, $method] = $this->splitTarget($target);

        if ($class === '' || $method === '' || ! class_exists($class)) {
            return null;
        }

        $reflectionClass = new ReflectionClass($class);
        if (! $reflectionClass->hasMethod($method)) {
            return null;
        }

        return [
            'class'      => $class,
            'method'     => $method,
            'reflection' => $reflectionClass->getMethod($method),
        ];
    }

    private function makeController(string $controllerClass): object
    {
        try {
            if (function_exists('app')) {
                return app()->make($controllerClass);
            }
        } catch (Throwable) {
            //
        }

        return (new ReflectionClass($controllerClass))->newInstanceWithoutConstructor();
    }

    private function bootWithoutAuthorization(object $controller, string $actionName = ''): void
    {
        if (! method_exists($controller, 'boot')) {
            return;
        }

        // 先把基类 `$method` 设成本次解析的动作名，再调 boot()。
        //
        // 2026-09-21 修「resolver 静默失败 → 产物对授权事实撒谎」：`Foundation\Controller::$method`
        // 是未初始化 typed property，只在运行期 `callAction()` 里赋值；生成期直接 boot() 会让任何在
        // boot() 里读 `$this->method` 的控制器抛 `must not be accessed before initialization`，
        // 而本类的 `catch (Throwable)` 会把整个动作吞成「回退 key」。实测 `PersonnelOptionController`
        // 因此三个动作全部落成「无标签白名单」，其真实授权（Gate 校验 ProcessDefinitionController 的
        // update / publish / simulate）完全没有进入产物。
        if ($actionName !== '') {
            try {
                $property = new \ReflectionProperty(\Mooeen\Scaffold\Foundation\Controller::class, 'method');
                $property->setAccessible(true);
                $property->setValue($controller, $actionName);
            } catch (Throwable) {
                // 非 scaffold 基类控制器（无 $method）无需设置。
            }
        }

        $original = Config::get('scaffold.authorization.check');
        Config::set('scaffold.authorization.check', false);

        try {
            $controller->boot();
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            // 控制器 boot() 里直接调 Gate 做**领域级**授权（如 PersonnelOptionController 校验
            // ProcessDefinitionController 的 update / publish / simulate）时，生成期没有登录用户，
            // 判定必然失败并被抛到这里。这类异常**不代表解析失败** —— transform_methods 的赋值在
            // boot() 开头就已完成，授权判定只是它后面的守卫，故忽略并继续扫描。
            // 只吞 AuthorizationException：其它异常仍向上抛给 resolve() 的 catch，标记解析失败。
        } finally {
            Config::set('scaffold.authorization.check', $original);
        }
    }

    private function resolveTargetActions(object $controller, string $controllerClass, string $actionName): array
    {
        $transformMethods = $this->getTransformMethods($controller);
        if (! isset($transformMethods[$actionName])) {
            return [$controllerClass . '::' . $actionName];
        }

        $mappedAction = $transformMethods[$actionName];
        if (is_string($mappedAction)) {
            return [$this->resolveMappedAction($controller, $controllerClass, $mappedAction)];
        }

        if (! is_array($mappedAction)) {
            return [$controllerClass . '::' . $actionName];
        }

        $targets = [];
        foreach ($mappedAction as $item) {
            if (is_string($item)) {
                $targets[] = $this->resolveMappedAction($controller, $controllerClass, $item);
            }
        }

        return $targets === [] ? [$controllerClass . '::' . $actionName] : array_values(array_unique($targets));
    }

    private function resolveMappedAction(object $controller, string $controllerClass, string $mappedAction): string
    {
        if (str_contains($mappedAction, '::')) {
            return $this->getOtherControllerAction($controller, $mappedAction);
        }

        return $controllerClass . '::' . $mappedAction;
    }

    private function usesDefaultTransform(object $controller, string $action): bool
    {
        if (! $controller instanceof \Mooeen\Scaffold\Foundation\Controller) {
            return false;
        }
        $method = new ReflectionMethod($controller, 'getTransformMethods');
        $custom = new \ReflectionProperty(\Mooeen\Scaffold\Foundation\Controller::class, 'transform_methods');

        return $method->getDeclaringClass()->getName() === \Mooeen\Scaffold\Foundation\Controller::class
            && ! array_key_exists($action, $custom->getValue($controller));
    }

    private function getTransformMethods(object $controller): array
    {
        if (! method_exists($controller, 'getTransformMethods')) {
            return ['create' => 'store', 'edit' => 'update', 'restore' => 'trashed'];
        }

        $method = new ReflectionMethod($controller, 'getTransformMethods');
        $method->setAccessible(true);
        $result = $method->invoke($controller);

        return is_array($result) ? $result : [];
    }

    private function getOtherControllerAction(object $controller, string $mappedAction): string
    {
        if (! method_exists($controller, 'getOtherControllerAction')) {
            return $mappedAction;
        }

        $method = new ReflectionMethod($controller, 'getOtherControllerAction');
        $method->setAccessible(true);

        return (string) $method->invoke($controller, $mappedAction);
    }

    /**
     * 由**目标自身**的 FQCN 计算 ACL key。
     *
     * 2026-09-21 修「跨控制器 transform 的 key 算错」：原实现在**起源控制器实例**上调用
     * `formatAclName($target)`，于是目标动作被按起源的命名空间解析 —— 实测
     * `PersonnelOptionController -> ProcessDefinitionController::update` 产出的明文是
     * `admin-process-mooeen-process-http-controllers-admin-process-definition-update`
     * （拼进了起源的命名空间段），与目标自己运行期校验的 `admin-process-process-definition-update`
     * 不一致，勾了也不生效。
     *
     * ACL key 只应由**目标 FQCN** 决定（`Controller::aclPlainKey` 是 gen↔runtime 的单一算法），
     * 故改为静态解析；跨控制器本就是这个框架支持的形态（`transform_methods` 的 `X::y` 写法）。
     */
    private function formatAclName(string $target, bool $plain): string
    {
        $action = \Mooeen\Scaffold\Foundation\Controller::aclPlainKey($target);
        if ($action === '') {
            return '';
        }

        if ($plain || ! Config::get('scaffold.authorization.md5')) {
            return $action;
        }

        return substr(md5($action), 8, 16);
    }

    private function splitTarget(string $target): array
    {
        if (! str_contains($target, '::')) {
            return ['', ''];
        }

        return explode('::', $target, 2);
    }

    private function emptyResult(): array
    {
        return [
            'keys'        => [],
            'plain_keys'  => [],
            'key'         => '',
            'plain_key'   => '',
            'targets'     => [],
            'target_keys' => [],
            'target'      => '',
            'transformed' => false,
        ];
    }
}
