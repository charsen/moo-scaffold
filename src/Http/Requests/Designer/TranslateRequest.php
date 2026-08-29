<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Requests\Designer;

use Illuminate\Validation\Rule;
use Mooeen\Scaffold\Foundation\FormRequest;

class TranslateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'scene'             => ['required', Rule::in(['fields', 'enums', 'spell_check', 'table_short'])],
            'table'             => ['required_if:scene,fields', 'string'],
            'prefix'            => ['required_if:scene,fields', 'nullable', 'string'],
            'existing_fields'   => ['exclude_unless:scene,fields', 'array'],
            'existing_fields.*' => ['string', 'max:64'],
            'field'             => ['required_if:scene,enums', 'string'],
            'module'            => ['required_if:scene,table_short', 'string'],
            'inputs'            => ['required', 'array', 'max:200'],
            'inputs.*'          => ['string', 'max:64'],
            'lenient'           => ['exclude_unless:scene,fields', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $scene = $this->input('scene');
            $limit = $scene === 'table_short' ? 5 : ($scene === 'spell_check' ? 200 : 50);
            if (is_array($this->input('inputs')) && count($this->input('inputs')) > $limit) {
                $validator->errors()->add('inputs', "inputs 不能超过 {$limit} 项。");
            }
        });
    }
}
