<?php declare(strict_types=1);

use Mooeen\Scaffold\Foundation\BaseFilter;

it('filters pagination and empty values from filter input', function () {
    $filter = new class(null) extends BaseFilter {};

    expect($filter->removeEmptyInput([
        'page'       => 2,
        'page_limit' => 20,
        'keyword'    => 'scaffold',
        'zero'       => 0,
        'blank'      => '',
        'nil'        => null,
        'empty_list' => [],
    ]))->toBe([
        'keyword' => 'scaffold',
        'zero'    => 0,
    ]);
});

it('uses whereHasIn for unjoined relation filters', function () {
    $query = new class
    {
        public ?string $relation = null;

        public array $filtered = [];

        public function whereHasIn(string $relation, Closure $callback): void
        {
            $this->relation = $relation;
            $callback($this);
        }

        public function filter(array $input): void
        {
            $this->filtered = $input;
        }
    };

    $filter = new class($query, ['name' => 'Alice']) extends BaseFilter
    {
        public $relations = ['profile' => ['name']];
    };

    $filter->filterUnjoinedRelation('profile');

    expect($query->relation)->toBe('profile')
        ->and($query->filtered)->toBe(['name' => 'Alice']);
});
