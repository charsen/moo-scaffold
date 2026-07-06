<?php declare(strict_types=1);

use Mooeen\Scaffold\Utility;

/**
 * Utility::parseActionDesc —— 2026-06-21:moo:api 接口描述来源。
 * docblock 第 1 行是 name(parseActionName 取),第 2 行起的散文行是 desc(多行 list),跳空行 + @tag。
 */
function fixtureWithDocblocks(): object
{
    return new class
    {
        /**
         * 消息列表
         * 分页返回当前用户的站内消息。
         * 带未读数。
         *
         * @param int $x
         */
        public function index(): void {}

        /**
         * 只有名字单行
         */
        public function show(): void {}

        public function store(): void {}
    };
}

it('parseActionDesc:取第 2 行起散文做 desc,跳空行 + @tag', function () {
    $u   = app(Utility::class);
    $ref = new ReflectionMethod(fixtureWithDocblocks(), 'index');

    expect($u->parseActionDesc($ref))->toBe(['分页返回当前用户的站内消息。', '带未读数。']);
    // parseActionName 仍取第一行 name(不受影响)
    expect($u->parseActionName($ref))->toBe('消息列表');
});

it('parseActionDesc:单行 docblock / 无 docblock → desc 为空', function () {
    $u = app(Utility::class);

    expect($u->parseActionDesc(new ReflectionMethod(fixtureWithDocblocks(), 'show')))->toBe([]);
    expect($u->parseActionDesc(new ReflectionMethod(fixtureWithDocblocks(), 'store')))->toBe([]);
});

it('parseActionDesc:去掉行首 markdown 列表符(- / *),展示端自带项目符避免双重', function () {
    $fixture = new class
    {
        /**
         * 创建订单
         * - 把购物车选中的服务结算成订单
         * * 第二条说明
         */
        public function store(): void {}
    };
    $u = app(Utility::class);

    expect($u->parseActionDesc(new ReflectionMethod($fixture, 'store')))
        ->toBe(['把购物车选中的服务结算成订单', '第二条说明']);
});
