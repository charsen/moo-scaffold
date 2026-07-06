<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use Illuminate\Support\Facades\Config;
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
            $this->bootWithoutAuthorization($controller);

            $targets   = $this->resolveTargetActions($controller, $controllerClass, $actionName);
            $keys      = [];
            $plainKeys = [];

            foreach ($targets as $target) {
                $keys[]      = $this->formatAclName($controller, $target, false);
                $plainKeys[] = $this->formatAclName($controller, $target, true);
            }

            $keys      = array_values(array_filter(array_unique($keys)));
            $plainKeys = array_values(array_filter(array_unique($plainKeys)));

            return [
                'keys'        => $keys,
                'plain_keys'  => $plainKeys,
                'key'         => implode(' | ', $keys),
                'plain_key'   => implode(' | ', $plainKeys),
                'targets'     => $targets,
                'target'      => implode(' | ', $targets),
                'transformed' => $targets !== [$controllerClass . '::' . $actionName],
            ];
        } catch (Throwable) {
            return $this->emptyResult();
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

    private function bootWithoutAuthorization(object $controller): void
    {
        if (! method_exists($controller, 'boot')) {
            return;
        }

        $original = Config::get('scaffold.authorization.check');
        Config::set('scaffold.authorization.check', false);

        try {
            $controller->boot();
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

    private function formatAclName(object $controller, string $target, bool $plain): string
    {
        if (! method_exists($controller, 'formatAclName')) {
            return '';
        }

        $method = new ReflectionMethod($controller, 'formatAclName');
        $method->setAccessible(true);

        return (string) $method->invoke($controller, $target, $plain);
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
            'target'      => '',
            'transformed' => false,
        ];
    }
}
