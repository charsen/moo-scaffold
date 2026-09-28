<?php

declare(strict_types=1);

use Mooeen\Scaffold\Foundation\FormRequest;

class FieldErrorFixtureRequest extends FormRequest
{
    public function rules(): array
    {
        return ['name' => ['required', 'string']];
    }
}

class ActionErrorFixtureRequest extends FieldErrorFixtureRequest
{
    protected bool $fieldValidation = false;
}

class DeniedActionFixtureRequest extends ActionErrorFixtureRequest
{
    public function authorize(): bool
    {
        return false;
    }
}

it('表单校验保留字段422，无表单动作校验返回522，合法输入正常通过', function () {
    Route::post('/_validation/form', fn (FieldErrorFixtureRequest $request) => $request->validated());
    Route::post('/_validation/action', fn (ActionErrorFixtureRequest $request) => $request->validated());
    Route::post('/_validation/denied', fn (DeniedActionFixtureRequest $request) => $request->validated());

    $this->postJson('/_validation/form', [])->assertUnprocessable()->assertJsonValidationErrors('name');
    $response = $this->postJson('/_validation/action', [])->assertStatus(522)->assertJsonPath('ok', false);
    expect($response->json('error.msg'))->not->toBeEmpty();
    expect($response->json())->not->toHaveKeys(['errors', 'message']);
    $this->postJson('/_validation/action', ['name' => '合法输入'])->assertOk()->assertJsonPath('name', '合法输入');
    $this->postJson('/_validation/denied', [])->assertForbidden();
});

it('keeps aggregate array rules separate from wildcard item rules', function () {
    $request = new class extends FormRequest
    {
        public function rules(): array
        {
            return [
                'files'   => ['nullable', 'array', 'max:10'],
                'files.*' => ['required', 'string', 'max:500', 'distinct'],
            ];
        }
    };

    $config = $request->getFormConfig(reset: [
        'files' => ['type' => 'upload-file', 'multiple' => true],
    ]);

    expect($config)->toHaveCount(1)
        ->and($config['files']['required'])->toBeFalse()
        ->and($config['files']['type'])->toBe('upload-file')
        ->and($config['files']['multiple'])->toBeTrue()
        ->and(array_column($config['files']['rules'], 'rule'))->toBe(['nullable', 'array', 'max:10']);
});

it('uses wildcard rules as a multiple signal without overwriting the parent widget', function () {
    $request = new class extends FormRequest
    {
        public function rules(): array
        {
            return [
                'states.*' => ['required', 'integer', 'in:7,9'],
                'states'   => ['nullable', 'array'],
            ];
        }

        public function options(string $field): array
        {
            return $field === 'states' ? [7 => 'On', 9 => 'Off'] : [];
        }
    };

    $config = $request->getFormConfig(reset: [
        'states' => ['default' => [7]],
    ]);

    expect($config)->toHaveCount(1)
        ->and($config['states']['required'])->toBeFalse()
        ->and($config['states']['type'])->toBe('radio')
        ->and($config['states']['multiple'])->toBeTrue()
        ->and($config['states']['default'])->toBe([7])
        ->and(array_column($config['states']['rules'], 'rule'))->toBe(['nullable', 'array']);
});

it('keeps supporting a wildcard-only widget definition', function () {
    $request = new class extends FormRequest
    {
        public function rules(): array
        {
            return [
                'tags.*' => ['required', 'string'],
            ];
        }
    };

    $config = $request->getFormConfig();

    expect($config)->toHaveCount(1)
        ->and($config['tags']['required'])->toBeTrue()
        ->and(array_column($config['tags']['rules'], 'rule'))->toBe(['required', 'string']);
});
