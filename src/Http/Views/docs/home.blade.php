{{-- 文档目录主页(裸 /docs 落这里;阅读走 ?doc= 深链):按源(host / 📦包)分节 → 组块 → 行。
     组内行拖 + 组块整体拖(跨组不允许 —— 那要改 frontmatter group:,不在本页职责),
     松手 POST docs/reorder 提交该源全量 slug 顺序,后端全局编号 10/20/30 写回各篇 order:。
     只读源(生产/强制只读/vendor 拷贝包)照列,不出把手。--}}
<x-scaffold::shell title="Scaffold - 开发文档 · 目录" containerClass="is-docs">

    <x-slot:aside>
        @include('scaffold::docs._nav', ['tree' => $tree, 'current_key' => null, 'locked' => $locked, 'src' => null])
    </x-slot:aside>

    <div class="p-docs-home">
        {{-- 顶部并成一行:标题 + 全文搜索 + 计数(hero 卡 + 玩法说明太占纵向空间,2026-07-16 user 反馈;
             拖拽提示由把手 title 承担,只读态给徽标)。搜索:标题/slug/正文跨全源现扫(docs-home.js
             防抖请求 docs.search,无索引);有关键词时隐藏下方分区,结果链接带 ?hl= 让阅读页定位首个命中 --}}
        <div class="p-docs-home__bar">
            <h2 class="p-docs-home__bar-title"><x-scaffold::icon name="book" :size="18" /> 文档目录</h2>
            @if ($total > 0)
                <input type="search" id="docs_home_search" class="input" autocomplete="off"
                       placeholder="全文搜索：标题 / 路径 / 正文…（≥2 字）" aria-label="全文搜索">
            @endif
            <span class="p-docs-home__bar-meta">
                @if ($locked)
                    <x-scaffold::badge tone="warning" size="sm" title="生产/只读模式，排序与编辑不可用">只读</x-scaffold::badge>
                @endif
                <strong>{{ number_format($total) }}</strong> 篇文档
            </span>
        </div>
        <div id="docs_home_results" class="p-docs-home__results" hidden></div>

        @if ($total === 0)
            <div class="p-docs-home__empty">
                <x-scaffold::empty title="还没有开发文档"
                    desc="{{ $locked ? '生产环境为只读预览。文档在本地编辑后随 git 同步过来这里就会出现。' : '在 ' . ($sections[0]['rel_base'] ?? 'scaffold/docs') . '/ 下用 Markdown 写设计/流程文档，或点左上「新建」。支持 Mermaid 流程图 + 接口/数据库深链。' }}">
                    <x-slot:icon><x-scaffold::icon name="book" :size="24" /></x-slot:icon>
                    @unless ($locked)
                        <a href="{{ route('docs.edit') }}" class="btn btn--primary p-docs-empty__new">
                            <x-scaffold::icon name="plus" :size="15" /> 新建第一篇
                        </a>
                    @endunless
                </x-scaffold::empty>
            </div>
        @else
            @foreach ($sections as $sec)
                @php $canDrag = $sec['writable']; @endphp
                <section class="p-docs-home__source"
                         data-reorder-src="{{ $sec['key'] ?? '' }}"
                         data-reorder-enabled="{{ $canDrag ? '1' : '0' }}">
                    <div class="p-docs-home__source-hd">
                        @if ($sec['key'] !== null)
                            <x-scaffold::badge tone="info" size="sm" title="扩展包文档，改动落包仓">📦 {{ $sec['key'] }}</x-scaffold::badge>
                        @else
                            <span class="p-docs-home__source-name">Host</span>
                        @endif
                        <span class="p-docs-home__source-path">{{ $sec['rel_base'] }}/</span>
                        @unless ($canDrag)
                            <x-scaffold::badge tone="warning" size="sm">只读</x-scaffold::badge>
                        @endunless
                    </div>

                    <div class="p-docs-home__groups">
                        @foreach ($sec['groups'] as $grp)
                            <div class="p-docs-home__group" data-group="{{ $grp['label'] }}">
                                {{-- 拖拽把手只在六点图标上(与行交互同构):整头可拖时 cursor 与热区不一致
                                     且组名文字没法选中复制(2026-07-15 user 反馈) --}}
                                <div class="p-docs-home__group-hd">
                                    @if ($canDrag)
                                        <span class="p-docs-home__grip" data-drag-handle="group" title="拖动调整分组顺序"><x-scaffold::icon name="grip" :size="14" /></span>
                                    @endif
                                    <span class="p-docs-home__group-name">{{ $grp['label'] }}</span>
                                    <span class="p-docs-home__group-count">{{ count($grp['items']) }}</span>
                                </div>
                                <ul class="p-docs-home__rows">
                                    @foreach ($grp['items'] as $doc)
                                        @php $q = ['doc' => $doc['slug']] + ($sec['key'] !== null ? ['src' => $sec['key']] : []); @endphp
                                        <li class="p-docs-home__row" data-slug="{{ $doc['slug'] }}">
                                            @if ($canDrag)
                                                <span class="p-docs-home__grip" data-drag-handle="row" title="拖动调整顺序">
                                                    <x-scaffold::icon name="grip" :size="14" />
                                                </span>
                                            @endif
                                            {{-- 序号 = frontmatter order(全局编号);未设(999 默认)显「–」,首次拖动后落号 --}}
                                            <span class="p-docs-home__order">{{ $doc['order'] >= 999 ? '–' : $doc['order'] }}</span>
                                            {{-- slug 列砍掉:目录/文件名与标题、组名高度重复,纯噪音(2026-07-16 user 反馈) --}}
                                            <a class="p-docs-home__title" href="{{ route('docs.index', $q) }}">{{ $doc['title'] }}</a>
                                            @if (! empty($doc['tags']))
                                                <span class="p-docs-home__tags">
                                                    @foreach ($doc['tags'] as $tag)
                                                        <x-scaffold::badge size="sm">{{ $tag }}</x-scaffold::badge>
                                                    @endforeach
                                                </span>
                                            @endif
                                            <span class="p-docs-home__mtime">{{ date('Y-m-d H:i', $doc['mtime']) }}</span>
                                            @if ($canDrag)
                                                <a class="p-docs-home__edit" href="{{ route('docs.edit', $q) }}" title="编辑">
                                                    <x-scaffold::icon name="edit" :size="14" />
                                                </a>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach
        @endif
    </div>

    <x-slot:scripts>
        <script nonce="{{ $cspNonce ?? '' }}">
            window.ScaffoldDocsHome = {
                routes: {
                    reorder: '{{ route('docs.reorder', [], false) }}',
                    search:  '{{ route('docs.search', [], false) }}'
                },
                locked: {{ $locked ? 'true' : 'false' }}
            };
            $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' } });
        </script>
        <script src="/vendor/scaffold/javascript/pages/docs-home.js?v={{ @filemtime(public_path('vendor/scaffold/javascript/pages/docs-home.js')) ?: time() }}"></script>
    </x-slot:scripts>

</x-scaffold::shell>
