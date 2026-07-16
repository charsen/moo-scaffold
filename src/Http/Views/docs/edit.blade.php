@php
    // plan-52 文档编辑器(本地写;生产/只读 = 整页灰 + 红条)。textarea + 防抖服务端预览(复用
    // DocMarkdownRenderer 单一真源)。引用 picker 插 shortcode。Mermaid 预览走 docs-center.js。
    $shellTitle = 'Scaffold - 文档编辑' . ($is_new ? ' · 新建' : ' · ' . $current_slug);
@endphp

<x-scaffold::shell :title="$shellTitle" containerClass="is-docs is-docs-edit">

    <x-slot:aside>
        @include('scaffold::docs._nav', ['tree' => $tree, 'current_key' => $current_key, 'locked' => $locked, 'src' => $src ?? null])
    </x-slot:aside>

    <div class="p-docs-editor" data-locked="{{ $locked ? 'true' : 'false' }}">
        @if ($locked)
            <div class="p-docs-editor__lock" role="alert">
                <x-scaffold::icon name="lock" :size="15" />
                {{ $is_prod ? '生产环境为只读预览，文档编辑已停用（团队在本地编辑后随 git 同步）。' : '当前为强制只读模式（SCAFFOLD_CONFIG_READONLY），文档编辑已停用。' }}
            </div>
        @endif

        <div class="p-docs-editor__bar">
            <div class="p-docs-editor__path">
                {{-- plan-53 出身:新建 + 多可写源时给下拉选落点(host / 软链包);其余情况静态显示当前源根 --}}
                @if ($is_new && count($writable_sources) > 1)
                    <select id="doc_src" class="p-docs-editor__src" title="文档落点（host 或软链扩展包仓）">
                        @foreach ($writable_sources as $ws)
                            <option value="{{ $ws['key'] }}" @selected(($src ?? null) === ($ws['key'] === '' ? null : $ws['key']))>{{ $ws['label'] }}/</option>
                        @endforeach
                    </select>
                @else
                    <span class="p-docs-editor__base">{{ $rel_base }}/</span>
                @endif
                <input type="text" id="doc_slug" class="p-docs-editor__slug" value="{{ $current_slug }}"
                       placeholder="市场/订单评价流程" autocomplete="off" spellcheck="false" {{ $is_new ? '' : 'readonly' }}>
                <span class="p-docs-editor__ext">.md</span>
                <span class="p-docs-editor__status" id="doc_save_status" aria-live="polite"></span>
            </div>
            <div class="p-docs-editor__tools">
                <button type="button" class="btn btn--secondary btn--sm" id="doc_ins_api">
                    <x-scaffold::icon name="send" :size="13" /> 接口引用
                </button>
                <button type="button" class="btn btn--secondary btn--sm" id="doc_ins_db">
                    <x-scaffold::icon name="database" :size="13" /> 数据库引用
                </button>
                <button type="button" class="btn btn--secondary btn--sm" id="doc_ins_mermaid">
                    <x-scaffold::icon name="protocol" :size="13" /> 流程图
                </button>
                @unless ($is_new)
                    <button type="button" class="btn btn--danger btn--sm" id="doc_delete">
                        <x-scaffold::icon name="trash" :size="13" /> 删除
                    </button>
                @endunless
                <a href="{{ route('docs.index', ($is_new ? [] : ['doc' => $current_slug]) + (($src ?? null) ? ['src' => $src] : [])) }}"
                   id="doc_back" class="btn btn--ghost btn--sm p-designer-no-lock">返回阅读</a>
                <button type="button" class="btn btn--primary btn--sm" id="doc_save" data-shortcut="save">
                    <x-scaffold::icon name="check" :size="13" /> 保存
                </button>
            </div>
        </div>

        <div class="p-docs-editor__panes">
            <div class="p-docs-editor__pane p-docs-editor__pane--edit">
                <textarea id="doc_content" class="p-docs-editor__textarea" spellcheck="false" wrap="off">{{ $raw }}</textarea>
            </div>
            <div class="p-docs-editor__pane p-docs-editor__pane--preview">
                <article class="doc-article" id="doc_preview"></article>
            </div>
        </div>
    </div>

    {{-- 引用 picker（接口 / 数据库）。纯 jQuery 驱动,不用 alpine modal(避开 bare directive 坑) --}}
    <div class="p-docs-picker" id="doc_picker" hidden>
        <div class="p-docs-picker__backdrop" id="doc_picker_backdrop"></div>
        <div class="p-docs-picker__dialog" role="dialog" aria-modal="true" aria-labelledby="doc_picker_title">
            <div class="p-docs-picker__hd">
                <span id="doc_picker_title">插入引用</span>
                <button type="button" class="p-docs-picker__close" id="doc_picker_close" aria-label="关闭">
                    <x-scaffold::icon name="close" :size="16" />
                </button>
            </div>
            <input type="text" class="p-docs-picker__search" id="doc_picker_search" placeholder="搜索…" autocomplete="off">
            {{-- 顶部模式条:左=插入格式(正文 chip / 流程图 click),右=接口类型(仅接口引用显示)。分段控件,固定不随列表滚 --}}
            <div class="p-docs-picker__bar">
                <div class="p-docs-picker__seg" id="doc_picker_fmt">
                    <button type="button" class="p-docs-picker__segbtn is-active" data-fmt="chip">正文 chip</button>
                    <button type="button" class="p-docs-picker__segbtn" data-fmt="click">流程图 click</button>
                </div>
                <div class="p-docs-picker__seg" id="doc_picker_apitype" hidden>
                    <button type="button" class="p-docs-picker__segbtn is-active" data-type="debug">接口调试</button>
                    <button type="button" class="p-docs-picker__segbtn" data-type="api">接口文档</button>
                </div>
            </div>
            <div class="p-docs-picker__list" id="doc_picker_list" role="listbox"></div>
        </div>
    </div>

    <x-slot:scripts>
        <script nonce="{{ $cspNonce ?? '' }}">
            window.ScaffoldDocsEditor = {
                routes: {
                    preview: '{{ route('docs.preview', [], false) }}',
                    save:    '{{ route('docs.save', [], false) }}',
                    remove:  '{{ route('docs.delete', [], false) }}',
                    picker:  '{{ route('docs.picker', [], false) }}',
                    index:   '{{ route('docs.index', [], false) }}',
                    edit:    '{{ route('docs.edit', [], false) }}'
                },
                isNew:    {{ $is_new ? 'true' : 'false' }},
                locked:   {{ $locked ? 'true' : 'false' }},
                slug:     @json($current_slug),
                src:      @json($src ?? null)
            };
            $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' } });
        </script>
        <script src="/vendor/scaffold/javascript/pages/docs-center.js?v={{ @filemtime(public_path('vendor/scaffold/javascript/pages/docs-center.js')) ?: time() }}"></script>
        <script src="/vendor/scaffold/javascript/pages/docs-editor.js?v={{ @filemtime(public_path('vendor/scaffold/javascript/pages/docs-editor.js')) ?: time() }}"></script>
    </x-slot:scripts>

</x-scaffold::shell>
