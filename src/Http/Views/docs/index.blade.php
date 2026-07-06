@php
    // plan-52 文档中心阅读页:侧导航(aside) + 正文(default slot,整宽 article)。
    // 服务端渲染 MD→HTML(DocMarkdownRenderer);Mermaid 块已换成隔离 iframe,docs-center.js 喂图源。
    $shellTitle = 'Scaffold - 开发文档' . ($doc ? ' · ' . $doc['title'] : '');
@endphp

<x-scaffold::shell :title="$shellTitle" containerClass="is-docs">

    <x-slot:aside>
        @include('scaffold::docs._nav', ['tree' => $tree, 'current_key' => $current_key, 'locked' => $locked, 'src' => $src ?? null])
    </x-slot:aside>

    @if (! $has_docs)
        {{-- 空态也进正文卡(跟阅读页一致,不再裸浮在页面底色上) --}}
        <div class="p-docs-reader">
            <div class="p-docs-reader__main p-docs-reader__main--center">
                <x-scaffold::empty title="还没有开发文档"
                    desc="{{ $locked ? '生产环境为只读预览。文档在本地编辑后随 git 同步过来这里就会出现。' : '在 ' . $rel_base . '/ 下用 Markdown 写设计/流程文档,或点右上「新建」。支持 Mermaid 流程图 + 接口/数据库深链。' }}">
                    <x-slot:icon><x-scaffold::icon name="book" :size="24" /></x-slot:icon>
                    @unless ($locked)
                        <a href="{{ route('docs.edit') }}" class="btn btn--primary p-docs-empty__new">
                            <x-scaffold::icon name="plus" :size="15" /> 新建第一篇
                        </a>
                    @endunless
                </x-scaffold::empty>
            </div>
        </div>
    @elseif ($not_found)
        <div class="p-docs-reader">
            <div class="p-docs-reader__main p-docs-reader__main--center">
                <x-scaffold::empty title="文档不存在" desc="左侧选一篇,或它可能已被删除/改名(历史走 git)。">
                    <x-slot:icon><x-scaffold::icon name="warn" :size="24" /></x-slot:icon>
                </x-scaffold::empty>
            </div>
        </div>
    @else
        <div class="p-docs-reader">
            <div class="p-docs-reader__main">
                <div class="p-docs-reader__bar">
                    <div class="p-docs-reader__crumb">
                        @if ($src ?? null)
                            {{-- plan-53 出身徽标:该文档属扩展包,编辑落包仓(只读包不出现编辑按钮) --}}
                            <x-scaffold::badge tone="info" size="sm" title="扩展包文档,改动落包仓,commit 到该仓">📦 {{ $src }}</x-scaffold::badge>
                        @endif
                        <span class="p-docs-reader__group">{{ $doc['group'] }}</span>
                        <x-scaffold::icon name="chevron-right" :size="13" />
                        <span class="p-docs-reader__title">{{ $doc['title'] }}</span>
                    </div>
                    <div class="p-docs-reader__actions">
                        {{-- 正文宽度开关(读类,生产只读也可用):docs-center.js 切 .doc-article.is-wide,偏好存 localStorage --}}
                        <button type="button" class="btn btn--secondary btn--sm p-docs-reader__width" id="doc_width_toggle">限宽</button>
                        @unless ($locked || ! $src_writable)
                            <a href="{{ route('docs.edit', ['doc' => $doc['slug']] + (($src ?? null) ? ['src' => $src] : [])) }}" class="btn btn--secondary btn--sm p-docs-reader__edit">
                                <x-scaffold::icon name="edit" :size="14" /> 编辑
                            </a>
                        @endunless
                    </div>
                </div>

                <article class="doc-article" id="doc_article">
                    {!! $html !!}
                </article>
            </div>

            {{-- 右侧目录:docs-center.js 从正文 h2/h3 现算 + scroll-spy;不足 2 个标题则保持 hidden --}}
            <aside class="p-docs-reader__toc" id="doc_toc" hidden>
                <div class="p-docs-toc__hd">目录</div>
                <nav class="p-docs-toc" id="doc_toc_list"></nav>
            </aside>
        </div>
    @endif

    <x-slot:scripts>
        <script src="/vendor/scaffold/javascript/pages/docs-center.js?v={{ @filemtime(public_path('vendor/scaffold/javascript/pages/docs-center.js')) ?: time() }}"></script>
    </x-slot:scripts>

</x-scaffold::shell>
