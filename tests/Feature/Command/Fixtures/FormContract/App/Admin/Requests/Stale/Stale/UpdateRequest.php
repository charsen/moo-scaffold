<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Tests\Feature\Command\Fixtures\FormContract\App\Admin\Requests\Stale\Stale;

use Mooeen\Scaffold\Foundation\FormRequest;

class UpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
            // @moo-waived removed_field: 控件已移除，标记应清理
            // 'removed_field' => ['nullable', 'string'],
            // @moo-waived restored_field: 规则已补回，标记应清理
            'restored_field' => ['nullable', 'string'],
        ];
    }
}
