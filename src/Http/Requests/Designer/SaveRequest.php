<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Designer;

use Mooeen\Scaffold\Foundation\FormRequest;

class SaveRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'module' => ['nullable', 'array'],
            'tables' => ['required', 'array'],
        ];
    }
}
