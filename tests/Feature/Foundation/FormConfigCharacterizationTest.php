<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Mooeen\Scaffold\Exceptions\FormLayoutException;
use Mooeen\Scaffold\Foundation\FormRequest;
use Mooeen\Scaffold\Foundation\FormWidgetCollection;

/**
 * FormRequest / FormWidgetCollection 表单契约**特征测试**（S2 收口前置网，2026-09-11）。
 *
 * 为什么单独一份：这套契约的下游体量是 1100+ 个 `extends FormRequest` 的子类、约 1500 处
 * 控件调用点、70 个 `formLayout()` 与 184 个 `options()` 定制，而收口前只有 7 个用例（全是
 * 历史 bug 回归）。要动 `formatFormConfig` / `getFrontendRules`，先得把**当前行为**钉死。
 *
 * 本文件只锁**现状**，不表达"应该怎样"：断言写的就是读代码得到的行为，包括几个看起来奇怪
 * 但已被下游依赖的点（首当其冲是"规则外的 reset 键被原样追加成控件"——host 有业务注释与
 * `audit:form-contract` 命令专门依赖它）。收口后这些断言必须逐条不变。
 */
function anonymousFormRequest(array $rules, ?callable $options = null): FormRequest
{
    return new class($rules, $options) extends FormRequest
    {
        public function __construct(private readonly array $fixtureRules, private readonly mixed $fixtureOptions = null) {}

        public function rules(): array
        {
            return $this->fixtureRules;
        }

        public function options(string $field): array
        {
            return $this->fixtureOptions === null ? [] : ($this->fixtureOptions)($field);
        }
    };
}

/* ---------------------------------------------------------------------------
 * 一、rules → 控件类型反推（formatFormConfig 的 type 分支）
 * ------------------------------------------------------------------------ */

it('反推:date 规则 → date-picker;字段名含 password → password;无命中则**不写 type**', function () {
    $c = anonymousFormRequest([
        'expire_date'      => ['required', 'date'],
        'password'         => ['required', 'string'],
        'password_confirm' => ['nullable', 'string'],
        'plain'            => ['nullable', 'string'],
    ])->getFormConfig();

    expect($c['expire_date']['type'])->toBe('date-picker');
    expect($c['password']['type'])->toBe('password');
    expect($c['password_confirm']['type'])->toBe('password');
    // 无命中时键**不存在**（'input' 是 FormWidgetCollection::toArray 后补的，不在这里）
    expect(array_key_exists('type', $c['plain']))->toBeFalse();
});

it('反推:date 优先于 password（elseif 顺序）——含 password 的日期字段仍是 date-picker', function () {
    $c = anonymousFormRequest([
        'password_expire_date' => ['required', 'date'],
    ])->getFormConfig();

    expect($c['password_expire_date']['type'])->toBe('date-picker');
});

it('反推:options() 非空 → radio + dictionary,且**无条件覆盖** date/password 反推', function () {
    $c = anonymousFormRequest(
        [
            'status'          => ['required', 'integer'],
            'status_date'     => ['nullable', 'date'],
            'status_states'   => ['nullable', 'array'],
            'status_states.*' => ['required', 'integer'],
        ],
        fn (string $field): array => str_starts_with($field, 'status') ? [1 => '启用', 2 => '停用'] : [],
    )->getFormConfig(with_default: true);

    // options 命中 → radio，覆盖了 status_date 的 date 反推
    expect($c['status']['type'])->toBe('radio');
    expect($c['status_date']['type'])->toBe('radio');
    expect($c['status']['dictionary'])->toBeTrue();
    expect($c['status']['options'])->toBe([1 => '启用', 2 => '停用']);
    // with_default=true → 取 options 的首个键
    expect($c['status']['default'])->toBe(1);
    // wildcard 存在 → multiple=true（父字段行）
    expect($c['status_states']['multiple'])->toBeTrue();
});

it('反推:with_default=false 时不写 default（只写 options/dictionary/type）', function () {
    $c = anonymousFormRequest(
        ['status' => ['required', 'integer']],
        fn (): array => [1 => '启用'],
    )->getFormConfig();

    expect($c['status'])->not->toHaveKey('default');
    expect($c['status']['type'])->toBe('radio');
});

/* ---------------------------------------------------------------------------
 * 二、前端规则投影（getFrontendRules）
 * ------------------------------------------------------------------------ */

it('前端规则:跳过非字符串 / $this->get / exists:,只留可下发的字符串规则', function () {
    $c = anonymousFormRequest([
        'title' => ['required', 'string', 'max:10', '$this->getInEnums', 'exists:foo,id', Rule::in(['a', 'b'])],
        'note'  => ['nullable', 'string'],
    ])->getFormConfig();

    expect(array_column($c['title']['rules'], 'rule'))->toBe(['required', 'string', 'max:10']);
    expect(array_column($c['note']['rules'], 'rule'))->toBe(['nullable', 'string']);
});

it('前端规则:nullable 的 msg 为空串,其余规则 msg 走 validation.* 且替换 :attribute', function () {
    app('translator')->addLines([
        'validation.required'         => ':attribute 不能为空',
        'validation.max'              => ':attribute 超出 :max',
        'validation.attributes.title' => '标题',
    ], 'zh_CN');
    app()->setLocale('zh_CN');

    $c = anonymousFormRequest([
        'title' => ['required', 'max:10'],
        'note'  => ['nullable'],
    ])->getFormConfig();

    expect($c['note']['rules'][0])->toBe(['rule' => 'nullable', 'msg' => '']);
    expect($c['title']['rules'][0]['msg'])->toBe('标题 不能为空');
    // 规则名取冒号前的部分（max:10 → validation.max），:max 由 Laravel 回填、此处不在 msg 内
    expect($c['title']['rules'][1]['rule'])->toBe('max:10');
});

it('前端规则:通配子项在父字段已存在时整条跳过（不生成聚合控件的重复规则）', function () {
    $c = anonymousFormRequest([
        'files'   => ['nullable', 'array'],
        'files.*' => ['required', 'string'],
    ])->getFormConfig();

    expect(array_column($c['files']['rules'], 'rule'))->toBe(['nullable', 'array']);
    expect($c)->toHaveCount(1);
});

it('前端规则:仅通配定义（无父字段）时用去通配的字段名承载', function () {
    $c = anonymousFormRequest([
        'tags.*' => ['required', 'string'],
    ])->getFormConfig();

    expect($c)->toHaveKey('tags');
    expect(array_column($c['tags']['rules'], 'rule'))->toBe(['required', 'string']);
});

/* ---------------------------------------------------------------------------
 * 三、reset / exclude 合并语义（host 依赖最重的一块）
 * ------------------------------------------------------------------------ */

it('reset:同名字段按「reset 胜」逐键覆盖,原字段属性保留', function () {
    $c = anonymousFormRequest([
        'body' => ['required', 'string'],
    ])->getFormConfig(reset: [
        'body' => ['type' => 'textarea', 'label' => '正文'],
    ]);

    expect($c['body']['type'])->toBe('textarea');
    expect($c['body']['label'])->toBe('正文');
    expect($c['body']['required'])->toBeTrue();
    expect($c['body']['field'])->toBe('body');
});

it('reset:**规则外的键被原样追加成控件**（host 依赖的既成契约，不是 bug）', function () {
    $c = anonymousFormRequest([
        'a' => ['required', 'string'],
    ])->getFormConfig(reset: [
        'ghost' => ['type' => 'text-amount', 'label' => '只读金额'],
    ]);

    expect($c)->toHaveKey('ghost');
    // 原样附加：没有 field / required / rules —— 这三项由 FormWidgetCollection::toArray 后补
    expect($c['ghost'])->toBe(['type' => 'text-amount', 'label' => '只读金额']);
});

it('exclude:排除 a.* 只丢通配子项行,父字段行保留;排除字段名才连父带子一起丢', function () {
    $rules = [
        'keep'    => ['nullable', 'string'],
        'files'   => ['nullable', 'array'],
        'files.*' => ['required', 'string'],
        'page'    => ['nullable', 'integer'],
    ];

    // 只 exclude 'files.*' ⇒ 父字段 files 仍生成控件,但 reset 里的 files 覆盖会被清掉
    $c = anonymousFormRequest($rules)->getFormConfig(
        reset: ['files' => ['label' => '文件'], 'ghost' => ['label' => 'G']],
        exclude: ['files.*', 'page', 'ghost'],
    );

    expect($c)->toHaveKey('keep');
    expect($c)->toHaveKey('files');
    expect($c['files'])->not->toHaveKey('label');           // reset['files'] 被 exclude 清掉
    expect(array_column($c['files']['rules'], 'rule'))->toBe(['nullable', 'array']);
    expect($c)->not->toHaveKey('page');
    expect($c)->not->toHaveKey('ghost');

    // exclude 字段名本身 ⇒ 父行与通配行都不出现
    $c2 = anonymousFormRequest($rules)->getFormConfig(exclude: ['files']);
    expect($c2)->not->toHaveKey('files');
});

it('rules 为空时 getFormConfig 返回空数组（reset 也一并丢弃）', function () {
    $c = anonymousFormRequest([])->getFormConfig(reset: ['x' => ['label' => 'X']]);

    expect($c)->toBe([]);
});

it('required 判定只认精确的 required 字符串', function () {
    $c = anonymousFormRequest([
        'a' => ['required'],
        'b' => ['required_if:flag,1'],
        'c' => ['nullable'],
    ])->getFormConfig();

    expect($c['a']['required'])->toBeTrue();
    expect($c['b']['required'])->toBeFalse();
    expect($c['c']['required'])->toBeFalse();
});

/* ---------------------------------------------------------------------------
 * 四、checkFormLayout 边界
 * ------------------------------------------------------------------------ */

it('checkFormLayout:布局齐全时原样返回,并忽略 page / page_limit', function () {
    $req = anonymousFormRequest([
        'a'          => ['required', 'string'],
        'b'          => ['required', 'string'],
        'page'       => ['nullable', 'integer'],
        'page_limit' => ['nullable', 'integer'],
    ]);

    $layout = [['a', 'b']];
    expect($req->checkFormLayout($layout))->toBe($layout);
});

it('checkFormLayout:布局漏字段 / 引用不存在字段都抛 FormLayoutException', function () {
    $req = anonymousFormRequest([
        'a' => ['required', 'string'],
        'b' => ['required', 'string'],
    ]);

    expect(fn () => $req->checkFormLayout([['a']]))->toThrow(FormLayoutException::class);
    expect(fn () => $req->checkFormLayout([['a', 'b', 'c']]))->toThrow(FormLayoutException::class);
});

it('checkFormLayout:通配子项归一化到父字段名后可被布局覆盖', function () {
    $req = anonymousFormRequest([
        'tags.*' => ['required', 'string'],
    ]);

    expect($req->checkFormLayout([['tags']]))->toBe([['tags']]);
});

it('checkFormLayout:单控件行支持「字符串下标」与「字段名 => span 配置」两种写法', function () {
    $req = anonymousFormRequest([
        'a' => ['required', 'string'],
        'b' => ['required', 'string'],
    ]);

    expect($req->checkFormLayout([['a'], ['b' => ['span' => 12]]]))
        ->toBe([['a'], ['b' => ['span' => 12]]]);
});

/* ---------------------------------------------------------------------------
 * 五、FormWidgetCollection 投影与布局
 * ------------------------------------------------------------------------ */

it('makeForm:create 表单不排 page/page_limit,默认一个控件一行,并补齐 field/required/type', function () {
    $req = anonymousFormRequest([
        'name' => ['required', 'string'],
        'age'  => ['nullable', 'integer'],
    ]);

    $rows = FormWidgetCollection::makeForm($req, base: ['name' => ['label' => '姓名']])
        ->toArray(Request::create('/'));

    expect($rows)->toHaveCount(2);
    expect($rows[0])->toHaveCount(1);

    $name = $rows[0][0];
    expect($name['field'])->toBe('name');
    expect($name['label'])->toBe('姓名');            // reset 给的 label 不被覆盖
    expect($name['required'])->toBeTrue();
    expect($name['type'])->toBe('input');            // 无 type 时 toArray 补 input
    expect($name['keep_id'])->toBeFalse();
});

it('makeSearch:排除 page/page_limit,默认所有控件排在同一行', function () {
    $req = anonymousFormRequest([
        'name'       => ['nullable', 'string'],
        'age'        => ['nullable', 'integer'],
        'page'       => ['nullable', 'integer'],
        'page_limit' => ['nullable', 'integer'],
    ]);

    $rows = FormWidgetCollection::makeSearch($req)->toArray(Request::create('/'));

    expect($rows)->toHaveCount(1);                    // 搜索表单默认一行
    expect($rows[0])->toHaveCount(2);
    expect(array_column($rows[0], 'field'))->toBe(['name', 'age']);
});

it('makeSearch:额外 exclude 参数同样生效', function () {
    $req = anonymousFormRequest([
        'name'       => ['nullable', 'string'],
        'age'        => ['nullable', 'integer'],
        'page'       => ['nullable', 'integer'],
        'page_limit' => ['nullable', 'integer'],
    ]);

    $rows = FormWidgetCollection::makeSearch($req, exclude: ['age'])->toArray(Request::create('/'));

    expect(array_column($rows[0], 'field'))->toBe(['name']);
});

it('withLayout:formLayout() 覆盖默认排布,span 缺省补 null', function () {
    $req = new class extends FormRequest
    {
        public function rules(): array
        {
            return [
                'a' => ['required', 'string'],
                'b' => ['required', 'string'],
                'c' => ['required', 'string'],
            ];
        }

        public function formLayout(): array
        {
            return [['a', 'b'], ['c']];
        }
    };

    $rows = FormWidgetCollection::makeForm($req)->toArray(Request::create('/'));

    expect($rows)->toHaveCount(2);
    expect(array_column($rows[0], 'field'))->toBe(['a', 'b']);
    expect($rows[0][0]['span'])->toBeNull();
    expect(array_column($rows[1], 'field'))->toBe(['c']);
});

it('toArray:dictionary 选项被映射成 [{label,value}] 且 dictionary 标记被摘掉', function () {
    $req = anonymousFormRequest(
        ['customer_id' => ['required', 'integer']],
        fn (): array => [1 => '甲', 2 => '乙'],
    );

    $rows = FormWidgetCollection::makeForm($req, base: ['customer_id' => ['label' => '客户ID']])
        ->toArray(Request::create('/'));

    $widget = $rows[0][0];
    expect($widget['type'])->toBe('radio');
    // 注意:options 保留**原始键**（此处 1/2），不重新索引 —— JSON 序列化后是对象而非数组
    expect($widget['options'])->toBe([
        1 => ['label' => '甲', 'value' => 1],
        2 => ['label' => '乙', 'value' => 2],
    ]);
    expect($widget)->not->toHaveKey('dictionary');
    // label 里的 'ID' 被清掉（keep_id 默认 false）
    expect($widget['label'])->toBe('客户');
});

it('toArray:label 含「 Ids」时清掉并做复数化', function () {
    $req = anonymousFormRequest(['contract_ids' => ['required', 'string']]);

    $rows = FormWidgetCollection::makeForm($req, base: ['contract_ids' => ['label' => 'Contract Ids']])
        ->toArray(Request::create('/'));

    expect($rows[0][0]['label'])->toBe('Contracts');
});

it('toArray:select / cascader / 图片类补齐专属属性;placeholder 分两个家族', function () {
    $req = anonymousFormRequest([
        'kind'   => ['required', 'string'],
        'memo'   => ['required', 'string'],
        'area'   => ['required', 'string'],
        'avatar' => ['required', 'string'],
    ]);

    $rows = FormWidgetCollection::makeForm($req, base: [
        'kind'   => ['type' => 'select', 'label' => '同名字段'],
        'memo'   => ['type' => 'input', 'label' => '同名字段'],
        'area'   => ['type' => 'cascader', 'label' => '区域'],
        'avatar' => ['type' => 'upload-image', 'label' => '头像', 'width' => 120, 'height' => 120],
    ])->toArray(Request::create('/'));

    $byField = collect($rows)->flatten(1)->keyBy('field');

    expect($byField['kind']['multiple'])->toBeFalse();
    expect($byField['kind']['limit'])->toBe(0);

    expect($byField['area']['multiple'])->toBeFalse();
    expect($byField['area']['strictly'])->toBeFalse();
    expect($byField['area']['array'])->toBeFalse();

    expect($byField['avatar']['tip'])->toBe('图片最小尺寸为： 120px * 120px');

    // select/cascader/date/radio/checkbox 走 please_select,其余走 please_enter —— 前缀不同但都带 label
    expect($byField['kind']['placeholder'])->toContain('同名字段');
    expect($byField['memo']['placeholder'])->toContain('同名字段');
    expect($byField['kind']['placeholder'])->not->toBe($byField['memo']['placeholder']);
});
