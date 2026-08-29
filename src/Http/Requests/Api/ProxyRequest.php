<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Api;

use Mooeen\Scaffold\Foundation\FormRequest;

class ProxyRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            '_proxy_url'     => ['required', 'string', 'url', 'max:2000'],
            '_proxy_method'  => ['nullable', 'string', 'in:GET,POST,PUT,PATCH,DELETE,get,post,put,patch,delete'],
            '_proxy_headers' => ['nullable', 'array'],
            '_proxy_params'  => ['nullable', 'array'],
        ];
    }
}
