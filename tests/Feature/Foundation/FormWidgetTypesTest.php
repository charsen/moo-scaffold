<?php declare(strict_types=1);

use Mooeen\Scaffold\Support\FormWidgetTypes;

/**
 * FormWidgetTypes 收口回归锁（2026-09-11）。
 *
 * 背景：调试器 `public/javascript/pages/api-request.js` 曾内联一份 `KNOWN_WIDGET_TYPES`，
 * 注释写着“对齐下游 admin 前端 former/config.ts”，实际只能人工同步 —— 2026-06-10 手工补过
 * `rate-picker`，收口时两份清单已双向漂移：`text-amount` 只在下游注册表，`date` /
 * `cropper-image` 只在 JS。现收口到 `Support\FormWidgetTypes`，由 `api/request` 视图注入。
 *
 * 本文件锁三层：常量自身不重复、已知差异是显式事实、以及“JS 不再内联清单 + 视图确实注入”
 * 的接线锚点（防重构后又被复制回去）。
 */
it('两个子集各自无重复，且 detectable() 就是并集', function () {
    expect(FormWidgetTypes::FORMER)->toBe(array_values(array_unique(FormWidgetTypes::FORMER)));
    expect(FormWidgetTypes::DEBUGGER_RENDERABLE)->toBe(array_values(array_unique(FormWidgetTypes::DEBUGGER_RENDERABLE)));

    $union = array_values(array_unique([...FormWidgetTypes::FORMER, ...FormWidgetTypes::DEBUGGER_RENDERABLE]));
    expect(FormWidgetTypes::detectable())->toBe($union);
});

it('已知差异是既成事实：text-amount 只在 FORMER', function () {
    expect(FormWidgetTypes::FORMER)->toContain('text-amount');
    expect(FormWidgetTypes::DEBUGGER_RENDERABLE)->not->toContain('text-amount');
});

it('已知差异是既成事实：date / cropper-image 只在 DEBUGGER_RENDERABLE', function () {
    expect(FormWidgetTypes::DEBUGGER_RENDERABLE)->toContain('date');
    expect(FormWidgetTypes::DEBUGGER_RENDERABLE)->toContain('cropper-image');
    expect(FormWidgetTypes::FORMER)->not->toContain('date');
    expect(FormWidgetTypes::FORMER)->not->toContain('cropper-image');
});

/* ---------------------------------------------------------------------------
 * 接线锚点
 * ------------------------------------------------------------------------ */

it('api-request.js 读注入值，且不再内联 widget type 清单', function () {
    $js = file_get_contents(__DIR__ . '/../../../public/javascript/pages/api-request.js');
    expect($js)->toBeString();
    expect($js)->toContain('window.ScaffoldConfig.knownWidgetTypes');
    // 旧的内联赋值形态必须消失；漏一处即说明清单被复制回 JS
    expect($js)->not->toContain('var KNOWN_WIDGET_TYPES = [');
});

it('api/request 视图注入 detectable()', function () {
    $blade = file_get_contents(__DIR__ . '/../../../src/Http/Views/api/request.blade.php');
    expect($blade)->toBeString();
    expect($blade)->toContain('knownWidgetTypes');
    expect($blade)->toContain('FormWidgetTypes::detectable()');
});

it('GET /scaffold/api/request 把 detectable() 真吐进 window.ScaffoldConfig', function () {
    // 运行时锚点：上面那条只证明源码文本，这条证明页面真的注入了并集（含 FORMER-only 的
    // text-amount）——只断源码文本会在"视图改了但没生效"时假绿。
    $this->withoutMiddleware([
        \Mooeen\Scaffold\Http\Middleware\ScaffoldAuthenticate::class,
        \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        \Mooeen\Scaffold\Http\Middleware\EnforceScaffoldWritable::class,
    ]);

    $r = $this->followingRedirects()->get('/scaffold/api/request');
    $r->assertOk();
    $r->assertSee('knownWidgetTypes', false);
    $r->assertSee('text-amount', false);
});
