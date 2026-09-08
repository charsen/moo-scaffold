<x-scaffold::shell :title="'Scaffold - 编辑' . $title" containerClass="is-docs is-docs-edit">
    <x-slot:aside>
        <div class="p-docs-nav">
            <div class="p-docs-nav__hd">
                <a class="p-docs-nav__hd-title" href="{{ route($route_prefix . '.index') }}">{{ $title }}</a>
            </div>
        </div>
    </x-slot:aside>
    <div class="p-docs-editor" id="local_markdown_editor"
         data-slug="{{ $slug }}" data-version="{{ $version }}"
         data-save="{{ route($route_prefix . '.save', [], false) }}"
         data-preview="{{ route($route_prefix . '.preview', [], false) }}"
         data-csrf="{{ csrf_token() }}">
        <div id="doc_frontmatter_error" hidden>
            <div class="p-docs-editor__lock" role="alert">
                <x-scaffold::icon name="warn" :size="15" />
                <span id="doc_frontmatter_error_text"></span>
            </div>
        </div>
        <div class="p-docs-editor__bar">
            <div class="p-docs-editor__path">
                <span class="p-docs-editor__base">{{ $slug }}</span>
                <span class="p-docs-editor__status" id="doc_save_status" role="status" aria-live="polite"></span>
            </div>
            <div class="p-docs-editor__tools">
                <a href="{{ $back }}" class="btn btn--ghost btn--sm">返回阅读</a>
                <button type="button" class="btn btn--primary btn--sm" id="doc_save" data-shortcut="save">
                    <x-scaffold::icon name="check" :size="13" /> 保存
                </button>
            </div>
        </div>
        <div class="p-docs-editor__panes">
            <div class="p-docs-editor__pane p-docs-editor__pane--edit">
                <textarea id="doc_content" class="p-docs-editor__textarea" aria-label="Markdown 正文" spellcheck="false" wrap="off"></textarea>
            </div>
            <div class="p-docs-editor__pane p-docs-editor__pane--preview">
                <article class="doc-article" id="doc_preview" aria-label="预览"></article>
            </div>
        </div>
    </div>
    <x-slot:scripts>
        <script type="application/json" id="local_markdown_raw" nonce="{{ $cspNonce ?? '' }}">@json($raw)</script>
        <script src="/vendor/scaffold/javascript/highlight.min.js"></script>
        <script src="/vendor/scaffold/javascript/pages/docs-center.js?v={{ @filemtime(public_path('vendor/scaffold/javascript/pages/docs-center.js')) ?: time() }}"></script>
        <script src="/vendor/scaffold/javascript/pages/local-markdown-editor.js?v={{ @filemtime(public_path('vendor/scaffold/javascript/pages/local-markdown-editor.js')) ?: time() }}"></script>
    </x-slot:scripts>
</x-scaffold::shell>
