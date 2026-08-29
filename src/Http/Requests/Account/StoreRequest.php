<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Account;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Mooeen\Scaffold\Foundation\FormRequest;

class StoreRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $booleanFields = [];
        foreach (['enabled', 'can_design_db'] as $field) {
            if ($this->has($field)) {
                $booleanFields[$field] = filter_var($this->input($field), FILTER_VALIDATE_BOOL);
            }
        }
        $this->merge($booleanFields);
    }

    public function rules(): array
    {
        return [
            'username'      => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'password'      => ['sometimes', 'nullable', 'string'],
            'phone'         => ['sometimes', 'nullable', 'string'],
            'role'          => ['sometimes', 'nullable', 'string'],
            'enabled'       => ['sometimes', 'boolean'],
            'can_design_db' => ['sometimes', 'boolean'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $this->session()->flash('flash_error', $validator->errors()->first());

        throw new HttpResponseException(redirect()->route('scaffold.accounts'));
    }
}
