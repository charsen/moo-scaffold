<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Config;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Mooeen\Scaffold\Foundation\FormRequest;

class UpdateAiRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'base_url'        => ['nullable', 'url:http,https', 'max:2000'],
            'api_key'         => ['nullable', 'string', 'max:2000'],
            'model'           => ['nullable', 'string', 'max:200'],
            'timeout'         => ['nullable', 'integer', 'min:1', 'max:120'],
            'connect_timeout' => ['nullable', 'integer', 'min:1', 'max:120'],
            'max_tokens'      => ['nullable', 'integer', 'min:1', 'max:65536'],
            'temperature'     => ['nullable', 'numeric', 'min:0', 'max:2'],
        ];
    }

    public function messages(): array
    {
        return [
            'url'     => ':attribute 必须是 http(s):// 开头的合法 URL',
            'integer' => ':attribute 必须是整数',
            'numeric' => ':attribute 必须是数字',
            'min'     => ':attribute 不能小于 :min',
            'max'     => ':attribute 不能大于 :max',
            'string'  => ':attribute 必须是字符串',
        ];
    }

    public function attributes(): array
    {
        return [
            'base_url'        => '上游地址',
            'api_key'         => 'API Key',
            'model'           => '模型',
            'timeout'         => '总超时',
            'connect_timeout' => '连接超时',
            'max_tokens'      => '生成上限',
            'temperature'     => '温度',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $this->session()->flash('flash_error', $validator->errors()->first());

        throw new HttpResponseException(redirect()->route('scaffold.config.ai'));
    }
}
