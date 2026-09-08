<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Mooeen\Scaffold\Http\Requests\LocalMarkdown\EditRequest;
use Mooeen\Scaffold\Http\Requests\LocalMarkdown\PreviewRequest;
use Mooeen\Scaffold\Http\Requests\LocalMarkdown\SaveRequest;
use Mooeen\Scaffold\Http\Requests\ReleaseRecords\ReadRequest;
use Mooeen\Scaffold\Support\LocalMarkdownEditor;
use Mooeen\Scaffold\Support\Markdown\DocMarkdownRenderer;
use Mooeen\Scaffold\Support\RecordMarkdownDocument;
use Mooeen\Scaffold\Support\ReleaseRecordsRepository;

class ReleaseRecordsController extends Controller
{
    public function index(ReadRequest $request, ReleaseRecordsRepository $repository, DocMarkdownRenderer $renderer, LocalMarkdownEditor $editor): View
    {
        $records = $repository->all();
        $slug    = $request->validated('record');
        $record  = $slug === null ? ($records[0] ?? null) : collect($records)->firstWhere('slug', $slug);
        abort_if($slug !== null && $record === null, 404);

        $groups = [];
        foreach ($records as $item) {
            $date  = $item['date'] ?: '未标注日期';
            $group = $item['group'] === $date ? $date : $date . ' · ' . $item['group'];
            $groups[$group] ??= ['key' => $group, 'label' => $group, 'items' => []];
            $groups[$group]['items'][] = [
                'key'   => $item['slug'],
                'label' => $item['title'],
                'href'  => route('release-records.index', ['record' => $item['slug']]),
            ];
        }

        return $this->view('release-records.index', [
            'writable' => $record !== null && $editor->canEdit('release_records', $record['slug']),
            'record'   => $record,
            'html'     => $record === null ? '' : $renderer->render($record['body']),
            'groups'   => array_values($groups),
            'total'    => count($records),
        ]);
    }

    public function edit(EditRequest $request, LocalMarkdownEditor $editor): View
    {
        $slug = $request->validated('slug');
        $raw  = $editor->read('release_records', $slug);

        return $this->view('local-markdown.edit', [
            'title'        => '发版日志',
            'slug'         => $slug,
            'raw'          => $raw,
            'version'      => hash('sha256', $raw),
            'route_prefix' => 'release-records',
            'back'         => route('release-records.index', ['record' => $slug]),
        ]);
    }

    public function save(SaveRequest $request, LocalMarkdownEditor $editor): JsonResponse
    {
        $data    = $request->validated();
        $version = $editor->save('release_records', $data['slug'], $data['content'], $data['version']);

        return response()->json(['ok' => true, 'version' => $version]);
    }

    public function preview(PreviewRequest $request, LocalMarkdownEditor $editor, DocMarkdownRenderer $renderer, RecordMarkdownDocument $markdown): JsonResponse
    {
        $data = $request->validated();
        $editor->read('release_records', $data['slug']);
        $document = $markdown->parse($data['content'], $data['slug'], '');
        $body     = $document['body'];

        return response()->json(['html' => $renderer->render($body), 'error' => $document['error']]);
    }
}
