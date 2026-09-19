<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Http\Controllers\Controller;
use Mooeen\Scaffold\Utility;

/**
 * `/scaffold` 统一 JSON 信封的守卫（`{ok:true,data:{…}}` / `{ok:false,error:{code,msg,detail}}`）。
 *
 * 背景：收敛前后端有 ~10 种响应形态（`{status:'ok'}` / `{error:"字符串"}` / `{html,error}` / 裸数据 …），
 * 前端 55 处错误提取写成两套**互不兼容**的读法（docs 系把 `error` 当字符串直接 toast、
 * designer 系当对象取 `.msg`/`.code`）。本文件钉住两件事：
 *   ① 信封本身长什么样（`Controller::ok()` / `error()` → `Support\JsonEnvelope`）——
 *      形状恒定，前端才敢只写一层解包；
 *   ② 整个 `src/` 里不许再出现**绕过信封**的裸 `response()->json()`。
 *
 * 白名单分两段：
 *   - `$allowed`（**永久**）：`JsonEnvelope.php` 是信封的**单一实现**（唯一出口，中间件与控制器共用）；
 *     `ApiProxyController.php` 必须原样透传上游 body（套信封会破坏代理语义），`_proxy_status` 契约也得保留。
 *   - `$knownDebt`（**欠账清单**，只减不增，机制照 `WriteFailureGuardTest` 的 `$knownDebt`）：
 *     每迁完一处就删掉对应行。扫描范围 2026-09-19 已从 `src/Http` 扩到整个 `src/`。
 */

/** 暴露基类的 protected `ok()` / `error()` —— 它们没有别的公开入口。 */
function jse_probe(): object
{
    return new class(app(Utility::class), app(Filesystem::class)) extends Controller
    {
        public function pubOk(array $data = []): \Illuminate\Http\JsonResponse
        {
            return $this->ok($data);
        }

        public function pubError(string $code, string $msg, int $http, array $detail = []): \Illuminate\Http\JsonResponse
        {
            return $this->error($code, $msg, $http, $detail);
        }
    };
}

it('ok()：{ok:true,data:{…}} + HTTP 200', function () {
    $res = jse_probe()->pubOk(['slug' => 'a/b']);

    expect($res->getStatusCode())->toBe(200)
        ->and(json_decode($res->getContent(), true))->toBe([
            'ok'   => true,
            'data' => ['slug' => 'a/b'],
        ]);
});

it('ok()：不给 data 时是空数组而不是缺键（前端解包不必判 undefined）', function () {
    expect(json_decode(jse_probe()->pubOk()->getContent(), true))
        ->toBe(['ok' => true, 'data' => []]);
});

it('error()：{ok:false,error:{code,msg,detail}} + 指定的 HTTP 码（别一律 200）', function () {
    $res = jse_probe()->pubError('SLUG_INVALID', 'slug 非法。', 422, ['field' => 'slug']);

    expect($res->getStatusCode())->toBe(422)
        ->and(json_decode($res->getContent(), true))->toBe([
            'ok'    => false,
            'error' => [
                'code'   => 'SLUG_INVALID',
                'msg'    => 'slug 非法。',
                'detail' => ['field' => 'slug'],
            ],
        ]);
});

it('error()：detail 缺省为空数组（形状恒定 ⇒ 前端可无脑取 .error.msg）', function () {
    $body = json_decode(jse_probe()->pubError('BOOM', '炸了', 500)->getContent(), true);

    expect($body['ok'])->toBeFalse()
        ->and($body['error'])->toBe(['code' => 'BOOM', 'msg' => '炸了', 'detail' => []]);
});

it('结构不变式：整个 src/ 只有一处信封实现 + 一处代理透传（白名单只减不增）', function () {
    // ① 信封的**单一实现**（`ok()` / `error()` 正是在这里裸返回的 —— 它就是那个"唯一出口"）
    // ② **永久排除**：ApiProxyController —— body 是上游 API 原样透传，套信封会破坏代理语义；
    //    它的 `_proxy_status` 契约（HTTP 恒 200 + body 带真实状态）也必须保留
    $allowed = [
        'JsonEnvelope.php',
        'ApiProxyController.php',
    ];
    // ③ 迁移中的**欠账**：每迁完一处就删掉对应行（只减不增）
    $knownDebt = [
        'BaseException.php',
    ];

    $offenders = [];

    // 扫描范围 2026-09-19 从 `src/Http` 扩到整个 `src/`：原范围**漏掉了**
    // `src/Exceptions/BaseException.php` —— 它一直是个裸 JSON 出口，却因为不在 Http 目录下
    // 而从未被这条不变式看见。守卫的覆盖面必须等于契约的范围，不能等于「当初随手写的那个目录」。
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        __DIR__ . '/../../../src',
        FilesystemIterator::SKIP_DOTS,
    ));
    foreach ($it as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        // `response()->json(` 是**出口**；`$response->json()` 是**读取上游响应**，别误伤（负向前瞻挡 `$`）
        if (! preg_match('/(?<![\$\w])response\(\)->json\(/', (string) file_get_contents($file->getPathname()))) {
            continue;
        }
        if (! in_array($file->getFilename(), [...$allowed, ...$knownDebt], true)) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe(
        [],
        '新增 JSON 出口请走 Support\JsonEnvelope（控制器可继续写 $this->ok() / $this->error()）；确实要裸返回就加进本文件的白名单并写清理由。',
    );
});

it('结构不变式：控制器里不许长出第二份信封实现（DesignerController 的去重不许回退）', function () {
    $src = (string) file_get_contents(
        __DIR__ . '/../../../src/Http/Controllers/DesignerController.php',
    );

    // 本控制器原来是全站事实标准的来源，现已上提（基类薄壳 → Support\JsonEnvelope）——
    // 别再长出第二份实现
    expect($src)->not->toMatch('/function\s+(ok|error)\(/')
        ->and($src)->toContain('已上提到基类');
});
