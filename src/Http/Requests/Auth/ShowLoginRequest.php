<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Auth;

use Mooeen\Scaffold\Foundation\FormRequest;

class ShowLoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'redirect' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
