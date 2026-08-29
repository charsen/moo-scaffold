<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Designer;

use Mooeen\Scaffold\Foundation\FormRequest;

class ShowRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'table' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
        ];
    }
}
