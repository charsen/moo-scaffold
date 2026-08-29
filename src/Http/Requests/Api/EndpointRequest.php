<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Api;

use Mooeen\Scaffold\Foundation\FormRequest;

class EndpointRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'app'        => ['nullable', 'string', 'max:64'],
            'f'          => ['nullable', 'string', 'max:128'],
            'c'          => ['nullable', 'string', 'max:200'],
            'a'          => ['nullable', 'string', 'max:200'],
            'host_scope' => ['nullable', 'string', 'max:200'],
            'client_id'  => ['nullable', 'string', 'max:200'],
        ];
    }
}
