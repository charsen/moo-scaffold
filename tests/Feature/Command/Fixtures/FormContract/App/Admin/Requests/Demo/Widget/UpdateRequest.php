<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Tests\Feature\Command\Fixtures\FormContract\App\Admin\Requests\Demo\Widget;

use Mooeen\Scaffold\Foundation\FormRequest;

class UpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
        ];
    }
}
