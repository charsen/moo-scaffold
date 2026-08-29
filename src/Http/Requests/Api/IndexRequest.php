<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Api;

use Mooeen\Scaffold\Foundation\FormRequest;

class IndexRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            '_doc_app'   => $this->cookie('scaffold_api_doc_app'),
            '_debug_app' => $this->cookie('scaffold_api_debug_app'),
        ]);
    }

    public function rules(): array
    {
        return [
            'app'        => ['nullable', 'string', 'max:64'],
            'f'          => ['nullable', 'string', 'max:128'],
            'c'          => ['nullable', 'string', 'max:200'],
            'a'          => ['nullable', 'string', 'max:200'],
            '_doc_app'   => ['nullable', 'string', 'max:64'],
            '_debug_app' => ['nullable', 'string', 'max:64'],
        ];
    }
}
