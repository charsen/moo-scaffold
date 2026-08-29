<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Scaffold;

use Mooeen\Scaffold\Foundation\FormRequest;

class DashboardRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'history_app'  => is_string($this->input('history_app')) ? $this->input('history_app') : null,
            'history_page' => is_scalar($this->input('history_page')) ? $this->input('history_page') : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'history_app'  => ['nullable', 'string', 'max:64'],
            'history_page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
