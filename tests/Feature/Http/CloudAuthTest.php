<?php declare(strict_types=1);

it('GET /scaffold/cloud 需要 scaffold 登录态', function () {
    config(['scaffold.auth.enabled' => true]);

    $this->get('/scaffold/cloud')
        ->assertRedirect();
});
