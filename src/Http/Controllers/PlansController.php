<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Mooeen\Scaffold\Http\Requests\LocalMarkdown\EditRequest;
use Mooeen\Scaffold\Http\Requests\LocalMarkdown\PreviewRequest;
use Mooeen\Scaffold\Http\Requests\LocalMarkdown\SaveRequest;
use Mooeen\Scaffold\Http\Requests\Plans\ReadRequest;
use Mooeen\Scaffold\Support\LocalMarkdownEditor;
use Mooeen\Scaffold\Support\Markdown\PlansMarkdownRenderer;
use Mooeen\Scaffold\Support\PlansRepository;
use Mooeen\Scaffold\Support\RecordMarkdownDocument;

class PlansController extends Controller
{
    public function index(ReadRequest $request, PlansRepository $repository, PlansMarkdownRenderer $renderer, LocalMarkdownEditor $editor): View
    {
        $records = $repository->all();
        $slug    = $request->validated('doc');
        $record  = $slug === null ? ($records[0] ?? null) : collect($records)->firstWhere('slug', $slug);
        abort_if($slug !== null && $record === null, 404);

        $groups = [];
        foreach ($records as $item) {
            $group = $item['group'];
            $groups[$group] ??= ['key' => $group, 'label' => $group, 'items' => []];
            $groups[$group]['items'][] = [
                'key'   => $item['slug'],
                'label' => $item['title'],
                'href'  => route('plans.index', ['doc' => $item['slug']]),
            ];
        }

        return $this->view('plans.index', [
            'writable' => $record !== null && $editor->canEdit('plans', $record['slug']),
            'record'   => $record,
            'html'     => $record === null ? '' : $renderer->render($record['body'], $record['slug'], array_column($records, 'slug')),
            'groups'   => array_values($groups),
            'total'    => count($records),
        ]);
    }

    public function edit(EditRequest $request, LocalMarkdownEditor $editor): View
    {
        $slug = $request->validated('slug');
        $raw  = $editor->read('plans', $slug);

        return $this->view('local-markdown.edit', [
            'title'        => '研发计划',
            'slug'         => $slug,
            'raw'          => $raw,
            'version'      => hash('sha256', $raw),
            'route_prefix' => 'plans',
            'back'         => route('plans.index', ['doc' => $slug]),
        ]);
    }

    public function save(SaveRequest $request, LocalMarkdownEditor $editor): JsonResponse
    {
        $data    = $request->validated();
        $version = $editor->save('plans', $data['slug'], $data['content'], $data['version']);

        return response()->json(['ok' => true, 'version' => $version]);
    }

    public function preview(PreviewRequest $request, LocalMarkdownEditor $editor, PlansMarkdownRenderer $renderer, PlansRepository $repository, RecordMarkdownDocument $markdown): JsonResponse
    {
        $data = $request->validated();
        $editor->read('plans', $data['slug']);
        $document = $markdown->parse($data['content'], $data['slug'], '');
        $body     = $document['body'];

        return response()->json(['html' => $renderer->render($body, $data['slug'], array_column($repository->all(), 'slug')), 'error' => $document['error']]);
    }
}
