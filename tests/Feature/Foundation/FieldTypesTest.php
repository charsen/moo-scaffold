<?php declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Mooeen\Scaffold\Exceptions\BaseException;
use Mooeen\Scaffold\Forms\FieldTypes;
use Mooeen\Scaffold\Foundation\FormRequest;
use Mooeen\Scaffold\Foundation\RuntimeFormRequest;

it('可留空文本参数兼容 HTTP 空字符串转 null', function (string $type, string $key) {
    expect(FieldTypes::normalizeParams($type, [$key => null])[$key])->toBe('')
        ->and(FieldTypes::normalizeParams($type, [$key => ''])[$key])->toBe('')
        ->and(FieldTypes::normalizeParams($type, [$key => ' 提示 '])[$key])->toBe('提示');
    expect(fn () => FieldTypes::normalizeParams($type, [$key => []]))->toThrow(BaseException::class, '必须是文本');
})->with([['text', 'placeholder'], ['textarea', 'placeholder'], ['integer', 'unit'], ['decimal', 'unit']]);

it('金额显式清空单位仍拒绝，不能用默认单位替换', function () {
    expect(fn () => FieldTypes::normalizeParams('money', ['unit' => null]))->toThrow(BaseException::class)
        ->and(fn () => FieldTypes::normalizeParams('money', ['unit' => '']))->toThrow(BaseException::class);
});

it('整数小数金额共享参数校验且拒绝非法配置', function () {
    expect(FieldTypes::violations())->toBe([]);
    expect(fn () => FieldTypes::normalizeParams('integer', ['min' => 1.5]))->toThrow(BaseException::class);
    expect(fn () => FieldTypes::normalizeParams('money', ['unit' => '']))->toThrow(BaseException::class);
    expect(fn () => FieldTypes::normalizeParams('decimal', ['min' => 3, 'max' => 2]))->toThrow(BaseException::class);
    expect(fn () => FieldTypes::validateValue('integer', [], '1.2'))->toThrow(ValidationException::class);
    expect(fn () => FieldTypes::validateValue('money', ['precision' => 2], '1.234'))->toThrow(ValidationException::class);
});
it('金额转存储与回填保持精确，未声明缩放不会隐式转分', function () {
    $value = '9007199254740993.01';
    expect(FieldTypes::validateValue('money', [], $value))->toBe($value)
        ->and(FieldTypes::validateValue('money', ['storage_scale' => 2], $value))->toBe('900719925474099301')
        ->and(FieldTypes::displayValue('money', ['storage_scale' => 2, 'precision' => 2], '900719925474099301'))->toBe($value)
        ->and(FieldTypes::inputValue('money', ['storage_scale' => 2, 'precision' => 2], '123.456'))->toBe('1.23456')
        ->and(FieldTypes::normalizeValue('integer', [], ''))->toBeNull();
});
it('固定 Request 与运行时 Request 从同一字段规则投影', function () {
    $params = FieldTypes::normalizeParams('money', ['precision' => 2, 'min' => 0]);
    $rules  = ['amount' => FieldTypes::rules('money', $params, true)];
    $fixed  = new class($rules) extends FormRequest
    {
        public function __construct(private array $contractRules) {}

        public function rules(): array
        {
            return $this->contractRules;
        }
    };
    expect($fixed->getFormConfig())->toBe(RuntimeFormRequest::fromSchema($rules)->getFormConfig());
    $frontend = FieldTypes::frontendRules('money', $params, '应收金额', true);
    expect(array_column($frontend, 'rule'))->toContain('decimal:0,2', 'min:0')
        ->and(implode(' ', array_column($frontend, 'msg')))->toContain('应收金额');
});
it('停用选项保持原值定义，但拒绝作为新输入', function () {
    $params = ['options' => [1 => '原标签', 2 => '新标签'], 'disabled_options' => ['1']];
    expect(FieldTypes::options('select', $params))->toBe([1 => '原标签', 2 => '新标签']);
    expect(fn () => FieldTypes::validateValue('select', $params, 1))->toThrow(ValidationException::class);
    expect(FieldTypes::validateValue('select', $params, '2'))->toBe(2);
});

it('领域登记表显式扩展控件支持面，未知控件仍会被拒绝', function () {
    $domain = new class extends FieldTypes
    {
        public static function supportedWidgets(): array
        {
            return [...parent::supportedWidgets(), 'domain-picker'];
        }

        protected static function definitions(): array
        {
            return parent::definitions() + [
                'domain'  => ['widget' => 'domain-picker', 'params' => []],
                'unknown' => ['widget' => 'unregistered-picker', 'params' => []],
            ];
        }
    };
    expect($domain::violations())->toHaveCount(1)
        ->and($domain::violations()[0])->toContain('unregistered-picker')
        ->and(FieldTypes::supportedWidgets())->not->toContain('domain-picker');
});
