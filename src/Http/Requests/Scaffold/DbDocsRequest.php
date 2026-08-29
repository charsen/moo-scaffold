<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Scaffold;

use Mooeen\Scaffold\Foundation\FormRequest;

class DbDocsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'schema' => ['nullable', 'string', 'max:64', 'regex:/^[A-Z][A-Za-z0-9]*$/'],
            'table'  => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
        ];
    }
}
