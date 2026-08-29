<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Scaffold;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Mooeen\Scaffold\Foundation\FormRequest;

class CspReportRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            '_report'       => $this->isJson() ? $this->json()->all() : $this->all(),
            '_content_size' => strlen($this->getContent()),
            '_user_agent'   => $this->userAgent(),
            '_ip'           => $this->ip(),
        ]);
    }

    public function rules(): array
    {
        return [
            '_report'       => ['array'],
            '_content_size' => ['required', 'integer', 'max:8192'],
            '_user_agent'   => ['nullable', 'string', 'max:2000'],
            '_ip'           => ['nullable', 'ip'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($validator->errors()->has('_content_size')) {
            throw new HttpResponseException(response('payload too large', 413));
        }

        parent::failedValidation($validator);
    }
}
