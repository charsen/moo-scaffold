<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Api;

use Mooeen\Scaffold\Foundation\FormRequest;

class CacheRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'key'    => ['nullable', 'string', 'max:200'],
            'params' => ['nullable', 'array'],
        ];
    }
}
