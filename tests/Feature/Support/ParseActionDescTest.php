<?php declare(strict_types=1);

use Mooeen\Scaffold\Support\ActionDoc;

/**
 * ActionDoc::parseActionDesc —— 2026-06-21:moo:api 接口描述来源。
 * docblock 第 1 行是 name(parseActionName 取),第 2 行起的散文行是 desc(多行 list),跳空行 + @tag。
 *
 * 2026-09-19 随阶段 3b 从 `Utility` 迁为 `ActionDoc`（纯静态）—— 断言本身没变，只换了取用方式：
 * 原先每个用例都要 `app(Utility::class)` 拿一个有状态服务只为调一个纯函数，现在直接静调。
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
    $ref = new ReflectionMethod(fixtureWithDocblocks(), 'index');

    expect(ActionDoc::parseActionDesc($ref))->toBe(['分页返回当前用户的站内消息。', '带未读数。']);
    // parseActionName 仍取第一行 name(不受影响)
    expect(ActionDoc::parseActionName($ref))->toBe('消息列表');
});

it('parseActionDesc:单行 docblock / 无 docblock → desc 为空', function () {
    expect(ActionDoc::parseActionDesc(new ReflectionMethod(fixtureWithDocblocks(), 'show')))->toBe([]);
    expect(ActionDoc::parseActionDesc(new ReflectionMethod(fixtureWithDocblocks(), 'store')))->toBe([]);
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

    expect(ActionDoc::parseActionDesc(new ReflectionMethod($fixture, 'store')))
        ->toBe(['把购物车选中的服务结算成订单', '第二条说明']);
});
