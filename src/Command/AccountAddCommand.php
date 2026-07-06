<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Command;

use Mooeen\Scaffold\Support\AccountStore;
use Mooeen\Scaffold\Support\AccountWriteForbiddenException;

/**
 * 创建首个 / 新增开发人员账号。
 *
 * 这是唯一保留的账号 CLI：列表/编辑/启停/删除都走 Web UI（/scaffold/accounts）。
 * 留 CLI 的唯一理由是"yaml 不存在时怎么造出第一个账号" —— UI 必须有账号才能登录。
 *
 * 退出码：0 成功 / 2 参数错误 / 3 环境拒绝（prod/readonly）/ 4 数据冲突（用户名重复等）
 */
class AccountAddCommand extends Command
{
    protected bool $requiresLocalEnvironment = false;

    protected string $title = 'Account Add';

    protected $name = 'moo:account:add';

    protected $description = 'Add a scaffold developer account (prompts for missing fields)';

    protected $signature = 'moo:account:add
        {username? : Username (A-Za-z0-9._-, max 64 chars)}
        {--password= : Login password}
        {--phone= : Phone number (optional)}
        {--role=admin : Role: admin | member}
        {--disabled : Create the account disabled}
        {--by= : Operator name (defaults to posix_getlogin)}';

    public function handle(AccountStore $store): int
    {
        try {
            return $this->doHandle($store);
        } catch (AccountWriteForbiddenException $e) {
            $this->error($e->getMessage());

            return 3;
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return 4;
        }
    }

    private function doHandle(AccountStore $store): int
    {
        $username = (string) ($this->argument('username') ?? $this->ask('用户名'));
        $password = (string) ($this->option('password') ?? $this->secret('密码'));
        $phone    = (string) $this->option('phone');
        $role     = (string) $this->option('role');
        $enabled  = ! (bool) $this->option('disabled');

        $row = $store->create([
            'username' => $username,
            'password' => $password,
            'phone'    => $phone,
            'role'     => $role,
            'enabled'  => $enabled,
        ], $this->resolveBy());

        $this->info("已创建账号 [{$row['username']}] role={$row['role']} enabled=" . ($row['enabled'] ? 'Y' : 'N'));

        return 0;
    }

    private function resolveBy(): string
    {
        $by = (string) ($this->option('by') ?: '');
        if ($by !== '') {
            return $by;
        }
        if (function_exists('posix_getlogin')) {
            $login = @posix_getlogin();
            if (is_string($login) && $login !== '') {
                return $login;
            }
        }

        return 'cli';
    }
}
