<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Controllers;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Mooeen\Scaffold\Support\AccountStore;
use Mooeen\Scaffold\Support\AccountWriteForbiddenException;
use Mooeen\Scaffold\Support\ConfigManager;
use Mooeen\Scaffold\Utility;

/**
 * 开发人员账号管理（plan 18 §3.2.5）
 *
 * 路径 /scaffold/accounts/*。所有写操作：
 *   - production / readonly 时返回 403
 *   - 不能删除自己
 *   - 不能删除最后一个 admin（store 内兜底）
 *
 * 故意精简：无 import / token / 文件级备份（理由见 AccountStore），历史回溯走 git。
 */
class AccountController extends Controller
{
    public function __construct(
        Utility $utility,
        Filesystem $filesystem,
        private readonly AccountStore $store,
        private readonly ConfigManager $configManager,
    ) {
        parent::__construct($utility, $filesystem);
    }

    public function index(Request $request)
    {
        $accounts = $this->store->exists() ? $this->store->all() : [];
        $meta     = $this->store->exists() ? $this->store->meta() : [];

        return $this->view('accounts.index', [
            'uri'           => $request->getPathInfo(),
            'accounts'      => $accounts,
            'meta'          => $meta,
            'readonly'      => $this->isReadonly(),
            'is_prod'       => app()->environment('production'),
            'me'            => $this->currentUser($request),
            'flash_message' => $request->session()->pull('flash_message'),
            'flash_error'   => $request->session()->pull('flash_error'),
            'yaml_path'     => $this->store->path(),
            // 配置中心 sidebar 用：展示同样的导航树
            'all_groups' => $this->configManager->groups(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $this->assertCanWrite();
            $payload = $this->extractPayload($request);
            $row     = $this->store->create($payload, $this->currentUser($request));
            $request->session()->flash('flash_message', "新增账号 [{$row['username']}] 成功");
        } catch (AccountWriteForbiddenException $e) {
            $request->session()->flash('flash_error', $e->getMessage());
        } catch (\Throwable $e) {
            $request->session()->flash('flash_error', $e->getMessage());
        }

        return redirect()->route('scaffold.accounts');
    }

    public function update(Request $request, string $username): RedirectResponse
    {
        try {
            $this->assertCanWrite();
            $payload = $this->extractPayload($request, includeUsername: false);
            $this->store->update($username, $payload, $this->currentUser($request));
            $request->session()->flash('flash_message', "更新 [{$username}] 成功");
        } catch (AccountWriteForbiddenException $e) {
            $request->session()->flash('flash_error', $e->getMessage());
        } catch (\Throwable $e) {
            $request->session()->flash('flash_error', $e->getMessage());
        }

        return redirect()->route('scaffold.accounts');
    }

    public function toggle(Request $request, string $username): RedirectResponse
    {
        try {
            $this->assertCanWrite();
            $me = $this->currentUser($request);
            if ($username === $me) {
                throw new \RuntimeException('不能停用自己');
            }
            $current = $this->store->find($username);
            if ($current === null) {
                throw new \RuntimeException("账号 [{$username}] 不存在");
            }
            $this->store->toggleEnabled($username, ! ($current['enabled'] ?? true), $me);
            $request->session()->flash('flash_message', "已切换 [{$username}] 状态");
        } catch (AccountWriteForbiddenException $e) {
            $request->session()->flash('flash_error', $e->getMessage());
        } catch (\Throwable $e) {
            $request->session()->flash('flash_error', $e->getMessage());
        }

        return redirect()->route('scaffold.accounts');
    }

    public function destroy(Request $request, string $username): RedirectResponse
    {
        try {
            $this->assertCanWrite();
            $me = $this->currentUser($request);
            if ($username === $me) {
                throw new \RuntimeException('不能删除自己');
            }
            $this->store->delete($username, $me);
            $request->session()->flash('flash_message', "已删除账号 [{$username}]");
        } catch (AccountWriteForbiddenException $e) {
            $request->session()->flash('flash_error', $e->getMessage());
        } catch (\Throwable $e) {
            $request->session()->flash('flash_error', $e->getMessage());
        }

        return redirect()->route('scaffold.accounts');
    }

    // -------------------------------------------------------------------------

    /**
     * 从 request 抽取允许的字段，过滤未知 key。
     */
    private function extractPayload(Request $request, bool $includeUsername = true): array
    {
        $payload = [];
        if ($includeUsername) {
            $payload['username'] = trim((string) $request->input('username', ''));
            // plan-40 §五 F10(精简版):跟 routes.php / store 命名约定一致,422 比 store throw 友好
            if ($payload['username'] !== '' && ! preg_match('/^[A-Za-z0-9._-]{1,64}$/', $payload['username'])) {
                throw new \InvalidArgumentException('username 必须是 [A-Za-z0-9._-]+ 且长度 ≤ 64');
            }
        }
        foreach (['password', 'phone', 'role'] as $k) {
            if ($request->has($k)) {
                $payload[$k] = (string) $request->input($k, '');
            }
        }
        if ($request->has('enabled')) {
            $payload['enabled'] = filter_var($request->input('enabled'), FILTER_VALIDATE_BOOL);
        }
        if ($request->has('can_design_db')) {
            $payload['can_design_db'] = filter_var($request->input('can_design_db'), FILTER_VALIDATE_BOOL);
        }

        return $payload;
    }

    private function assertCanWrite(): void
    {
        if (app()->environment('production')) {
            throw new AccountWriteForbiddenException('生产环境禁止写入开发人员账号');
        }
        if ((bool) config('scaffold.config_ui.readonly', false)) {
            throw new AccountWriteForbiddenException('当前为强制只读模式（SCAFFOLD_CONFIG_READONLY）');
        }
    }

    private function isReadonly(): bool
    {
        return app()->environment('production') || (bool) config('scaffold.config_ui.readonly', false);
    }

    private function currentUser(Request $request): string
    {
        // 'unknown' fallback:account 写操作的 by 字段不接受 null。username 解析见 base Controller::currentOperator。
        return $this->currentOperator($request) ?? 'unknown';
    }
}
