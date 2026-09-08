<?php

declare(strict_types=1);

use Mooeen\Scaffold\Support\DocsRepository;
use Mooeen\Scaffold\Support\MarkdownFrontmatter;
use Mooeen\Scaffold\Support\RecordMarkdownDocument;

it('strips empty frontmatter and BOM only from the parsed view', function (string $raw) {
    $result = app(MarkdownFrontmatter::class)->parse($raw);
    expect($result)->toBe(['meta' => [], 'body' => '# Body', 'error' => null]);
    expect(app(DocsRepository::class)->parseRaw($raw))->toBe(['meta' => [], 'body' => '# Body']);
})->with([
    "---\n---\n# Body",
    "---\n\n---\n# Body",
    "\xEF\xBB\xBF---\r\n---\r\n# Body",
    "\xEF\xBB\xBF# Body",
    "---  \n--- \n# Body",
]);

it('recognizes a BOM header and an EOF closing delimiter', function () {
    $raw = "\xEF\xBB\xBF---\r\ntitle: Metadata\r\n---";
    expect(app(MarkdownFrontmatter::class)->parse($raw))->toBe(['meta' => ['title' => 'Metadata'], 'body' => '', 'error' => null]);
    expect(app(DocsRepository::class)->parseRaw($raw))->toBe(['meta' => ['title' => 'Metadata'], 'body' => '']);
});

it('reports malformed headers without dropping the body', function (string $raw, string $body, string $error) {
    $result = app(MarkdownFrontmatter::class)->parse($raw);
    expect($result['body'])->toBe($body)->and($result['error'])->toContain($error);
})->with([
    ["---\ntitle: [\n---\n# Body", '# Body', '第 3 行'],
    ["---\nscalar\n---\n# Body", '# Body', '键值对象'],
    ["---\n- list\n---\n# Body", '# Body', '键值对象'],
    ["---\ntitle: Missing end\n# Body", "---\ntitle: Missing end\n# Body", '结束标记'],
    ["---\ntitle: Missing end\n---oops\n# Body", "---\ntitle: Missing end\n---oops\n# Body", '结束标记'],
]);

it('only accepts integer order values', function (string $value) {
    $raw    = "---\norder: {$value}\n---\n# Body";
    $result = app(RecordMarkdownDocument::class)->parse($raw, 'file.md', 'Default');
    expect($result['order'])->toBe(999)->and($result['error'])->toContain('order 必须是整数');
})->with(['true', 'false', '1.0', '1.5', '[]', '{}', '"1.0"', '"not-an-order"']);

it('accepts explicit zero negative and quoted integer order', function (string $value, int $expected) {
    $result = app(RecordMarkdownDocument::class)->parse("---\norder: {$value}\n---\n# Body", 'file.md', 'Default');
    expect($result['order'])->toBe($expected)->and($result['error'])->toBeNull();
})->with([['0', 0], ['-10', -10], ['"20"', 20]]);
