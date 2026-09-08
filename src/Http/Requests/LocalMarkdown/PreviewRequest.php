<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\LocalMarkdown;

use Mooeen\Scaffold\Foundation\FormRequest;

class PreviewRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // 原始 JSON 重新进入校验，避免 Host 的 TrimStrings / 空串转 null 改动 Markdown。
        abort_unless($this->isJson(), 415, '请使用 JSON 提交 Markdown。');
        $data = json_decode($this->getContent(), true);
        abort_unless(is_array($data), 422, '无效的 JSON。');
        $this->replace($data);
    }

    public function rules(): array
    {
        return [
            'slug'    => ['required', 'string', 'max:500'],
            'content' => ['present', 'string', 'max:2097152'],
        ];
    }
}
