<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Config;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Mooeen\Scaffold\Foundation\FormRequest;

class UpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'fields' => ['array'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $this->session()->flash('flash_error', '提交数据格式不合法：' . $validator->errors()->first());
        $group = (string) $this->route('group');

        throw new HttpResponseException(
            redirect()->to(route('scaffold.config') . '#group-' . $group),
        );
    }
}
