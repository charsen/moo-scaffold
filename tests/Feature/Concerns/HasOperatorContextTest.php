<?php

declare(strict_types=1);

/**
 * HasOperator × OperatorContext 集成（plan 42 · Phase A · D3）。
 *
 * 锁：context 下 creating/updating 优先取 context 值；无 context 回落 OperatorResolver；
 * fillable 不含对应列时不填（既有行为逐字节保持）。
 */

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mooeen\Scaffold\Concerns\HasOperator;
use Mooeen\Scaffold\Contracts\OperatorResolver;
use Mooeen\Scaffold\Support\OperatorContext;

beforeEach(function () {
    OperatorContext::clear();

    Schema::create('operator_context_probes', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->unsignedBigInteger('creator_id')->nullable();
        $table->unsignedBigInteger('updater_id')->nullable();
        $table->timestamps();
    });

    // 默认 resolver 恒返回 100，用来区分「取 context 值」还是「回落 resolver」。
    app()->bind(OperatorResolver::class, fn () => new class implements OperatorResolver
    {
        public function id(): int|string|null
        {
            return 100;
        }
    });
});

afterEach(fn () => OperatorContext::clear());

it('creating：context 下 creator_id / updater_id 取 context 值', function () {
    $model = new class extends Model
    {
        use HasOperator;

        protected $table = 'operator_context_probes';

        protected $fillable = ['name', 'creator_id', 'updater_id'];
    };

    $row = OperatorContext::runAs(42, fn () => $model::create(['name' => 'a']));

    expect($row->creator_id)->toBe(42)
        ->and($row->updater_id)->toBe(42);
});

it('updating：context 下 updater_id 取 context 值，creator_id 不动', function () {
    $model = new class extends Model
    {
        use HasOperator;

        protected $table = 'operator_context_probes';

        protected $fillable = ['name', 'creator_id', 'updater_id'];
    };

    // 先在 resolver 语境建（creator = updater = 100）
    $row = $model::create(['name' => 'a']);
    expect($row->creator_id)->toBe(100)
        ->and($row->updater_id)->toBe(100);

    // context 下更新
    OperatorContext::runAs(42, function () use ($row): void {
        $row->name = 'b';
        $row->save();
    });

    expect($row->creator_id)->toBe(100)   // creating-only，updating 不动 creator
        ->and($row->updater_id)->toBe(42); // updating 取 context
});

it('无 context：回落 OperatorResolver（creator / updater = 100）', function () {
    $model = new class extends Model
    {
        use HasOperator;

        protected $table = 'operator_context_probes';

        protected $fillable = ['name', 'creator_id', 'updater_id'];
    };

    $row = $model::create(['name' => 'a']);

    expect($row->creator_id)->toBe(100)
        ->and($row->updater_id)->toBe(100);
});

it('fillable 不含 creator_id / updater_id 时不填（既有行为保持）', function () {
    $model = new class extends Model
    {
        use HasOperator;

        protected $table = 'operator_context_probes';

        protected $fillable = ['name']; // 不含 operator 列
    };

    $row = OperatorContext::runAs(42, fn () => $model::create(['name' => 'a']));

    expect($row->creator_id)->toBeNull()
        ->and($row->updater_id)->toBeNull();
});
