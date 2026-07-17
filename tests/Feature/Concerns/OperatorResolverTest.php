<?php

declare(strict_types=1);

/**
 * B-01 方案 B —— OperatorResolver 注入缝回归。
 *
 * 覆盖：默认容器绑定（GuardOperatorResolver = auth()->id()，未登录 null / 登录返回 id）、
 * host 可 bind 覆盖，以及共享 HasOperator 已经由契约取数。
 */

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mooeen\Scaffold\Concerns\HasOperator;
use Mooeen\Scaffold\Contracts\OperatorResolver;
use Mooeen\Scaffold\ScaffoldProvider;
use Mooeen\Scaffold\Support\GuardOperatorResolver;

it('默认绑定 GuardOperatorResolver；未登录 id() 返回 null', function () {
    $resolver = app(OperatorResolver::class);

    expect($resolver)->toBeInstanceOf(GuardOperatorResolver::class);
    expect($resolver->id())->toBeNull();
});

it('actingAs 后默认实现 id() = auth()->id()', function () {
    $user = new GenericUser(['id' => 42]);
    $this->actingAs($user);

    expect(app(OperatorResolver::class)->id())->toBe(42);
});

it('host 可 bind 覆盖默认实现', function () {
    app()->bind(OperatorResolver::class, fn () => new class implements OperatorResolver
    {
        public function id(): int|string|null
        {
            return 999;
        }
    });

    expect(app(OperatorResolver::class)->id())->toBe(999);
});

it('host 预先绑定实现时默认绑定不覆盖', function () {
    $hostResolver = new class implements OperatorResolver
    {
        public function id(): int|string|null
        {
            return 888;
        }
    };

    app()->instance(OperatorResolver::class, $hostResolver);
    (new ScaffoldProvider(app()))->register();

    expect(app(OperatorResolver::class))->toBe($hostResolver);
});

it('共享 HasOperator 经 OperatorResolver 取操作人 ID', function () {
    $trait = file_get_contents(__DIR__ . '/../../../src/Concerns/HasOperator.php');

    expect($trait)->toContain('use Mooeen\Scaffold\Contracts\OperatorResolver;');
    expect($trait)->toContain('app(OperatorResolver::class)->id()');
    expect($trait)->not->toContain('auth()->id()');
});

it('共享 HasOperator 真实事件：无身份保留 null', function () {
    Schema::create('operator_contract_probes', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->unsignedBigInteger('creator_id')->nullable();
        $table->unsignedBigInteger('updater_id')->nullable();
        $table->timestamps();
    });

    app()->bind(OperatorResolver::class, fn () => new class implements OperatorResolver
    {
        public function id(): int|string|null
        {
            return null;
        }
    });

    $nullableModel = new class extends Model
    {
        use HasOperator;

        protected $table = 'operator_contract_probes';

        protected $fillable = ['name', 'creator_id', 'updater_id'];
    };

    $nullable = $nullableModel::create(['name' => 'nullable']);

    expect($nullable->creator_id)->toBeNull()
        ->and($nullable->updater_id)->toBeNull();

});
