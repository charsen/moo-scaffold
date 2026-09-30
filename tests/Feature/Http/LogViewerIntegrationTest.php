<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Tests\Feature\Http;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Mooeen\Scaffold\Auth\ScaffoldAuth;
use Mooeen\Scaffold\Support\AccountStore;
use Mooeen\Scaffold\Tests\TestCase;
use Opcodes\LogViewer\LogViewerServiceProvider;

final class LogViewerIntegrationTest extends TestCase
{
    private string $sandbox;

    private array $settings = [];

    private bool $logViewerFirst = false;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/scaffold-log-viewer-' . uniqid();
        mkdir($this->sandbox, 0755, true);
        file_put_contents($this->sandbox . '/fixture.log', "[2026-09-29 10:00:00] testing.ERROR: Scaffold log fixture\n");
        parent::setUp();
        app(AccountStore::class)->create(['username' => 'reader', 'password' => 'test-password', 'role' => 'member'], 'test');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sandbox);
        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        $providers = parent::getPackageProviders($app);
        if ($this->logViewerFirst) {
            array_unshift($providers, LogViewerServiceProvider::class);
        } else {
            $providers[] = LogViewerServiceProvider::class;
        }

        return $providers;
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set([
            'scaffold.accounts.yaml_path' => $this->sandbox . '/accounts.yaml',
            'log-viewer.route_path'       => 'mooeen-log-viewer',
            'log-viewer.include_files'    => [$this->sandbox . '/*.log'],
            'log-viewer.exclude_files'    => [],
            'log-viewer.timezone'         => 'Asia/Shanghai',
            'log-viewer.cache_driver'     => 'array',
            ...$this->settings,
        ]);
        // AccountStore 默认以 base_path 拼接配置路径；fixture 只覆盖落点，
        // 认证、账号读写与只读守卫全部沿用生产实现。
        $path = $this->sandbox . '/accounts.yaml';
        $app->instance(AccountStore::class, new class($app['config'], new Filesystem, $path) extends AccountStore
        {
            public function __construct($config, $files, private string $fixturePath)
            {
                parent::__construct($config, $files);
            }

            public function path(): string
            {
                return $this->fixturePath;
            }
        });
        self::assertSame($path, $app->make(AccountStore::class)->path());
    }

    private function loginReader(): void
    {
        $cookie = app(ScaffoldAuth::class)->makeCookie('reader', Request::create('/scaffold'));
        if (in_array('web', config('scaffold.route.middleware', []), true)) {
            $this->withCookie($cookie->getName(), $cookie->getValue());
        } else {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }
        $this->withCredentials();
        self::assertNotNull(app(ScaffoldAuth::class)->authenticateRequest(Request::create('/scaffold', 'GET', [], [$cookie->getName() => $cookie->getValue()])));
    }

    public function test_anonymous_page_redirects_and_api_requires_auth_even_without_accept(): void
    {
        $this->get('/scaffold/logs')->assertRedirectContains('/scaffold/login');
        $this->get('/scaffold/logs/api/files')->assertUnauthorized()->assertHeader('X-Scaffold-Auth', 'required');
        $this->get('/scaffold/logs/api/files/fixture/download?signature=invalid')->assertUnauthorized();
        $this->get('/mooeen-log-viewer')->assertNotFound();
        $this->get('/mooeen-log-viewer/api/files')->assertNotFound();
        // production 使 Testbench 真正执行 CSRF 校验，验证认证位于其前。
        $this->app['env'] = 'production';
        $this->post('/scaffold/logs/api/clear-cache-all')->assertUnauthorized();
        $this->delete('/scaffold/logs/api/files/fixture')->assertUnauthorized();
    }

    public function test_member_reads_native_ui_and_api_with_scaffold_nonce_and_delete_controls_disabled(): void
    {
        $this->loginReader();
        $response = $this->get('/scaffold/logs')->assertOk()->assertSee('Scaffold')->assertSee('router-view', false);
        preg_match('/nonce="([^"]+)"/', $response->getContent(), $matches);
        $response->assertHeader('Content-Security-Policy');
        self::assertStringContainsString("'nonce-{$matches[1]}'", $response->headers->get('Content-Security-Policy'));
        self::assertSame(3, substr_count($response->getContent(), 'nonce="' . $matches[1] . '"'));
        self::assertStringNotContainsString('unsafe-eval', $response->headers->get('Content-Security-Policy'));

        $files = $this->getJson('/scaffold/logs/api/files')->assertOk()->assertJsonPath('0.can_delete', false);
        self::assertArrayNotHasKey('ok', $files->json());
        $identifier = $files->json('0.identifier');
        $this->getJson('/scaffold/logs/api/logs?file=' . $identifier)->assertOk();
        $download = $this->getJson('/scaffold/logs/api/files/' . $identifier . '/download/request')->assertOk()->json('url');
        $this->get($download)->assertOk()->assertDownload('fixture.log');
        $this->defaultCookies     = [];
        $this->unencryptedCookies = [];
        $this->get($download)->assertUnauthorized();
        self::assertFalse(Gate::check('deleteLogFile', new \stdClass));
        self::assertFalse(Gate::check('deleteLogFolder', new \stdClass));
        self::assertSame('Asia/Shanghai', config('log-viewer.timezone'));
        self::assertSame([$this->sandbox . '/*.log'], config('log-viewer.include_files'));
    }

    public function test_disabled_and_expired_accounts_cannot_reuse_cookie(): void
    {
        $this->loginReader();
        app(AccountStore::class)->update('reader', ['enabled' => false], 'test');
        $this->get('/scaffold/logs')->assertRedirectContains('/scaffold/login');
        $this->get('/scaffold/logs/api/files')->assertUnauthorized();

        app(AccountStore::class)->update('reader', ['enabled' => true], 'test');
        $auth      = app(ScaffoldAuth::class);
        $old       = time() - $auth->getTtlMinutes() * 60 - 100;
        $signature = (new \ReflectionMethod($auth, 'sign'))->invoke($auth, 'reader', $old);
        $this->withCookie($auth->getCookieName(), Crypt::encryptString(json_encode([
            'username' => 'reader', 'last_active' => $old, 'signature' => $signature,
        ])));
        $this->get('/scaffold/logs/api/files')->assertUnauthorized();
    }

    public function test_page_bridge_uses_current_prefix_and_runtime_readonly_state(): void
    {
        $this->settings = ['scaffold.route.prefix' => 'tools'];
        $this->refreshApplication();
        $this->loginReader();
        foreach (['testing' => false, 'production' => true] as $environment => $readonly) {
            $this->app['env'] = $environment;
            $response         = $this->get('/tools/logs')->assertOk();
            preg_match('/window\.ScaffoldLogViewer = (.*?);/s', $response->getContent(), $matches);
            self::assertSame([
                'apiPath' => '/tools/logs/api', 'loginPath' => '/tools/login', 'readonly' => $readonly,
            ], json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR));
            self::assertSame($readonly, str_contains($response->getContent(), 'id="scaffold-log-viewer-readonly"'));
            $response->assertSee('id="scaffold-log-viewer-auth"', false)->assertSee('登录已失效，请重新登录');
            self::assertStringContainsString(\Mooeen\Scaffold\Support\LogViewerIntegration::script(), $response->getContent());
        }
        $this->app['env'] = 'testing';
        config(['scaffold.config_ui.readonly' => true]);
        $this->get('/tools/logs')->assertOk()->assertSee('id="scaffold-log-viewer-readonly"', false);
    }

    public function test_inline_assets_ignore_stale_published_manifest(): void
    {
        $this->app->usePublicPath($this->sandbox . '/public');
        $directory = public_path(trim(config('log-viewer.assets_path'), '/'));
        mkdir($directory, 0755, true);
        file_put_contents($directory . '/mix-manifest.json', '{"/app.js":"/app.js?id=old"}');
        self::assertTrue(\Opcodes\LogViewer\Facades\LogViewer::assetsArePublished());
        self::assertFalse(\Opcodes\LogViewer\Facades\LogViewer::assetsAreCurrent());
        $this->loginReader();
        $response = $this->get('/scaffold/logs')->assertOk();
        preg_match('/window\.LogViewer = (.*?);/s', $response->getContent(), $matches);
        $bootstrap = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($bootstrap['assets_outdated']);
        self::assertSame('scaffold/logs', $bootstrap['path']);
    }

    public function test_authentication_runs_once_per_request_and_disabled_accounts_are_rechecked(): void
    {
        $this->loginReader();
        $auth = \Mockery::mock(ScaffoldAuth::class)->makePartial();
        $auth->shouldReceive('authenticateRequest')->twice()->passthru();
        app()->instance(ScaffoldAuth::class, $auth);

        $this->getJson('/scaffold/logs/api/files')->assertOk();
        $auth->shouldHaveReceived('authenticateRequest')->once();
        app(AccountStore::class)->update('reader', ['enabled' => false], 'test');
        $this->get('/scaffold/logs/api/files')->assertUnauthorized()->assertHeader('X-Scaffold-Auth', 'required');
        $auth->shouldHaveReceived('authenticateRequest')->twice();
    }

    public function test_readonly_menu_labels_match_installed_upstream_components(): void
    {
        $base   = LogViewerServiceProvider::basePath('/resources/js/components/');
        $script = \Mooeen\Scaffold\Support\LogViewerIntegration::script();
        foreach ([
            'FileListItem.vue'         => 'Clear index',
            'FileList.vue'             => 'Clear indices',
            'SiteSettingsDropdown.vue' => 'Clear indices for all files',
        ] as $file => $label) {
            self::assertStringContainsString('>' . $label . '</span>', file_get_contents($base . $file));
            self::assertStringContainsString("'" . $label . "'", $script);
        }
    }

    public function test_all_native_deletion_endpoints_reject_and_preserve_file(): void
    {
        $this->loginReader();
        $this->deleteJson('/scaffold/logs/api/files/fixture')->assertForbidden();
        $this->deleteJson('/scaffold/logs/api/folders/fixture')->assertForbidden();
        $this->postJson('/scaffold/logs/api/delete-multiple-files', ['files' => ['fixture']])->assertForbidden();
        self::assertFileExists($this->sandbox . '/fixture.log');
    }

    public function test_production_and_forced_readonly_reject_explicit_cache_writes(): void
    {
        $this->loginReader();
        $this->postJson('/scaffold/logs/api/clear-cache-all')->assertOk();
        $this->app['env'] = 'production';
        $this->postJson('/scaffold/logs/api/clear-cache-all')->assertForbidden();
        $this->withSession(['_token' => 'log-viewer-test-token'])->withHeader('X-CSRF-TOKEN', 'log-viewer-test-token');
        $this->getJson('/scaffold/logs/api/files')->assertOk();
        $this->postJson('/scaffold/logs/api/clear-cache-all')->assertForbidden();
        $this->postJson('/scaffold/logs/api/files/fixture/clear-cache')->assertForbidden();
        $this->postJson('/scaffold/logs/api/folders/fixture/clear-cache')->assertForbidden();
        $this->app['env'] = 'testing';
        config(['scaffold.config_ui.readonly' => true]);
        $this->postJson('/scaffold/logs/api/clear-cache-all')->assertForbidden();

        // 允许维护的本地环境仍保留 CSRF 校验。
        $this->app['env'] = 'local';
        config(['scaffold.config_ui.readonly' => false]);
        $this->defaultHeaders = [];
        $this->postJson('/scaffold/logs/api/clear-cache-all')->assertStatus(419);
    }

    public function test_existing_scaffold_login_cookie_also_authenticates_log_viewer(): void
    {
        $returnTo = '/scaffold/logs?file=fixture&query=error#details';
        $login    = $this->post('/scaffold/login', ['username' => 'reader', 'password' => 'test-password', 'redirect' => $returnTo])
            ->assertRedirect($returnTo);
        $cookie = $login->getCookie(app(ScaffoldAuth::class)->getCookieName());
        self::assertNotNull($cookie);
        $this->withCookie($cookie->getName(), $cookie->getValue())->withCredentials();
        $this->get('/scaffold/logs')->assertOk();
        $this->getJson('/scaffold/logs/api/files')->assertOk();
    }

    public function test_scaffold_without_web_cookie_encryption_uses_the_same_login_cookie(): void
    {
        $this->settings = ['scaffold.route.middleware' => []];
        $this->refreshApplication();
        $this->loginReader();
        $this->get('/scaffold/logs')->assertOk();
        $this->getJson('/scaffold/logs/api/files')->assertOk();
    }

    public function test_compiled_routes_preserve_authentication_and_native_endpoint(): void
    {
        app('router')->setCompiledRoutes(app('router')->getRoutes()->compile());
        $this->get('/scaffold/logs')->assertRedirectContains('/scaffold/login');
        $this->get('/scaffold/logs/api/files')->assertUnauthorized();
        $this->loginReader();
        $this->getJson('/scaffold/logs/api/files')->assertOk();
        $this->get('/mooeen-log-viewer')->assertNotFound();
    }

    public function test_prefix_and_both_provider_orders_keep_log_routes_ahead_of_fallback(): void
    {
        foreach ([false, true] as $first) {
            $this->logViewerFirst = $first;
            $this->settings       = ['scaffold.route.prefix' => 'tools'];
            $this->refreshApplication();
            $this->get('/tools/logs')->assertRedirectContains('/tools/login');
            $this->get('/tools/logs/api/files')->assertUnauthorized();
            foreach (['/tools' => 'scaffold.home', '/tools/login' => 'scaffold.login', '/tools/db/docs' => 'db.docs'] as $uri => $name) {
                self::assertSame($name, app('router')->getRoutes()->match(Request::create($uri))->getName());
            }
            self::assertSame(1, count(array_filter($this->app->getProviders(LogViewerServiceProvider::class))));
        }
    }

    public function test_disabled_routes_log_viewer_or_scaffold_auth_fail_closed_and_hide_navigation(): void
    {
        foreach (['log-viewer.enabled', 'scaffold.route.enabled', 'scaffold.auth.enabled'] as $key) {
            $this->settings = [$key => false];
            $this->refreshApplication();
            $this->get('/scaffold/logs')->assertNotFound();
            $this->get('/scaffold/logs/api/files')->assertNotFound();
            self::assertFalse(\Mooeen\Scaffold\Support\LogViewerIntegration::enabled());
        }
    }
}
