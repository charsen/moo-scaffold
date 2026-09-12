<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Tests\Feature\Command\Fixtures\FormContract\App\Admin\Requests\Waived\Waived;

use Mooeen\Scaffold\Foundation\FormRequest;

class StoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
            // @moo-waived legacy_field: 早期精简：nullable 字段暂不实现
            // 'legacy_field' => ['nullable', 'string'],
        ];
    }
}
