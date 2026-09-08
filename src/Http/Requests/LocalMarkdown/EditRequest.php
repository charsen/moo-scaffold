<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\LocalMarkdown;

use Mooeen\Scaffold\Foundation\FormRequest;

class EditRequest extends FormRequest
{
    public function rules(): array
    {
        return ['slug' => ['required', 'string', 'max:500']];
    }
}
