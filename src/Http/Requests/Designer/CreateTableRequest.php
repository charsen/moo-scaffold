<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Designer;

use Mooeen\Scaffold\Foundation\FormRequest;

class CreateTableRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'table_key' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'name'      => ['required', 'string', 'max:100'],
            'desc'      => ['nullable', 'string', 'max:500'],
            'prefix'    => ['nullable', 'string', 'max:30'],
        ];
    }
}
