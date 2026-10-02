<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Mooeen\Scaffold\Http\Middleware\ScaffoldAuthenticate;

beforeEach(function () {
    app()->instance('env', 'local');
    config(['scaffold.config_ui.readonly' => false, 'logging.default' => 'null']);
    $this->withoutMiddleware([ScaffoldAuthenticate::class, VerifyCsrfToken::class]);
    $this->withSession(['_token' => 'throttle-isolation-token']);
    $this->withHeader('X-CSRF-TOKEN', 'throttle-isolation-token');
    cache()->clear();
});

it('CSP reports do not consume login attempts from the same IP', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/scaffold/csp-report', ['csp-report' => []])->assertNoContent();
    }

    // Invalid input stops before account lookup or credential checks.
    $this->postJson('/scaffold/login', ['username' => []])
        ->assertUnprocessable()
        ->assertHeader('X-RateLimit-Remaining', '4');
});

it('login attempts do not consume the CSP reporting quota', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/scaffold/login', ['username' => []])->assertUnprocessable();
    }

    $this->postJson('/scaffold/login', ['username' => []])->assertTooManyRequests();
    $this->postJson('/scaffold/csp-report', ['csp-report' => []])
        ->assertNoContent()
        ->assertHeader('X-RateLimit-Remaining', '59');
});

it('Markdown previews do not consume the save quota', function () {
    for ($i = 0; $i < 30; $i++) {
        $this->postJson('/scaffold/plans/preview', [])->assertUnprocessable();
    }

    // Neither action reaches the editor, so no Host file is read or written.
    $this->postJson('/scaffold/plans/save', [])
        ->assertUnprocessable()
        ->assertHeader('X-RateLimit-Remaining', '29');
});

it('login keeps its five-attempt limit, response headers and one-minute recovery', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/scaffold/login', ['username' => []])->assertUnprocessable();
    }

    $response = $this->postJson('/scaffold/login', ['username' => []]);
    $response->assertTooManyRequests()
        ->assertExactJson(['message' => 'Too Many Attempts.'])
        ->assertHeader('X-RateLimit-Limit', '5')
        ->assertHeader('X-RateLimit-Remaining', '0');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);

    $this->travel(61)->seconds();
    $this->postJson('/scaffold/login', ['username' => []])
        ->assertUnprocessable()
        ->assertHeader('X-RateLimit-Remaining', '4');
});

it('different IPs retain independent login quotas', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10']);
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/scaffold/login', ['username' => []])->assertUnprocessable();
    }
    $this->postJson('/scaffold/login', ['username' => []])->assertTooManyRequests();

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.20']);
    $this->postJson('/scaffold/login', ['username' => []])
        ->assertUnprocessable()
        ->assertHeader('X-RateLimit-Remaining', '4');
});

it('all Scaffold throttles have isolated buckets, with account writes intentionally grouped', function () {
    $buckets = [];
    foreach (app('router')->getRoutes() as $route) {
        if (! str_starts_with($route->getActionName(), 'Mooeen\\Scaffold\\Http\\Controllers\\')) {
            continue;
        }
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! str_starts_with($middleware, 'throttle:')) {
                continue;
            }
            $parameters = explode(',', substr($middleware, strlen('throttle:')));
            $scope      = $parameters[2] ?? '';
            expect($scope)->toStartWith('scaffold:');
            $buckets[$scope][] = $route->getName();
        }
    }

    expect($buckets)->not->toBeEmpty();
    $shared = array_values(array_filter($buckets, fn (array $routes) => count($routes) > 1));
    expect($shared)->toBe([[
        'scaffold.accounts.store',
        'scaffold.accounts.update',
        'scaffold.accounts.toggle',
        'scaffold.accounts.delete',
    ]]);
});
