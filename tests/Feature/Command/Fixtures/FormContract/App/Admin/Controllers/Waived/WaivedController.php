<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Tests\Feature\Command\Fixtures\FormContract\App\Admin\Controllers\Waived;

use Mooeen\Scaffold\Foundation\FormRequest;
use Mooeen\Scaffold\Foundation\FormWidgetCollection;

/**
 * 契约审计命令测试夹具：Request 经 waivedFormFields() 显式声明 legacy_field 为「刻意不实现」。
 */
class WaivedController
{
    public function __construct(private readonly \stdClass $model) {}

    public function create(): void {}

    public function edit(): void {}

    protected function getFormWidgets(FormRequest $request, string $method): FormWidgetCollection
    {
        return FormWidgetCollection::makeForm($request, [
            'name'         => ['label' => 'Name'],
            'legacy_field' => ['type' => 'text'],
        ], $method === 'create');
    }
}
