@php
    // plan-52 文档中心阅读页:侧导航(aside) + 正文(default slot,整宽 article)。
    // 服务端渲染 MD→HTML(DocMarkdownRenderer);Mermaid 块已换成隔离 iframe,docs-center.js 喂图源。
    $shellTitle = 'Scaffold - 开发文档' . ($doc ? ' · ' . $doc['title'] : '');
@endphp

<x-scaffold::shell :title="$shellTitle" containerClass="is-docs">

    <x-slot:aside>
        @include('scaffold::docs._nav', ['tree' => $tree, 'current_key' => $current_key, 'locked' => $locked, 'src' => $src ?? null])
    </x-slot:aside>

    {{-- 空态(还没有任何文档)由目录主页(docs/home)承载;本页只管单篇阅读 + not_found --}}
    @if ($not_found)
        <div class="p-docs-reader">
            <div class="p-docs-reader__main p-docs-reader__main--center">
                <x-scaffold::empty title="文档不存在" desc="左侧选一篇，或它可能已被删除/改名（历史走 git）。">
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
                            <x-scaffold::badge tone="info" size="sm" title="扩展包文档，改动落包仓，commit 到该仓">📦 {{ $src }}</x-scaffold::badge>
                        @endif
                        <span class="p-docs-reader__group">{{ $doc['group'] }}</span>
                        <x-scaffold::icon name="chevron-right" :size="13" />
                        <span class="p-docs-reader__title">{{ $doc['title'] }}</span>
                    </div>
                    <div class="p-docs-reader__actions">
                        {{-- 正文字号 A− / A+(读类,生产只读也可用):docs-center.js 调 .doc-article 的 --doc-font-scale,偏好存 localStorage,跨文档/刷新记住 --}}
                        <div class="p-docs-reader__font" role="group" aria-label="正文字号">
                            <button type="button" class="btn btn--secondary btn--sm p-docs-reader__font-btn" id="doc_font_dec" aria-label="缩小正文字号">A−</button>
                            <button type="button" class="btn btn--secondary btn--sm p-docs-reader__font-btn" id="doc_font_inc" aria-label="放大正文字号">A+</button>
                        </div>
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

                {{-- 上一篇/下一篇:同源 all() 阅读顺序(全局编号序)的相邻两篇,跨组连续 --}}
                @if (($prev ?? null) || ($next ?? null))
                    <nav class="p-docs-reader__pager" aria-label="相邻文档">
                        @if ($prev ?? null)
                            <a class="p-docs-reader__pager-link" href="{{ route('docs.index', ['doc' => $prev['slug']] + (($src ?? null) ? ['src' => $src] : [])) }}">
                                <span class="p-docs-reader__pager-dir"><x-scaffold::icon name="chevron-left" :size="13" /> 上一篇 · {{ $prev['group'] }}</span>
                                <span class="p-docs-reader__pager-title">{{ $prev['title'] }}</span>
                            </a>
                        @else
                            <span class="p-docs-reader__pager-spacer"></span>
                        @endif
                        @if ($next ?? null)
                            <a class="p-docs-reader__pager-link p-docs-reader__pager-link--next" href="{{ route('docs.index', ['doc' => $next['slug']] + (($src ?? null) ? ['src' => $src] : [])) }}">
                                <span class="p-docs-reader__pager-dir">下一篇 · {{ $next['group'] }} <x-scaffold::icon name="chevron-right" :size="13" /></span>
                                <span class="p-docs-reader__pager-title">{{ $next['title'] }}</span>
                            </a>
                        @endif
                    </nav>
                @endif
            </div>

            {{-- 右侧目录:docs-center.js 从正文 h2/h3 现算 + scroll-spy;不足 2 个标题则保持 hidden --}}
            <aside class="p-docs-reader__toc" id="doc_toc" hidden>
                <div class="p-docs-toc__hd">目录</div>
                <nav class="p-docs-toc" id="doc_toc_list"></nav>
            </aside>
        </div>
    @endif

    <x-slot:scripts>
        {{-- highlight.js 必须在 docs-center.js **之前**加载:wireCodeBlocks 里读 window.hljs 给代码块上色 --}}
        <script src="/vendor/scaffold/javascript/highlight.min.js"></script>
        <script src="/vendor/scaffold/javascript/pages/docs-center.js?v={{ @filemtime(public_path('vendor/scaffold/javascript/pages/docs-center.js')) ?: time() }}"></script>
    </x-slot:scripts>

</x-scaffold::shell>
