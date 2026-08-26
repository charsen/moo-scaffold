<?php declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('主题 logo 组件在普通与装饰语境保持正确可访问语义', function () {
    $labelled   = Blade::render('<x-scaffold::brand-logo class="probe-logo" />');
    $decorative = Blade::render('<x-scaffold::brand-logo class="probe-logo" :decorative="true" />');

    expect($labelled)
        ->toContain('brand-logo probe-logo')
        ->toContain('role="img"')
        ->toContain('aria-label="Scaffold"')
        ->and($decorative)
        ->toContain('aria-hidden="true"')
        ->not->toContain('role="img"');
});

it('亮暗主题分别映射到用户提供的独立 logo 资源', function () {
    $packageRoot = dirname(__DIR__, 3);
    expect(is_file($packageRoot . '/public/images/logo-light.png'))->toBeTrue()
        ->and(is_file($packageRoot . '/public/images/logo-moon.png'))->toBeTrue();

    $scss = file_get_contents($packageRoot . '/public/sass/6-components/_brand-logo.scss');
    expect($scss)
        ->toContain('logo-light.png')
        ->toContain('aspect-ratio: 4041 / 552')
        ->toContain('[data-theme="dark"] .brand-logo')
        ->toContain('logo-moon.png');
});
