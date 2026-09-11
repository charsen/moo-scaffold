<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Mooeen\Scaffold\Exceptions\FormLayoutException;
use Mooeen\Scaffold\Foundation\FormRequest;
use Mooeen\Scaffold\Foundation\FormWidgetCollection;
use Mooeen\Scaffold\Foundation\RuntimeFormRequest;

/**
 * RuntimeFormRequest 回归（2026-09-11）—— 锁「动态 schema 是同一表单契约的另一个生产者」。
 *
 * 判据不是"能渲染"，而是**与生成式 FormRequest 逐字段同形**：同一份 rules 下，两者的
 * `getFormConfig()` 必须完全相等。否则前端/调试器就要养两套表单。
 */
function runtimeGenerated(array $rules): FormRequest
{
    return new class($rules) extends FormRequest
    {
        public function __construct(private readonly array $fixtureRules) {}

        public function rules(): array
        {
            return $this->fixtureRules;
        }
    };
}

it('同一份 rules 下,运行时 schema 与生成式 FormRequest 的 getFormConfig 逐字段相等', function () {
    $rules = [
        'brand'        => ['required', 'integer'],
        'install_date' => ['required', 'date'],
        'load_kg'      => ['nullable', 'integer'],
        'remark'       => ['nullable', 'string', 'max:500'],
        'password'     => ['nullable', 'string'],
    ];

    $runtime   = RuntimeFormRequest::fromSchema($rules)->getFormConfig();
    $generated = runtimeGenerated($rules)->getFormConfig();

    expect($runtime)->toBe($generated);
});

it('运行时 options 命中 → radio + dictionary；date 规则 → date-picker；无命中不写 type', function () {
    $request = RuntimeFormRequest::fromSchema(
        [
            'brand'        => ['required', 'integer'],
            'install_date' => ['required', 'date'],
            'load_kg'      => ['nullable', 'integer'],
        ],
        ['brand' => [1 => '奥的斯', 2 => '三菱']],
    );

    $widgets = FormWidgetCollection::makeForm($request, base: ['brand' => ['label' => '品牌']])
        ->toArray(Request::create('/'));
    $byField = collect($widgets)->flatten(1)->keyBy('field');

    expect($byField['brand']['type'])->toBe('radio');
    // dictionary 选项被映射成 [{label,value}] 且保留原始键
    expect($byField['brand']['options'])->toBe([
        1 => ['label' => '奥的斯', 'value' => 1],
        2 => ['label' => '三菱', 'value' => 2],
    ]);
    expect($byField['brand'])->not->toHaveKey('dictionary');
    expect($byField['install_date']['type'])->toBe('date-picker');
    expect($byField['load_kg']['type'])->toBe('input');
});

it('运行时 schema 的搜索表单同样排除 page/page_limit 并排成一行', function () {
    $request = RuntimeFormRequest::fromSchema([
        'brand'      => ['nullable', 'integer'],
        'load_kg'    => ['nullable', 'integer'],
        'page'       => ['nullable', 'integer'],
        'page_limit' => ['nullable', 'integer'],
    ]);

    $rows = FormWidgetCollection::makeSearch($request)->toArray(Request::create('/'));

    expect($rows)->toHaveCount(1);
    expect(array_column($rows[0], 'field'))->toBe(['brand', 'load_kg']);
});

it('运行时 layout 走同一套 checkFormLayout 校验,漏字段照样抛错', function () {
    $ok = RuntimeFormRequest::fromSchema(
        ['a' => ['required', 'string'], 'b' => ['required', 'string']],
        [],
        [['a', 'b']],
    );
    expect($ok->formLayout())->toBe([['a', 'b']]);
    expect($ok->checkFormLayout($ok->formLayout()))->toBe([['a', 'b']]);

    $bad = RuntimeFormRequest::fromSchema(['a' => ['required', 'string']], [], [['a', 'b']]);
    expect(fn () => $bad->checkFormLayout($bad->formLayout()))->toThrow(FormLayoutException::class);
});

it('runtime schema 的显式控件覆盖仍走 makeForm 的 base/override（动态类型不靠规则反推硬猜）', function () {
    // 后端显式给类型与参数（例如 text-amount 这类无法从规则反推的展示控件）
    $request = RuntimeFormRequest::fromSchema(['balance' => ['nullable', 'numeric']]);

    $widgets = FormWidgetCollection::makeForm($request, base: [
        'balance' => ['type' => 'text-amount', 'label' => '可用余额'],
    ])->toArray(Request::create('/'));

    expect($widgets[0][0]['type'])->toBe('text-amount');
    expect($widgets[0][0]['label'])->toBe('可用余额');
});
