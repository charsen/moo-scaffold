<?php declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
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
