<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Controllers;

use Illuminate\View\View;
use Mooeen\Scaffold\Http\Requests\Plans\ReadRequest;
use Mooeen\Scaffold\Support\Markdown\PlansMarkdownRenderer;
use Mooeen\Scaffold\Support\PlansRepository;

class PlansController extends Controller
{
    public function index(ReadRequest $request, PlansRepository $repository, PlansMarkdownRenderer $renderer): View
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
            'record' => $record,
            'html'   => $record === null ? '' : $renderer->render($record['body'], $record['slug'], array_column($records, 'slug')),
            'groups' => array_values($groups),
            'total'  => count($records),
        ]);
    }
}
