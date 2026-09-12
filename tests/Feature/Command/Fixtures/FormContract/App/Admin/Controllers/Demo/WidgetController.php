<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Tests\Feature\Command\Fixtures\FormContract\App\Admin\Controllers\Demo;

use Mooeen\Scaffold\Foundation\FormRequest;
use Mooeen\Scaffold\Foundation\FormWidgetCollection;

/**
 * 契约审计命令测试夹具：三个 rules 外的 reset 附加键，分别覆盖 visible / hidden / disabled 三桶。
 */
class WidgetController
{
    public function __construct(private readonly \stdClass $model) {}

    public function create(): void {}

    public function edit(): void {}

    protected function getFormWidgets(FormRequest $request, string $method): FormWidgetCollection
    {
        return FormWidgetCollection::makeForm($request, [
            'name'           => ['label' => 'Name'],
            'ghost_hidden'   => ['hidden' => true],
            'ghost_disabled' => ['disabled' => true],
            'ghost_visible'  => ['type' => 'text-amount'],
        ], $method === 'create');
    }
}
