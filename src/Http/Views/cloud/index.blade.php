<x-scaffold::shell title="Scaffold - 云端汇聚" containerClass="is-route">
@php
    $editable = ! $is_prod && ! $is_readonly;

    // —— hero KPI / 概览(全部来自现有 $buffers / 标量,零新增数据)——
    $openTotal    = 0;
    $pendingTotal = 0;
    $pendingKnown = false;
    $lastCursor   = null;
    foreach ($buffers as $b) {
        $openTotal += (int) $b['open'];
        if (! is_null($b['pending'])) {
            $pendingTotal += (int) $b['pending'];
            $pendingKnown = true;
        }
        if (! empty($b['cursor'])) {
            $c = (string) $b['cursor'];
            if (is_null($lastCursor) || $c > $lastCursor) {
                $lastCursor = $c;
            }
        }
    }
    $lastSyncFmt = $lastCursor
        ? \Illuminate\Support\Str::limit(str_replace('T', ' ', $lastCursor), 16, '')
        : '—';

    // 配置明细折成三列(4 + 4 + 2),避免长条、提升密度
    $cfgChunks = array_chunk($config_rows, (int) ceil(max(1, count($config_rows)) / 3));
    $versionItemsCount = count($version_info['cards'] ?? [])
        + count($version_info['switches'] ?? [])
        + count($version_info['details'] ?? []);
@endphp

<section class="p-cloud-page" @if (! $editable) data-locked="true" @endif>

    <x-scaffold::hero icon="cloud" title="云端汇聚">
        <x-slot:badges>
            @if ($configured)
                <x-scaffold::badge tone="success">已接入</x-scaffold::badge>
            @elseif ($enabled)
                <x-scaffold::badge tone="warning">未配 URL / Token</x-scaffold::badge>
            @else
                <x-scaffold::badge tone="neutral">未启用</x-scaffold::badge>
            @endif
        </x-slot:badges>
        <x-slot:meta>
            <span>已采集 <strong>{{ number_format($openTotal) }}</strong></span>
            @if ($pendingKnown)
                <span>待推 <strong>{{ number_format($pendingTotal) }}</strong></span>
            @endif
            <span>本地回收 <strong>{{ $retention }}</strong> 天</span>
            <span>自动调度 <strong>{{ $schedule ? '开' : '关' }}</strong></span>
            <span>上次同步 <strong>{{ $lastSyncFmt }}</strong></span>
        </x-slot:meta>
    </x-scaffold::hero>

    {{-- 说明行:从 hero 拆出来独立成行——说明文字占满左侧可用宽度(不再被 hero 的 meta 卡宽、
         无谓换行),「进入 Moo Scaffold Cloud」靠右、与说明底对齐。 --}}
    <div class="p-cloud-intro">
        <p class="p-cloud-intro__desc">
            本地仅作<strong>临时采集缓冲</strong>，运行时错误 / 慢 SQL 经 <code>moo:cloud:push</code>
            汇聚到 <strong>Moo Scaffold Cloud</strong> 集中查看 + 处置。<br>
            处置（解决 / 删除）统一在 Moo Scaffold Cloud，本页只看本地缓冲与手动推送。
        </p>
        @if ($base_url !== '')
            <a href="{{ $base_url }}/app" target="_blank" rel="noopener noreferrer" class="btn btn--secondary btn--sm p-cloud-no-lock">进入 Moo Scaffold Cloud →</a>
        @endif
    </div>

    {{-- 推送结果提示:紧贴「本地缓冲」上方(推送针对的就是本地缓冲) --}}
    @if (! empty($flash_message))
        <div class="p-cloud-flash p-cloud-flash--ok" role="status">{{ $flash_message }}</div>
    @endif
    @if (! empty($flash_error))
        <div class="p-cloud-flash p-cloud-flash--err" role="alert">{{ $flash_error }}</div>
    @endif

    {{-- ① 本地缓冲(live):缓冲量 + 待推 + 立即推送 —— 日常最常看,2026-06-19 提到接入配置之上 --}}
    <section class="p-cloud-section">
        <header class="p-cloud-section__hd">
            <h3 class="p-cloud-section__title">本地缓冲</h3>
            <span class="p-cloud-section__sub">推送成功后回收 · open 保留 {{ $retention }} 天作聚合锚点</span>
            @if ($editable && $configured)
                <form method="POST" action="{{ route('cloud.push') }}" class="p-cloud-pushform">
                    @csrf
                    <x-scaffold::btn type="submit" variant="primary" size="sm">立即推送</x-scaffold::btn>
                </form>
            @endif
        </header>

        <div class="p-cloud-buffers">
            @foreach ($buffers as $type => $b)
                @php
                    $cursorFmt = $b['cursor'] ? \Illuminate\Support\Str::limit(str_replace('T', ' ', (string) $b['cursor']), 16, '') : null;
                    $pendingActive = ! is_null($b['pending']) && $b['pending'] > 0;
                @endphp
                <article class="p-cloud-card p-cloud-buffer @if ($pendingActive) is-pending @endif">
                    <header class="p-cloud-card__hd">
                        <h2 class="section-title-with-icon">
                            <span class="section-icon-box" aria-hidden="true"><x-scaffold::icon :name="$b['icon']" :size="18" /></span>
                            {{ $b['label'] }}
                        </h2>
                    </header>

                    <div class="p-cloud-card__bd">
                        {{-- 空态须同时满足 open 空 + 无待推:推送的是 open+resolved 两桶,
                             resolved 有存量时 pending>0,只看 open 会谎报"缓冲为空"而推送
                             却真能推出东西(2026-06-10 修) --}}
                        @if ($b['open'] === 0 && ! $pendingActive)
                            <div class="p-cloud-empty">
                                <x-scaffold::icon name="check" :size="18" />
                                <span>本地缓冲为空 — 没有待汇聚的{{ $b['label'] }}</span>
                            </div>
                        @else
                            {{-- 云端化后本地只是待推缓冲:open(缓冲量)+ 待推(未上云)。处置在 Moo Scaffold Cloud。 --}}
                            <div class="p-cloud-stats">
                                <div class="p-cloud-stat is-open">
                                    <strong>{{ number_format($b['open']) }}</strong>
                                    <span>已采集 · open</span>
                                </div>
                                @if (! is_null($b['pending']))
                                    <div class="p-cloud-stat @if ($pendingActive) is-pending @endif">
                                        <strong>{{ number_format($b['pending']) }}</strong>
                                        <span>待推 · pending</span>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>

                    <footer class="p-cloud-buffer__foot">
                        <span class="p-cloud-buffer__sync">
                            <x-scaffold::icon name="refresh" :size="12" />
                            上次同步至 {{ $cursorFmt ?? '—（未推过）' }}
                        </span>
                        <code class="p-cloud-buffer__path">scaffold/{{ $b['dir'] }}/</code>
                    </footer>
                </article>
            @endforeach
        </div>
    </section>

    {{-- ② 运行环境:与心跳 meta 同口径 + 本地轻量版本/驱动信息,不做实时服务探测 --}}
    <section class="p-cloud-card p-cloud-version">
        <header class="p-cloud-card__hd">
            <h2 class="section-title-with-icon">
                <span class="section-icon-box" aria-hidden="true"><x-scaffold::icon name="code" :size="18" /></span>
                运行环境
            </h2>
            <span class="p-cloud-card__hd-aux">随心跳上报 · {{ $versionItemsCount }} 项</span>
        </header>

        <div class="p-cloud-card__bd">
            <div class="p-cloud-version-tiles">
                @foreach (($version_info['cards'] ?? []) as $card)
                    <article class="p-cloud-version-tile is-{{ $card['tone'] ?? 'neutral' }}">
                        <span class="p-cloud-version-tile__label">{{ $card['label'] }}</span>
                        <strong class="p-cloud-version-tile__value @if (! empty($card['mono'])) is-mono @endif">{{ $card['value'] }}</strong>
                        <span class="p-cloud-version-tile__hint @if (! empty($card['mono'])) is-mono @endif">{{ $card['hint'] }}</span>
                    </article>
                @endforeach
            </div>

            <div class="p-cloud-version-lower">
                <section class="p-cloud-version-block">
                    <h3 class="p-cloud-version-block__title">心跳开关</h3>
                    <div class="p-cloud-version-switches">
                        @foreach (($version_info['switches'] ?? []) as $row)
                            <span class="p-cloud-version-switch is-{{ $row['tone'] }}" title="{{ $row['key'] }}">
                                <span>{{ $row['label'] }}</span>
                                <strong>{{ $row['value'] }}</strong>
                            </span>
                        @endforeach
                    </div>
                </section>

                <section class="p-cloud-version-block">
                    <h3 class="p-cloud-version-block__title">运行配置</h3>
                    <dl class="p-cloud-version-mini">
                        @foreach (($version_info['details'] ?? []) as $row)
                            <div class="p-cloud-version-mini__row">
                                <dt>
                                    <span>{{ $row['label'] }}</span>
                                    <code>{{ $row['key'] }}</code>
                                </dt>
                                <dd class="@if (! empty($row['mono'])) is-mono @endif">{{ $row['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>
            </div>
        </div>
    </section>

    {{-- ③ 接入配置(次要):live 缓冲之后 —— 静态接入参数,设好后偶尔查看 --}}
    <section class="p-cloud-card p-cloud-config">
        <header class="p-cloud-card__hd">
            <h2 class="section-title-with-icon">
                <span class="section-icon-box" aria-hidden="true"><x-scaffold::icon name="settings" :size="18" /></span>
                接入配置
            </h2>
            <span class="p-cloud-card__hd-aux">{{ count($config_rows) }} 项</span>
        </header>

        <div class="p-cloud-card__bd">
            <div class="p-cloud-detail">
                @foreach ($cfgChunks as $chunk)
                    <dl class="p-cloud-detail__col">
                        @foreach ($chunk as $row)
                            <div class="p-cloud-detail__row">
                                <dt class="p-cloud-detail__label">
                                    <span class="p-cloud-detail__name">{{ $row['label'] }}</span>
                                    <code class="p-cloud-detail__key">{{ $row['key'] }}</code>
                                </dt>
                                <dd class="p-cloud-detail__value @if ($row['tone']) is-{{ $row['tone'] }} @endif @if ($row['mono']) is-mono @endif">
                                    <span class="p-cloud-detail__v">{{ $row['value'] }}</span>
                                    @if ($row['env'])
                                        <span class="p-cloud-env" title="来自环境变量 {{ $row['env'] }}">env</span>
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                @endforeach
            </div>

            @unless ($configured)
                <p class="p-cloud-muted">
                    未接入 — 改 <code>.env</code> 填齐 <code>MOO_MONITOR_CLOUD_ENABLED / TOKEN</code>（<code>URL</code> 有默认值），
                    再 <code>php artisan config:clear</code>，详见 docs/guide/16-cloud-push.md。
                </p>
            @endunless
        </div>
    </section>

    {{-- ④ 机制说明:整宽弱化卡 --}}
    <section class="p-cloud-card p-cloud-note">
        <div class="p-cloud-card__bd">
            <p>
                本地仅作临时采集缓冲：<code>moo:cloud:push</code> 成功后回收，<strong>open</strong> 留 {{ $retention }} 天作聚合锚点。
                处置（解决 / 删除）统一在 Moo Scaffold Cloud，本地不再保留这两类。
                线上由 scheduler 自动推，「立即推送」只适用于本地。
            </p>
        </div>
    </section>

</section>
</x-scaffold::shell>
