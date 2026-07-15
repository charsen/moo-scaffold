<?php declare(strict_types=1);

use Mooeen\Scaffold\Foundation\TableColumnsCollection;

it('passes table column widths through without appending units', function () {
    $columns = TableColumnsCollection::makeColumns([
        'numeric'        => ['width' => 100, 'minWidth' => 120],
        'numeric_string' => ['width' => '300', 'minWidth' => '320'],
        'css_string'     => ['width' => '300px', 'minWidth' => 'auto'],
        'empty'          => ['width' => null, 'minWidth' => null],
    ])->toArray(request());

    $byField = collect($columns)->keyBy('field');

    expect($byField['numeric']['width'])->toBe(100)
        ->and($byField['numeric']['minWidth'])->toBe(120)
        ->and($byField['numeric_string']['width'])->toBe('300')
        ->and($byField['numeric_string']['minWidth'])->toBe('320')
        ->and($byField['css_string']['width'])->toBe('300px')
        ->and($byField['css_string']['minWidth'])->toBe('auto')
        ->and($byField['empty']['width'])->toBeNull()
        ->and($byField['empty']['minWidth'])->toBeNull();
});
