<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Mooeen\Scaffold\Http\Controllers\Controller;
use Mooeen\Scaffold\Utility;

/**
 * scaffold UI 基类 `Http\Controllers\Controller`。
 *
 * 为什么现在才补：13 个 UI 控制器全部依赖它，但此前**零测试**。第 9 项给 `DesignerController`
 * 接上继承时，它替掉了三处手写实现 —— 2 处 `view('scaffold::…')` 前缀、3 处直接读
 * `$request->attributes->get('scaffold_auth_user')`。而后者正是「作者盖章」链路的入口
 * （`saveModule()` / `createTable()` 的 `$author`），这条链路此前只在 loader 层有断言
 * （`SchemaLoaderWriteTest` 的 `updated_by`），controller 侧零覆盖。
 */

/** 探针子类：把两个受保护方法暴露出来（反射也能 invoke protected，但匿名子类更直白）。 */
function controller_base_probe(): Controller
{
    return new class(app(Utility::class), new Filesystem) extends Controller
    {
        public function operator(Request $request): ?string
        {
            return $this->currentOperator($request);
        }

        public function viewName(string $view): string
        {
            return $this->view($view)->name();
        }
    };
}

/**
 * 造一个带 `scaffold_auth_user` attribute 的请求；`$user === null` 表示「attribute 不存在」。
 *
 * 属性名与 `ScaffoldAuthenticate` 中间件塞的一致 —— 绝不读 `scaffold_auth` cookie：
 * 那是密文字面，被当作者写进 yaml 会爆版（见基类方法注释）。
 */
function controller_base_request(mixed $user = null): Request
{
    $request = Request::create('/scaffold/db/designer');
    if ($user !== null) {
        $request->attributes->set('scaffold_auth_user', $user);
    }

    return $request;
}

it('currentOperator()：只认非空字符串，其余一律 null', function () {
    $probe = controller_base_probe();

    expect($probe->operator(controller_base_request('charsen')))->toBe('charsen')
        ->and($probe->operator(controller_base_request()))->toBeNull()
        ->and($probe->operator(controller_base_request('')))->toBeNull()
        // 非字符串不能当作者：旧写法 `(string) $attr` 会把数组变成字面量 'Array' 写进 yaml
        ->and($probe->operator(controller_base_request(['a'])))->toBeNull()
        ->and($probe->operator(controller_base_request(42)))->toBeNull();
});

it('view()：统一加 scaffold:: 前缀，控制器只写视图短名', function () {
    expect(controller_base_probe()->viewName('db.designer.index'))->toBe('scaffold::db.designer.index');
});
