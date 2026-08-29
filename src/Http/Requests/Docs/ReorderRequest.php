<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Docs;

use Mooeen\Scaffold\Foundation\FormRequest;

class ReorderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'slugs'   => ['required', 'array', 'min:1'],
            'slugs.*' => ['required', 'string', 'max:200'],
            'src'     => ['nullable', 'string', 'max:100'],
        ];
    }
}
