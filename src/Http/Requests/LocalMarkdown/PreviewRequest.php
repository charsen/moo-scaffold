<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\LocalMarkdown;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;
use Mooeen\Scaffold\Exceptions\BaseException;
use Mooeen\Scaffold\Foundation\FormRequest;

class PreviewRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // 原始 JSON 重新进入校验，避免 Host 的 TrimStrings / 空串转 null 改动 Markdown。
        abort_unless($this->isJson(), 415, '请使用 JSON 提交 Markdown。');
        $data = json_decode($this->getContent(), true);
        if (! is_array($data)) {
            throw new BaseException('无效的 JSON。');
        }
        $this->replace($data);
    }

    protected function failedValidation(Validator $validator): void
    {
        if (! $validator->errors()->has('content')) {
            throw new BaseException($validator->errors()->first());
        }

        throw ValidationException::withMessages(['content' => $validator->errors()->get('content')]);
    }

    public function rules(): array
    {
        return [
            'slug'    => ['required', 'string', 'max:500'],
            'content' => ['present', 'string', 'max:2097152'],
        ];
    }
}
