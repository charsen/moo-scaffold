<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Tests\Feature\Command\Fixtures\FormContract\App\Admin\Controllers\Stale;

use Mooeen\Scaffold\Foundation\FormRequest;
use Mooeen\Scaffold\Foundation\FormWidgetCollection;

/**
 * 契约审计命令测试夹具：Request 里的 waived 标记已陈旧 ——
 * removed_field 的控件早已移除、restored_field 的规则已补回，两者都不再构成违规。
 * 命令必须把它们作为 stale marker 报出来，而不是静默忽略。
 */
class StaleController
{
    public function __construct(private readonly \stdClass $model) {}

    public function create(): void {}

    public function edit(): void {}

    protected function getFormWidgets(FormRequest $request, string $method): FormWidgetCollection
    {
        return FormWidgetCollection::makeForm($request, [
            'name'           => ['label' => 'Name'],
            'restored_field' => ['label' => 'Restored'],
        ], $method === 'create');
    }
}
