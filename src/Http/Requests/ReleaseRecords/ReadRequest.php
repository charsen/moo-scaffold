<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\ReleaseRecords;

use Mooeen\Scaffold\Foundation\FormRequest;

class ReadRequest extends FormRequest
{
    public function rules(): array
    {
        return ['record' => ['nullable', 'string', 'max:500']];
    }
}
