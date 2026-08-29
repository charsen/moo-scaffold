<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Docs;

use Mooeen\Scaffold\Foundation\FormRequest;

class DeleteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:200'],
            'src'  => ['nullable', 'string', 'max:100'],
        ];
    }
}
