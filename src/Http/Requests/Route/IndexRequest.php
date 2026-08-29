<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Route;

use Mooeen\Scaffold\Foundation\FormRequest;

class IndexRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['_last_app' => $this->cookie('scaffold_routes_app')]);
    }

    public function rules(): array
    {
        return [
            'app'       => ['nullable', 'string', 'max:64'],
            'm'         => ['nullable', 'string', 'max:128'],
            'keyword'   => ['nullable', 'string', 'max:200'],
            '_last_app' => ['nullable', 'string', 'max:64'],
        ];
    }
}
