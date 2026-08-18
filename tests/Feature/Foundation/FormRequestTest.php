<?php

declare(strict_types=1);

use Mooeen\Scaffold\Foundation\FormRequest;

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
