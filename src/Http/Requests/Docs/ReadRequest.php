<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Docs;

use Mooeen\Scaffold\Foundation\FormRequest;

class ReadRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'doc' => ['nullable', 'string', 'max:200'],
            'src' => ['nullable', 'string', 'max:100'],
        ];
    }
}
