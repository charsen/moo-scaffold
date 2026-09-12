<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Tests\Feature\Command\Fixtures\FormContract\App\Admin\Controllers\Layout;

use Mooeen\Scaffold\Foundation\FormRequest;
use Mooeen\Scaffold\Foundation\FormWidgetCollection;

/**
 * 契约审计命令测试夹具：Request 定义了 formLayout()，rules 外的 ghost_outside 落在 layout 外。
 */
class LayoutController
{
    public function __construct(private readonly \stdClass $model) {}

    public function create(): void {}

    public function edit(): void {}

    protected function getFormWidgets(FormRequest $request, string $method): FormWidgetCollection
    {
        return FormWidgetCollection::makeForm($request, [
            'name'          => ['label' => 'Name'],
            'amount'        => ['label' => 'Amount'],
            'ghost_outside' => ['type' => 'text'],
        ], $method === 'create');
    }
}
