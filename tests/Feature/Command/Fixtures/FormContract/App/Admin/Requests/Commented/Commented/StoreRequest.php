<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Tests\Feature\Command\Fixtures\FormContract\App\Admin\Requests\Commented\Commented;

use Mooeen\Scaffold\Foundation\FormRequest;

class StoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
            // 'commented_field' => ['nullable', 'string'],
        ];
    }
}
