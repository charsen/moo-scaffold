<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Designer;

use Mooeen\Scaffold\Foundation\FormRequest;

class ConfirmRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'confirm_key' => ['required', 'string', 'max:64'],
        ];
    }
}
