<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Controllers;

use Illuminate\View\View;
use Mooeen\Scaffold\Http\Requests\ReleaseRecords\ReadRequest;
use Mooeen\Scaffold\Support\Markdown\DocMarkdownRenderer;
use Mooeen\Scaffold\Support\ReleaseRecordsRepository;

class ReleaseRecordsController extends Controller
{
    public function index(ReadRequest $request, ReleaseRecordsRepository $repository, DocMarkdownRenderer $renderer): View
    {
        $records = $repository->all();
        $slug    = $request->validated('record');
        $record  = $slug === null ? ($records[0] ?? null) : collect($records)->firstWhere('slug', $slug);
        abort_if($slug !== null && $record === null, 404);

        $groups = [];
        foreach ($records as $item) {
            $date = $item['date'] ?: '未标注日期';
            $groups[$date] ??= ['key' => $date, 'label' => $date, 'items' => []];
            $groups[$date]['items'][] = [
                'key'   => $item['slug'],
                'label' => $item['title'],
                'href'  => route('release-records.index', ['record' => $item['slug']]),
            ];
        }

        return $this->view('release-records.index', [
            'record' => $record,
            'html'   => $record === null ? '' : $renderer->render($record['body']),
            'groups' => array_values($groups),
            'total'  => count($records),
        ]);
    }
}
