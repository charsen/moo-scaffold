<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Plans;

use Mooeen\Scaffold\Foundation\FormRequest;

class ReadRequest extends FormRequest
{
    public function rules(): array
    {
        return ['doc' => ['nullable', 'string', 'max:500']];
    }
}
