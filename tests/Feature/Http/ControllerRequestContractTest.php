<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest as LaravelFormRequest;

it('scaffold controller actions use FormRequest instead of the generic HTTP request', function () {
    $actions = [];

    foreach (app('router')->getRoutes() as $route) {
        $action = ltrim($route->getActionName(), '\\');
        if (! str_starts_with($action, 'Mooeen\\Scaffold\\Http\\Controllers\\') || ! str_contains($action, '@')) {
            continue;
        }

        [$controller, $method]                = explode('@', $action, 2);
        $actions[$controller . '@' . $method] = [$controller, $method];
    }

    expect($actions)->not->toBeEmpty();

    foreach ($actions as [$controller, $method]) {
        $reflection = new ReflectionMethod($controller, $method);
        $parameters = $reflection->getParameters();

        if ($parameters === []) {
            continue;
        }

        $hasFormRequest = collect($parameters)->contains(function (ReflectionParameter $parameter): bool {
            $type = $parameter->getType();

            return $type instanceof ReflectionNamedType
                && ! $type->isBuiltin()
                && is_a($type->getName(), LaravelFormRequest::class, true);
        });

        $serviceOnly = collect($parameters)->every(function (ReflectionParameter $parameter): bool {
            $type = $parameter->getType();

            return $type instanceof ReflectionNamedType
                && ! $type->isBuiltin()
                && ! is_a($type->getName(), \Illuminate\Http\Request::class, true);
        });

        $isIdOnly = count($parameters) === 1 && $parameters[0]->getName() === 'id';

        expect($hasFormRequest || $isIdOnly || $serviceOnly)
            ->toBeTrue("{$controller}@{$method} 必须使用 FormRequest；仅单一 \$id action 可例外。");
    }
});

it('scaffold controllers consume validated input instead of reading raw user fields', function () {
    $forbidden = '/\$(?:req|request)->(?:input|query|file|header|bearerToken|cookie|boolean|integer|string|all|only|validate)\s*\(|\brequest\s*\(\s*\)/';

    foreach (glob(__DIR__ . '/../../../src/Http/Controllers/*.php') ?: [] as $file) {
        if (basename($file) === 'Controller.php') {
            continue;
        }

        $source = (string) file_get_contents($file);
        expect(preg_match($forbidden, $source))
            ->toBe(0, basename($file) . ' 仍在 Controller 中直接读取或校验用户输入。');
    }
});
