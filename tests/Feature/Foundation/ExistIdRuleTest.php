<?php

declare(strict_types=1);

/**
 * `getExistId()` 的**入参形态守卫** —— 钉住「原样透传模型类 FQCN」这个刻意选择。
 *
 * ## 为什么这个文件存在：它守的是一条被证伪过的结论
 *
 * 生成器产出 `$this->getExistId(\App\Models\X::class)`，helper 直接拼成 `exists:App\Models\X,id`。
 * 看上去像「把 FQCN 当表名查询 ⇒ 必然 500」，2026-09-21 因此把 helper 改成「先把入参归一成
 * `getTable()`」，随后**撤回**（判定为误判）。
 *
 * **真相**：Laravel 的 `exists` / `unique` 规则**自己就会解析模型类** ——
 *   - 字符串规则 → `ValidatesAttributes::parseTable()`
 *   - `Rule::exists()` 对象 → `DatabaseRule::resolveTableName()`
 * 两处在 Laravel 10 / 11 / 12 逐字相同，而且都会**带上模型自己的 connection**
 * （`$connection ??= $model->getConnectionName()`）—— 手工归一反而会**丢掉 connection**，
 * 让跨库模型查到默认库上去。所以「原样透传」不只是够用，而是**更正确**。
 *
 * ## 误判是怎么发生的（最后一组用例把这个陷阱钉住）
 *
 * 「226 条规则是坏的」这个结论来自**离线扫规则串**的 harness：那里宿主的模型类**加载不到**
 * ⇒ `class_exists()` 为 false ⇒ 框架不解析 ⇒ 真的抛 `no such table: App\Models\X`。
 * 同一串形态在「类可加载 / 不可加载」两种上下文里结果**相反**。
 * ⇒ **判断这条链路必须在应用内跑一次 `Validator`**，静态扫规则串得出的结论不可信。
 */

namespace ScaffoldExistIdFixtures {
    class PlainParent extends \Illuminate\Database\Eloquent\Model
    {
        protected $table = 'exist_id_probe_parents';
    }

    /** 跨库模型：`getTable()` 之外还带 `$connection` —— 手工归一最容易丢的就是它。 */
    class ConnParent extends \Illuminate\Database\Eloquent\Model
    {
        protected $table = 'exist_id_conn_parents';

        protected $connection = 'exist_id_conn';
    }
}

namespace {

    use Illuminate\Database\QueryException;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Illuminate\Support\Facades\Validator;
    use Mooeen\Scaffold\Foundation\FormRequest;
    use ScaffoldExistIdFixtures\ConnParent;
    use ScaffoldExistIdFixtures\PlainParent;

    function existIdGuardRequest(): FormRequest
    {
        return new class extends FormRequest
        {
            public function ruleFor(string $modelOrTable): string
            {
                return $this->getExistId($modelOrTable);
            }

            public function rules(): array
            {
                return [];
            }
        };
    }

    function bootExistIdDb(): void
    {
        config()->set('database.default', 'exist_id_probe');
        config()->set('database.connections.exist_id_probe', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        DB::purge('exist_id_probe');

        Schema::create('exist_id_probe_parents', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name')->nullable();
        });
    }

    it('原样透传入参 —— 模型类 FQCN 不做归一，表名也照样吃', function () {
        expect(existIdGuardRequest()->ruleFor(PlainParent::class))
            ->toBe('exists:' . PlainParent::class . ',id')
            ->and(existIdGuardRequest()->ruleFor('light_books'))
            ->toBe('exists:light_books,id');
    });

    it('框架自己把模型类解析成真实表名（所以原样透传是对的）', function () {
        // 第三项 `id` 是模型主键名 —— 框架连 `getKeyName()` 一起解析了
        expect(Validator::make([], [])->parseTable(PlainParent::class))
            ->toBe([null, 'exist_id_probe_parents', 'id']);
    });

    it('端到端：原样的 FQCN 规则在真实库上正常工作，不是 500', function () {
        bootExistIdDb();
        DB::connection('exist_id_probe')->table('exist_id_probe_parents')->insert(['id' => 7, 'name' => '存在']);

        $rules = ['parent_id' => ['required', 'numeric', existIdGuardRequest()->ruleFor(PlainParent::class)]];

        expect(Validator::make(['parent_id' => 7], $rules)->passes())->toBeTrue()
            ->and(Validator::make(['parent_id' => 999], $rules)->passes())->toBeFalse()
            ->and(Validator::make(['parent_id' => 999], $rules)->errors()->first('parent_id'))
            ->toContain('invalid');
    });

    it('框架会带上模型自己的 connection —— 手工归一成表名会丢掉它', function () {
        $validator = Validator::make([], []);

        expect($validator->parseTable(ConnParent::class))
            ->toBe(['exist_id_conn', 'exist_id_conn_parents', 'id'])
            // 对照：helper 若归一成裸表名，框架只能拿到表名、connection 变成 null
            // ⇒ 跨库模型会被查到**默认库**上，而且**不报错**（静默查错库）。
            ->and($validator->parseTable('exist_id_conn_parents'))
            ->toBe([null, 'exist_id_conn_parents', null]);
    });

    it('⚠ 只有「类加载不到」时才退化成表名 —— 离线 harness 的误判来源', function () {
        bootExistIdDb();

        $ghost     = 'App\\Models\\Ghost\\Nope';
        $validator = Validator::make([], []);

        expect(class_exists($ghost))->toBeFalse()
            ->and($validator->parseTable($ghost)[1])->toBe($ghost);

        // 这才是「把 FQCN 当表名查询」的真实形态 —— 它只在类加载不到的上下文里发生。
        // 所以别拿离线扫规则串的结果判断线上行为。
        expect(fn () => Validator::make(['x' => 1], ['x' => ['exists:' . $ghost . ',id']])->passes())
            ->toThrow(QueryException::class);
    });
}
