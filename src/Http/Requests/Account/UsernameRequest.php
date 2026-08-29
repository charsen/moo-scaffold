<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Account;

use Mooeen\Scaffold\Foundation\FormRequest;

class UsernameRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['username' => $this->route('username')]);
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
        ];
    }
}
