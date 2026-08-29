<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Designer;

use Mooeen\Scaffold\Foundation\FormRequest;

class CreateSchemaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'schema' => ['required', 'string', 'max:64', 'regex:/^[A-Z][A-Za-z0-9]*$/'],
            'name'   => ['required', 'string', 'max:100'],
            'desc'   => ['nullable', 'string', 'max:500'],
        ];
    }
}
