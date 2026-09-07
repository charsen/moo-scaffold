<x-scaffold::shell title="Scaffold - 研发计划" containerClass="is-docs">
    <x-slot:aside>
        <div class="p-docs-nav">
            <div class="p-docs-nav__hd">
                <a class="p-docs-nav__hd-title" href="{{ route('plans.index') }}">研发计划 · {{ $total }}</a>
            </div>
            <x-scaffold::side-tree :groups="$groups" :current="$record['slug'] ?? null" :searchable="true" />
        </div>
    </x-slot:aside>
    <div class="p-docs-reader">
        <div class="p-docs-reader__main">
            @if ($record)
                <div class="p-docs-reader__bar">
                    <div class="p-docs-reader__crumb">
                        <span class="p-docs-reader__group">{{ $record['group'] }}</span>
                        <x-scaffold::icon name="chevron-right" :size="13" />
                        <span class="p-docs-reader__title">{{ $record['title'] }}</span>
                    </div>
                    <x-scaffold::badge tone="info" size="sm">只读</x-scaffold::badge>
                </div>
                <article class="doc-article" id="doc_article">{!! $html !!}</article>
            @else
                <x-scaffold::empty title="暂无研发计划" desc="请在配置的 plans 目录中保存 Markdown 研发计划。" />
            @endif
        </div>
        <aside class="p-docs-reader__toc" id="doc_toc" hidden>
            <div class="p-docs-toc__hd">目录</div>
            <nav class="p-docs-toc" id="doc_toc_list"></nav>
        </aside>
    </div>
    <x-slot:scripts>
        <script src="/vendor/scaffold/javascript/highlight.min.js"></script>
        <script src="/vendor/scaffold/javascript/pages/docs-center.js?v={{ @filemtime(public_path('vendor/scaffold/javascript/pages/docs-center.js')) ?: time() }}"></script>
    </x-slot:scripts>
</x-scaffold::shell>
