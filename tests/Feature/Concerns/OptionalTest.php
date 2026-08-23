<?php

declare(strict_types=1);

use Mooeen\Scaffold\Concerns\Optional;

function optionalModel(bool $show, mixed $deletedAt = null): object
{
    return new class($show, $deletedAt)
    {
        use Optional;

        public mixed $deleted_at;

        public function __construct(
            private readonly bool $show,
            mixed $deletedAt,
        ) {
            $this->deleted_at = $deletedAt;
        }

        protected function optionsAllowShow(): bool
        {
            return $this->show;
        }
    };
}

it('keeps the show action disabled by default', function () {
    $model = new class
    {
        use Optional;

        public mixed $deleted_at = null;
    };

    expect($model->getOptionsAttribute())->toBe([
        ['type' => 'edit'],
        ['type' => 'destroy'],
    ]);
});

it('adds the show action before live and trashed default actions when enabled', function () {
    expect(optionalModel(true)->getOptionsAttribute())->toBe([
        ['type' => 'show'],
        ['type' => 'edit'],
        ['type' => 'destroy'],
    ])->and(optionalModel(true, now())->getOptionsAttribute())->toBe([
        ['type' => 'show'],
        ['type' => 'restore'],
        ['type' => 'force-destroy'],
    ]);
});
