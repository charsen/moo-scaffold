<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Designer;

use Mooeen\Scaffold\Foundation\FormRequest;

class CompactExecuteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'table'    => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'clean_db' => ['nullable', 'boolean'],
            'force'    => ['nullable', 'boolean'],
        ];
    }
}
