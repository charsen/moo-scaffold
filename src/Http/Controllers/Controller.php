<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Controllers;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\View\View;
use Mooeen\Scaffold\Utility;

/**
 * Class     Controller
 *
 * @author Charsen
 */
class Controller extends BaseController
{
    protected Utility $utility;

    protected Filesystem $filesystem;

    /**
     * Controller constructor.
     */
    public function __construct(Utility $utility, Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
        $this->utility    = $utility;
    }

    /**
     * Helper to get the config values.
     */
    protected function config(string $key, mixed $default = null): mixed
    {
        return $this->utility->getConfig($key, $default);
    }

    /**
     * Get the evaluated view contents for the given view.
     */
    protected function view(string $view, array $data = [], array $mergeData = []): View
    {
        return view()->make("scaffold::{$view}", $data, $mergeData);
    }

    /**
     * 当前登录用户名 —— ScaffoldAuthenticate 中间件解密 scaffold_auth cookie 后塞进 request attribute。
     * 绝不读 $req->cookies->get('scaffold_auth'):那是 AES-256+HMAC 密文字面(eyJpdi...),被当
     * by/operator 字段写进 yaml 会爆版(ship-checklist #12;2026-05-28 全面 audit catch 出 12+ 脏数据)。
     * authed 路由该 attr 必 set,无 cookie fallback(fallback = dead code + footgun)。
     */
    protected function currentOperator(Request $req): ?string
    {
        $user = $req->attributes->get('scaffold_auth_user');

        return is_string($user) && $user !== '' ? $user : null;
    }
}
