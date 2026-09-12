<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Tests\Feature\Command\Fixtures\FormContract\App\Admin\Controllers\Commented;

use Mooeen\Scaffold\Foundation\FormRequest;
use Mooeen\Scaffold\Foundation\FormWidgetCollection;

/**
 * 契约审计命令测试夹具（反例）：commented_field 的规则被整行注释且**未声明 waived**，
 * 必须仍被报为可见违规 —— 防止豁免机制变成掩盖真漏写的后门。
 */
class CommentedController
{
    public function __construct(private readonly \stdClass $model) {}

    public function create(): void {}

    public function edit(): void {}

    protected function getFormWidgets(FormRequest $request, string $method): FormWidgetCollection
    {
        return FormWidgetCollection::makeForm($request, [
            'name'            => ['label' => 'Name'],
            'commented_field' => ['type' => 'text'],
        ], $method === 'create');
    }
}
