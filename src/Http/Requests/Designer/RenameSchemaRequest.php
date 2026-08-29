<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Designer;

use Mooeen\Scaffold\Foundation\FormRequest;

class RenameSchemaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'new_name' => ['required', 'string', 'max:64', 'regex:/^[A-Z][A-Za-z0-9]*$/'],
        ];
    }
}
