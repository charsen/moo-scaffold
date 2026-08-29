<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Designer;

use Mooeen\Scaffold\Foundation\FormRequest;

class MigrationContentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['file', 'table'] as $field) {
            if ($this->has($field) && ! is_string($this->input($field))) {
                $this->merge([$field => '']);
            }
        }
    }

    public function rules(): array
    {
        return [
            'file'  => ['nullable', 'string', 'max:255'],
            'table' => ['nullable', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
        ];
    }
}
