<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Docs;

use Mooeen\Scaffold\Foundation\FormRequest;

class SaveRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'slug'    => ['required', 'string', 'max:200'],
            'content' => ['present', 'string'],
            'src'     => ['nullable', 'string', 'max:100'],
        ];
    }
}
