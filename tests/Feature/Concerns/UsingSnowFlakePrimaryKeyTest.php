<?php declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mooeen\Scaffold\Concerns\UsingSnowFlakePrimaryKey;

class SnowflakePrimaryKeyModel extends Model
{
    use UsingSnowFlakePrimaryKey;

    public $timestamps = false;
}

it('uses non-incrementing string primary keys', function () {
    $model = new SnowflakePrimaryKeyModel;

    expect($model->getIncrementing())->toBeFalse()
        ->and($model->getKeyType())->toBe('string');
});

it('fills an empty primary key from the shared snowflake service as a string', function () {
    app()->instance('scaffold.snowflake', new class
    {
        public function id(): int
        {
            return 9007199254740993;
        }
    });

    $model = new SnowflakePrimaryKeyModel;
    SnowflakePrimaryKeyModel::getEventDispatcher()->until(
        'eloquent.creating: ' . SnowflakePrimaryKeyModel::class,
        $model
    );

    expect($model->getKey())->toBe('9007199254740993');
});

it('exposes the shared snowflake through the framework unique-id extension point', function () {
    expect((new SnowflakePrimaryKeyModel)->newUniqueId())->toMatch('/^[0-9]{1,20}$/');
});

it('lets the creating hook take the key from newUniqueId so callers can pre-allocate it', function () {
    Schema::create('snowflake_primary_key_probes', function (Blueprint $table): void {
        $table->string('id', 64)->primary();
        $table->string('name');
    });

    // 覆写 newUniqueId 后落库主键即该值 —— 证明钩子只是委托，预分配与钩子同源。
    $model = new class extends Model
    {
        use UsingSnowFlakePrimaryKey;

        protected $table = 'snowflake_primary_key_probes';

        public $timestamps = false;

        protected $guarded = [];

        public function newUniqueId(): string
        {
            return '424242';
        }
    };

    $row = $model->newInstance(['name' => 'a']);
    $row->save();

    expect($row->getKey())->toBe('424242')
        ->and(DB::table('snowflake_primary_key_probes')->where('id', '424242')->count())->toBe(1);
});
