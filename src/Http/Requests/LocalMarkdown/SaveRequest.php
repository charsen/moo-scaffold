<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\LocalMarkdown;

class SaveRequest extends PreviewRequest
{
    public function rules(): array
    {
        return parent::rules() + ['version' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/']];
    }
}
