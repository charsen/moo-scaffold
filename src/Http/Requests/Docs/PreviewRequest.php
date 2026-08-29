<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Docs;

use Mooeen\Scaffold\Foundation\FormRequest;

class PreviewRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'content' => ['present', 'string'],
        ];
    }
}
