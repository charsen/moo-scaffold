<?php

declare(strict_types=1);

use Mooeen\Scaffold\Concerns\Optional;

function optionalModel(bool $show, bool $showPage = false, mixed $deletedAt = null): object
{
    return new class($show, $showPage, $deletedAt)
    {
        use Optional;

        public mixed $deleted_at;

        public function __construct(
            private readonly bool $show,
            private readonly bool $showPage,
            mixed $deletedAt,
        ) {
            $this->deleted_at = $deletedAt;
        }

        protected function optionsAllowShow(): bool
        {
            return $this->show;
        }

        protected function optionsAllowShowPage(): bool
        {
            return $this->showPage;
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
    ])->and(optionalModel(true, false, now())->getOptionsAttribute())->toBe([
        ['type' => 'show'],
        ['type' => 'restore'],
        ['type' => 'force-destroy'],
    ]);
});

it('adds the show-page action before live and trashed default actions when enabled', function () {
    expect(optionalModel(false, true)->getOptionsAttribute())->toBe([
        ['type' => 'show-page'],
        ['type' => 'edit'],
        ['type' => 'destroy'],
    ])->and(optionalModel(false, true, now())->getOptionsAttribute())->toBe([
        ['type' => 'show-page'],
        ['type' => 'restore'],
        ['type' => 'force-destroy'],
    ]);
});

it('keeps the modal and page show actions independent and ordered', function () {
    expect(optionalModel(true, true)->getOptionsAttribute())->toBe([
        ['type' => 'show'],
        ['type' => 'show-page'],
        ['type' => 'edit'],
        ['type' => 'destroy'],
    ]);
});
