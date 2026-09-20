<?php declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Mooeen\Scaffold\Exceptions\BaseException;
use Mooeen\Scaffold\Exceptions\FormLayoutException;

/**
 * `Exceptions\BaseException::render()` 的**回执契约**（统一 JSON 信封 · 第 2 项 · 阶段 2 · 最后一项）。
 *
 * 背景：这里原先返回 `{message:"…"}` —— 全站**第 6 种**旧形态（没有 `ok` 布尔、也没有机器码，
 * 前端只能拿到一句文案、拿不到分支依据）。而 `render()` **此前零测试覆盖**：
 * `grep -rl BaseException tests/` 只命中「抛出了没」的 `toThrow(...)`，从没人断言过它的**响应体**。
 *
 * 本文件补四件事：
 *   ① **遍历 `src/Exceptions/` 下所有子类**并排断言同一形状 —— 将来新加子类自动纳管；
 *   ② 机器码由**类名派生**，逐名列表钉住（含连续大写的断字规则）；
 *   ③ 子类覆写的 `code` **原样作 HTTP 状态**（`FormLayoutException` = 402）—— 这是既有对外契约，不许顺手改；
 *   ④ 结构不变式：`render()` 只许有一处实现（子类别各自重写）。
 * 外加一条**打穿 HTTP 栈**的集成测试，证明信封真的以这个格式上线。
 */

/** `src/Exceptions/` 下所有可实例化的 BaseException（含基类自身），自动纳管新子类。 */
function excEnvelope_classes(): array
{
    $dir = __DIR__ . '/../../../src/Exceptions';
    $out = [];

    foreach (glob($dir . '/*.php') ?: [] as $file) {
        $fqcn = 'Mooeen\\Scaffold\\Exceptions\\' . basename($file, '.php');
        // is_a() 含「就是本类自身」，is_subclass_of() 不含 —— 基类也必须纳管
        if (class_exists($fqcn) && is_a($fqcn, BaseException::class, true)) {
            $out[$fqcn] = basename($file);
        }
    }

    ksort($out);

    return $out;
}

it('src/Exceptions 下每个业务异常都渲染同一信封（顶层恰好 ok/error，error 是对象三键）', function () {
    $classes = excEnvelope_classes();

    // 断言「确实扫到了东西」，否则 glob 写错会让整条测试变成空转（假绿）
    expect($classes)->not->toBeEmpty()
        ->and(array_keys($classes))->toContain(BaseException::class);

    foreach ($classes as $fqcn => $file) {
        $e    = new $fqcn('boom');
        $res  = $e->render(request());
        $body = json_decode((string) $res->getContent(), true);

        expect(array_keys($body))->toBe(['ok', 'error'], "{$file}：顶层恰好 ok/error")
            // 旧的 `{message:"…"}` 形态正是靠这两条被挡住的：它没有 ok 键、message 在顶层
            ->and($body['ok'])->toBeFalse("{$file}：ok 必须是 false")
            ->and(array_key_exists('message', $body))->toBeFalse("{$file}：顶层 message 必须消失")
            ->and($body['error'])->toBeArray("{$file}：error 必须是对象")
            ->and(array_keys($body['error']))->toBe(['code', 'msg', 'detail'], "{$file}：error 恰好三键")
            ->and($body['error']['msg'])->toBe('boom', "{$file}：msg 就是异常文案")
            ->and($body['error']['detail'])->toBe([])
            // HTTP 状态沿用异常自身的 code（本类既有对外契约）
            ->and($res->getStatusCode())->toBe($e->getCode(), "{$file}：HTTP 状态必须沿用自身 code");
    }
});

it('机器码由类名派生：SCREAMING_SNAKE，连续大写（CDN）当一个词', function () {
    // 期望值手写在测试里（不调用实现），否则就是自证
    $table = [
        BaseException::class                                    => 'BASE_EXCEPTION',
        FormLayoutException::class                              => 'FORM_LAYOUT_EXCEPTION',
        \Mooeen\Scaffold\Exceptions\ModelDeleteException::class => 'MODEL_DELETE_EXCEPTION',
        \Mooeen\Scaffold\Exceptions\BatchActionException::class => 'BATCH_ACTION_EXCEPTION',
        \Mooeen\Scaffold\Exceptions\CanNotEditException::class  => 'CAN_NOT_EDIT_EXCEPTION',
        \Mooeen\Scaffold\Exceptions\UploadException::class      => 'UPLOAD_EXCEPTION',
        \Mooeen\Scaffold\Exceptions\SaveMediaException::class   => 'SAVE_MEDIA_EXCEPTION',
        // 连续大写（CDN）当成一个词，不逐字母拆成 SYNC_C_D_N —— 这条专门钉住第二条分裂规则
        \Mooeen\Scaffold\Exceptions\SyncCDNException::class => 'SYNC_CDN_EXCEPTION',
    ];

    foreach ($table as $fqcn => $expected) {
        $body = json_decode((string) (new $fqcn('boom'))->render(request())->getContent(), true);
        expect($body['error']['code'])->toBe($expected, class_basename($fqcn));
    }
});

it('子类覆写的 code 原样作 HTTP 状态（FormLayoutException = 402，不被信封迁移吞掉）', function () {
    $base = new BaseException('boom');
    $form = new FormLayoutException('布局炸了');

    expect($base->render(request())->getStatusCode())->toBe(522)
        ->and($form->render(request())->getStatusCode())->toBe(402)
        ->and(json_decode((string) $form->render(request())->getContent(), true)['error']['msg'])
        ->toBe('布局炸了');
});

it('结构不变式：render() 只许有一处实现（子类别各自重写，机器码会因此失去派生）', function () {
    $offenders = [];

    foreach (glob(__DIR__ . '/../../../src/Exceptions/*.php') ?: [] as $file) {
        if (basename($file) === 'BaseException.php') {
            continue;
        }
        if (preg_match('/function\s+render\s*\(/', (string) file_get_contents($file))) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([], '业务异常的统一渲染只在 BaseException::render()；子类覆写会绕过机器码派生。');
});

it('打穿 HTTP 栈：抛出的业务异常以该信封上线（HTTP 522 + 顶层恰好 ok/error）', function () {
    Route::get('/_exc_envelope_probe', static function () {
        throw new BaseException('字段类型非法');
    });

    $res = $this->getJson('/_exc_envelope_probe');

    $res->assertStatus(522);
    $body = json_decode((string) $res->getContent(), true);

    expect(array_keys($body))->toBe(['ok', 'error'])
        ->and($body['error']['code'])->toBe('BASE_EXCEPTION')
        ->and($body['error']['msg'])->toBe('字段类型非法')
        ->and($body['error']['detail'])->toBe([]);
});
