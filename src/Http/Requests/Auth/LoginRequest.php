<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Auth;

use Mooeen\Scaffold\Foundation\FormRequest;

class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'username' => ['nullable', 'string', 'max:64'],
            'password' => ['nullable', 'string', 'max:1024'],
            'redirect' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
