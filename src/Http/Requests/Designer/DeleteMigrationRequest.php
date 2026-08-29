<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Designer;

use Mooeen\Scaffold\Foundation\FormRequest;

class DeleteMigrationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'clear_baseline' => ['sometimes', 'boolean'],
            'table_key'      => ['nullable', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
        ];
    }
}
