<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Docs;

use Mooeen\Scaffold\Foundation\FormRequest;

class SearchRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ];
    }
}
