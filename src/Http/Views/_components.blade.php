<!DOCTYPE html>
<html lang="zh-CN">
@include('scaffold::layouts._theme_boot')
<head>
    <meta charset="UTF-8">
    <title>Scaffold - 组件预览</title>
    <link rel="icon" type="image/svg+xml" href="/vendor/scaffold/images/favicon.svg">
    <link rel="stylesheet" href="/vendor/scaffold/css/index.css?v={{ @filemtime(public_path('vendor/scaffold/css/index.css')) ?: time() }}">
    <script src="/vendor/scaffold/javascript/alpine-init.js?v={{ @filemtime(public_path('vendor/scaffold/javascript/alpine-init.js')) ?: time() }}"></script>
    <script defer src="/vendor/scaffold/javascript/alpine-csp.min.js"></script>
</head>
<body class="pv-body">
<div class="pv-wrap">

    <div class="pv-head">
        <h1>Scaffold 组件预览</h1>
        <button type="button" class="btn btn--secondary" id="pv-theme-toggle">切换主题</button>
        <script nonce="{{ $cspNonce ?? '' }}">
            document.getElementById('pv-theme-toggle').addEventListener('click', function () {
                var n = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-theme', n);
                localStorage.setItem('scaffold_theme', n);
            });
        </script>
    </div>

    <section class="pv-section">
        <h2>Button <code>&lt;x-scaffold::btn&gt;</code></h2>

        <div class="pv-row">
            <span class="pv-label">primary</span>
            <x-scaffold::btn variant="primary" size="sm">小号</x-scaffold::btn>
            <x-scaffold::btn variant="primary">默认</x-scaffold::btn>
            <x-scaffold::btn variant="primary" size="lg">大号</x-scaffold::btn>
            <x-scaffold::btn variant="primary" disabled>禁用</x-scaffold::btn>
        </div>

        <div class="pv-row">
            <span class="pv-label">secondary</span>
            <x-scaffold::btn size="sm">小号</x-scaffold::btn>
            <x-scaffold::btn>默认</x-scaffold::btn>
            <x-scaffold::btn size="lg">大号</x-scaffold::btn>
        </div>

        <div class="pv-row">
            <span class="pv-label">ghost</span>
            <x-scaffold::btn variant="ghost" size="sm">小号</x-scaffold::btn>
            <x-scaffold::btn variant="ghost">默认</x-scaffold::btn>
            <x-scaffold::btn variant="ghost" size="lg">大号</x-scaffold::btn>
        </div>

        <div class="pv-row">
            <span class="pv-label">block</span>
            <div class="pv-w280"><x-scaffold::btn variant="primary" block>整行按钮</x-scaffold::btn></div>
        </div>

        <div class="pv-row">
            <span class="pv-label">链接形态</span>
            <x-scaffold::btn variant="primary" href="javascript:void(0)">链接按钮</x-scaffold::btn>
        </div>
    </section>

    <section class="pv-section">
        <h2>Input / Field <code>&lt;x-scaffold::input&gt;</code>, <code>&lt;x-scaffold::field&gt;</code></h2>

        <div class="pv-row">
            <span class="pv-label">尺寸</span>
            <div class="pv-w200"><x-scaffold::input size="sm" placeholder="sm" /></div>
            <div class="pv-w200"><x-scaffold::input placeholder="md（默认）" /></div>
            <div class="pv-w200"><x-scaffold::input size="lg" placeholder="lg" /></div>
        </div>

        <div class="pv-row pv-row--top">
            <span class="pv-label">字段</span>
            <div class="pv-w320">
                <x-scaffold::field label="用户名" hint="3-20 个字符" for="pv-uname">
                    <x-scaffold::input id="pv-uname" placeholder="请输入用户名" />
                </x-scaffold::field>
            </div>
            <div class="pv-w320">
                <x-scaffold::field label="密码" error="密码长度至少 6 位" for="pv-pwd">
                    <x-scaffold::input id="pv-pwd" type="password" placeholder="请输入密码" />
                </x-scaffold::field>
            </div>
        </div>

        <div class="pv-row pv-row--top">
            <span class="pv-label">禁用 / 只读</span>
            <div class="pv-w200"><x-scaffold::input value="只读内容" readonly /></div>
            <div class="pv-w200"><x-scaffold::input value="禁用" disabled /></div>
        </div>
    </section>

    <section class="pv-section">
        <h2>Card <code>&lt;x-scaffold::card&gt;</code></h2>

        <div class="pv-grid">
            <x-scaffold::card title="基础卡片">
                <p style="margin:0;color:var(--text-desc);">卡片正文内容。可以放任意 HTML。</p>
            </x-scaffold::card>

            <x-scaffold::card title="带操作">
                <x-slot:actions>
                    <x-scaffold::btn variant="ghost" size="sm">编辑</x-scaffold::btn>
                    <x-scaffold::btn variant="primary" size="sm">保存</x-scaffold::btn>
                </x-slot:actions>
                <p style="margin:0;color:var(--text-desc);">含右上角操作按钮的卡片。</p>
            </x-scaffold::card>

            <x-scaffold::card title="紧凑（flush）" flush>
                <ul style="margin:0;padding:0;list-style:none;">
                    <li style="padding:var(--space-3) var(--space-5);border-bottom:1px solid var(--border-light);">行 1</li>
                    <li style="padding:var(--space-3) var(--space-5);border-bottom:1px solid var(--border-light);">行 2</li>
                    <li style="padding:var(--space-3) var(--space-5);">行 3</li>
                </ul>
            </x-scaffold::card>

            <x-scaffold::card title="浮起（raised）" raised>
                <p style="margin:0;color:var(--text-desc);">阴影层级提升，用于浮起卡片或抽屉。</p>
            </x-scaffold::card>

            <x-scaffold::card title="幽灵（ghost）" ghost>
                <p style="margin:0;color:var(--text-desc);">透明背景 + 虚线边框。</p>
            </x-scaffold::card>

            <x-scaffold::card title="带 footer">
                <p style="margin:0;color:var(--text-desc);">主体内容</p>
                <x-slot:footer>
                    <x-scaffold::btn variant="ghost" size="sm">取消</x-scaffold::btn>
                    <x-scaffold::btn variant="primary" size="sm">确定</x-scaffold::btn>
                </x-slot:footer>
            </x-scaffold::card>
        </div>
    </section>

    <section class="pv-section">
        <h2>Panel <code>&lt;x-scaffold::panel&gt;</code></h2>

        <div class="pv-row pv-row--top">
            <span class="pv-label">base</span>
            <x-scaffold::panel class="pv-w320">
                <x-slot:hd><h3>有 hd slot</h3></x-slot:hd>
                这是 body 区。<code>wrapBody=true</code> 默认，slot 自动包到 <code>.bd</code> 里。
            </x-scaffold::panel>
        </div>

        <div class="pv-row pv-row--top">
            <span class="pv-label">无 hd</span>
            <x-scaffold::panel class="pv-w320">
                没传 <code>hd</code> slot 时，<code>.hd</code> 容器不渲染。
            </x-scaffold::panel>
        </div>

        <div class="pv-row pv-row--top">
            <span class="pv-label">多 .bd</span>
            <x-scaffold::panel class="pv-w320" :wrapBody="false">
                <x-slot:hd><h3>多 body 兄弟</h3></x-slot:hd>
                <div class="bd">第一段 body</div>
                <div class="bd">第二段 body（用于 tab pane 等多 .bd 场景）</div>
            </x-scaffold::panel>
        </div>
    </section>

    <section class="pv-section">
        <h2>Empty <code>&lt;x-scaffold::empty&gt;</code></h2>

        <div class="pv-grid">
            <x-scaffold::card>
                <x-scaffold::empty
                    title="暂无数据"
                    desc="还没有可显示的内容，点击下方按钮新建一条。"
                >
                    <x-slot:icon>
                        <x-scaffold::icon name="file" :size="24" />
                    </x-slot:icon>
                    <x-slot:actions>
                        <x-scaffold::btn variant="primary" size="sm">新建</x-scaffold::btn>
                    </x-slot:actions>
                </x-scaffold::empty>
            </x-scaffold::card>

            <x-scaffold::card>
                <x-scaffold::empty
                    compact
                    title="选择一个应用"
                    desc="请从顶部选择要查看的应用。"
                >
                    <x-slot:icon>
                        <x-scaffold::icon name="inbox" :size="24" />
                    </x-slot:icon>
                </x-scaffold::empty>
            </x-scaffold::card>
        </div>
    </section>

    <section class="pv-section">
        <h2>Icon <code>&lt;x-scaffold::icon&gt;</code></h2>

        <div class="pv-row">
            <span class="pv-label">默认 16</span>
            <x-scaffold::icon name="search" />
            <x-scaffold::icon name="close" />
            <x-scaffold::icon name="check" />
            <x-scaffold::icon name="plus" />
            <x-scaffold::icon name="more" />
            <x-scaffold::icon name="refresh" />
            <x-scaffold::icon name="external-link" />
            <x-scaffold::icon name="copy" />
            <x-scaffold::icon name="eye" />
        </div>

        <div class="pv-row">
            <span class="pv-label">scaffold</span>
            <x-scaffold::icon name="file" />
            <x-scaffold::icon name="code" />
            <x-scaffold::icon name="debug" />
            <x-scaffold::icon name="key" />
            <x-scaffold::icon name="wordbook" />
            <x-scaffold::icon name="protocol" />
            <x-scaffold::icon name="database" />
            <x-scaffold::icon name="list" />
            <x-scaffold::icon name="inbox" />
            <x-scaffold::icon name="shield" />
            <x-scaffold::icon name="send" />
        </div>

        <div class="pv-row">
            <span class="pv-label">尺寸</span>
            <x-scaffold::icon name="file" :size="12" />
            <x-scaffold::icon name="file" :size="16" />
            <x-scaffold::icon name="file" :size="20" />
            <x-scaffold::icon name="file" :size="24" />
            <x-scaffold::icon name="file" :size="32" />
        </div>

        <div class="pv-row">
            <span class="pv-label">染色</span>
            <span style="color:var(--accent-text)"><x-scaffold::icon name="check" :size="20" /></span>
            <span style="color:var(--badge-blue)"><x-scaffold::icon name="search" :size="20" /></span>
            <span style="color:var(--badge-green)"><x-scaffold::icon name="check" :size="20" /></span>
            <span style="color:var(--badge-red)"><x-scaffold::icon name="close" :size="20" /></span>
            <span style="color:var(--text-light)"><x-scaffold::icon name="more" :size="20" /></span>
        </div>
    </section>

    <section class="pv-section">
        <h2>Badge <code>&lt;x-scaffold::badge&gt;</code></h2>

        <div class="pv-row">
            <span class="pv-label">tint（默认）</span>
            <x-scaffold::badge tone="neutral">neutral</x-scaffold::badge>
            <x-scaffold::badge tone="info">info</x-scaffold::badge>
            <x-scaffold::badge tone="success">success</x-scaffold::badge>
            <x-scaffold::badge tone="warning">warning</x-scaffold::badge>
            <x-scaffold::badge tone="danger">danger</x-scaffold::badge>
            <x-scaffold::badge tone="accent">accent</x-scaffold::badge>
        </div>

        <div class="pv-row">
            <span class="pv-label">solid</span>
            <x-scaffold::badge tone="info" solid>info</x-scaffold::badge>
            <x-scaffold::badge tone="success" solid>success</x-scaffold::badge>
            <x-scaffold::badge tone="warning" solid>warning</x-scaffold::badge>
            <x-scaffold::badge tone="danger" solid>danger</x-scaffold::badge>
            <x-scaffold::badge tone="accent" solid>accent</x-scaffold::badge>
        </div>

        <div class="pv-row">
            <span class="pv-label">sm</span>
            <x-scaffold::badge tone="info" size="sm">3</x-scaffold::badge>
            <x-scaffold::badge tone="success" size="sm">完成</x-scaffold::badge>
            <x-scaffold::badge tone="danger" size="sm">5</x-scaffold::badge>
        </div>

        <div class="pv-row">
            <span class="pv-label">带图标</span>
            <x-scaffold::badge tone="success">
                <x-scaffold::icon name="check" :size="12" />
                已完成
            </x-scaffold::badge>
            <x-scaffold::badge tone="warning">
                <x-scaffold::icon name="more" :size="12" />
                进行中
            </x-scaffold::badge>
        </div>
    </section>

    <section class="pv-section">
        <h2>Method Badge <code>&lt;x-scaffold::method-badge&gt;</code></h2>

        <div class="pv-row">
            <span class="pv-label">默认</span>
            <x-scaffold::method-badge method="GET" />
            <x-scaffold::method-badge method="POST" />
            <x-scaffold::method-badge method="PUT" />
            <x-scaffold::method-badge method="PATCH" />
            <x-scaffold::method-badge method="DELETE" />
            <x-scaffold::method-badge method="ANY" />
        </div>

        <div class="pv-row">
            <span class="pv-label">sm</span>
            <x-scaffold::method-badge method="GET" size="sm" />
            <x-scaffold::method-badge method="POST" size="sm" />
            <x-scaffold::method-badge method="DELETE" size="sm" />
        </div>
    </section>

    <section class="pv-section">
        <h2>Stat Card <code>&lt;x-scaffold::stat-card&gt;</code></h2>

        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:var(--space-4);">
            <x-scaffold::stat-card label="模块" value="12">
                <x-slot:icon><x-scaffold::icon name="database" :size="20" /></x-slot:icon>
            </x-scaffold::stat-card>

            <x-scaffold::stat-card label="数据表" value="86" tone="info" hint="较昨日 +3">
                <x-slot:icon><x-scaffold::icon name="list" :size="20" /></x-slot:icon>
            </x-scaffold::stat-card>

            <x-scaffold::stat-card label="路由" value="240" tone="success">
                <x-slot:icon><x-scaffold::icon name="protocol" :size="20" /></x-slot:icon>
            </x-scaffold::stat-card>

            <x-scaffold::stat-card label="接口" value="198" tone="accent">
                <x-slot:icon><x-scaffold::icon name="send" :size="20" /></x-slot:icon>
            </x-scaffold::stat-card>
        </div>

        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:var(--space-4);margin-top:var(--space-4);">
            <x-scaffold::stat-card label="今日错误" value="3" hint="较昨日 -2" tone="warning">
                <x-slot:icon><x-scaffold::icon name="shield" :size="20" /></x-slot:icon>
            </x-scaffold::stat-card>

            <x-scaffold::stat-card label="未授权调用" value="0" tone="danger" hint="近 24 小时">
                <x-slot:icon><x-scaffold::icon name="key" :size="20" /></x-slot:icon>
            </x-scaffold::stat-card>
        </div>
    </section>

    <section class="pv-section">
        <h2>App Picker / App Card <code>&lt;x-scaffold::app-picker&gt; / &lt;x-scaffold::app-card&gt;</code></h2>

        <x-scaffold::app-picker>
            <x-scaffold::app-card name="admin" desc="后台管理 API" href="javascript:void(0)" active>
                <x-slot:icon><x-scaffold::icon name="shield" :size="20" /></x-slot:icon>
            </x-scaffold::app-card>

            <x-scaffold::app-card name="api" desc="对外业务 API" href="javascript:void(0)">
                <x-slot:icon><x-scaffold::icon name="send" :size="20" /></x-slot:icon>
            </x-scaffold::app-card>

            <x-scaffold::app-card name="internal" desc="内部服务调用" href="javascript:void(0)">
                <x-slot:icon><x-scaffold::icon name="code" :size="20" /></x-slot:icon>
            </x-scaffold::app-card>

            <x-scaffold::app-card name="webhook" desc="第三方回调入口" href="javascript:void(0)">
                <x-slot:icon><x-scaffold::icon name="protocol" :size="20" /></x-slot:icon>
                <x-slot:badge><x-scaffold::badge tone="success" size="sm">新</x-scaffold::badge></x-slot:badge>
            </x-scaffold::app-card>
        </x-scaffold::app-picker>
    </section>

    <section class="pv-section">
        <h2>Tabs <code>&lt;x-scaffold::tabs&gt;</code> <span style="color:var(--text-light);font-size:var(--font-sm);font-weight:400;">需 Alpine.js</span></h2>

        <div style="margin-bottom:var(--space-5);">
            <x-scaffold::tabs :tabs="[
                ['key' => 'history', 'label' => 'API 调试历史'],
                ['key' => 'commands', 'label' => '常用命令'],
                ['key' => 'changes', 'label' => '最近变更'],
            ]">
                <div class="tabs__panel" data-tab-panel="history" style="padding:var(--space-3) 0;color:var(--text-body);">
                    <p style="margin:0 0 var(--space-2);">最近 10 条变更...</p>
                    <p style="margin:0;color:var(--text-desc);font-size:var(--font-sm);">这里通常是历史列表。</p>
                </div>
                <div class="tabs__panel" data-tab-panel="commands" style="padding:var(--space-3) 0;color:var(--text-body);">
                    <p style="margin:0;">scaffold 命令引导：<code>php artisan scaffold:init</code> ...</p>
                </div>
                <div class="tabs__panel" data-tab-panel="changes" style="padding:var(--space-3) 0;color:var(--text-body);">
                    <p style="margin:0;">近 7 天发布了 5 个新接口。</p>
                </div>
            </x-scaffold::tabs>
        </div>

        <div>
            <x-scaffold::tabs variant="pills" default="all" :tabs="[
                ['key' => 'all', 'label' => '全部'],
                ['key' => 'active', 'label' => '使用中'],
                ['key' => 'deprecated', 'label' => '已弃用'],
            ]">
                <div class="tabs__panel" data-tab-panel="all" style="padding:var(--space-3) 0;color:var(--text-desc);font-size:var(--font-sm);">198 个接口</div>
                <div class="tabs__panel" data-tab-panel="active" style="padding:var(--space-3) 0;color:var(--text-desc);font-size:var(--font-sm);">186 个使用中</div>
                <div class="tabs__panel" data-tab-panel="deprecated" style="padding:var(--space-3) 0;color:var(--text-desc);font-size:var(--font-sm);">12 个已弃用</div>
            </x-scaffold::tabs>
        </div>
    </section>

    <section class="pv-section">
        <h2>Table <code>&lt;x-scaffold::table&gt;</code></h2>

        <x-scaffold::table>
            <thead>
                <tr>
                    <th>方法</th>
                    <th>URI</th>
                    <th>控制器</th>
                    <th>状态</th>
                    <th class="is-right">操作</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><x-scaffold::method-badge method="GET" /></td>
                    <td><code>/api/users/list</code></td>
                    <td>UserController@list</td>
                    <td><x-scaffold::badge tone="success">已发布</x-scaffold::badge></td>
                    <td class="is-right">
                        <div class="table__actions">
                            <x-scaffold::btn variant="ghost" size="sm">查看</x-scaffold::btn>
                            <x-scaffold::btn variant="ghost" size="sm">调试</x-scaffold::btn>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td><x-scaffold::method-badge method="POST" /></td>
                    <td><code>/api/users/store</code></td>
                    <td>UserController@store</td>
                    <td><x-scaffold::badge tone="success">已发布</x-scaffold::badge></td>
                    <td class="is-right">
                        <div class="table__actions">
                            <x-scaffold::btn variant="ghost" size="sm">查看</x-scaffold::btn>
                            <x-scaffold::btn variant="ghost" size="sm">调试</x-scaffold::btn>
                        </div>
                    </td>
                </tr>
                <tr class="is-active">
                    <td><x-scaffold::method-badge method="PUT" /></td>
                    <td><code>/api/users/{id}</code></td>
                    <td>UserController@update</td>
                    <td><x-scaffold::badge tone="warning">待审核</x-scaffold::badge></td>
                    <td class="is-right">
                        <div class="table__actions">
                            <x-scaffold::btn variant="ghost" size="sm">查看</x-scaffold::btn>
                            <x-scaffold::btn variant="ghost" size="sm">调试</x-scaffold::btn>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td><x-scaffold::method-badge method="DELETE" /></td>
                    <td><code>/api/users/{id}</code></td>
                    <td>UserController@destroy</td>
                    <td><x-scaffold::badge tone="danger">已弃用</x-scaffold::badge></td>
                    <td class="is-right">
                        <div class="table__actions">
                            <x-scaffold::btn variant="ghost" size="sm">查看</x-scaffold::btn>
                        </div>
                    </td>
                </tr>
            </tbody>
        </x-scaffold::table>

        <div style="margin-top:var(--space-4);">
            <x-scaffold::table compact striped>
                <thead>
                    <tr><th>字段</th><th>类型</th><th>必填</th><th>说明</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>name</code></td><td>string</td><td>是</td><td>用户名</td></tr>
                    <tr><td><code>email</code></td><td>string</td><td>是</td><td>邮箱</td></tr>
                    <tr><td><code>age</code></td><td>int</td><td>否</td><td>年龄</td></tr>
                    <tr><td><code>meta</code></td><td>object</td><td>否</td><td>扩展元数据</td></tr>
                </tbody>
            </x-scaffold::table>
        </div>
    </section>

    <section class="pv-section">
        <h2>Drawer <code>&lt;x-scaffold::drawer&gt;</code></h2>

        <div class="pv-row">
            <span class="pv-label">触发</span>
            <x-scaffold::btn variant="secondary" x-on:click="$dispatch('open-drawer', 'demo-records')">
                <x-scaffold::icon name="list" :size="14" />
                查看调试历史
            </x-scaffold::btn>
            <x-scaffold::btn variant="primary" x-on:click="$dispatch('open-drawer', 'demo-create')">
                <x-scaffold::icon name="plus" :size="14" />
                新建
            </x-scaffold::btn>
        </div>

        <p style="margin:var(--space-3) 0 0;color:var(--text-desc);font-size:var(--font-sm);">
            点击按钮触发 <code>$dispatch('open-drawer', 'NAME')</code>，对应 name 的 drawer 滑出。
        </p>
    </section>

    <section class="pv-section">
        <h2>Toast <code>&lt;x-scaffold::toast-container&gt;</code></h2>

        <div class="pv-row">
            <span class="pv-label">触发</span>
            <x-scaffold::btn variant="secondary" x-on:click="$dispatch('toast', { tone: 'success', message: '已保存成功' })">success</x-scaffold::btn>
            <x-scaffold::btn variant="secondary" x-on:click="$dispatch('toast', { tone: 'info', title: '提示', message: '后台正在同步数据，请稍候...' })">info（含标题）</x-scaffold::btn>
            <x-scaffold::btn variant="secondary" x-on:click="$dispatch('toast', { tone: 'warning', message: '该接口已弃用，建议使用 v2 版本' })">warning</x-scaffold::btn>
            <x-scaffold::btn variant="secondary" x-on:click="$dispatch('toast', { tone: 'danger', message: 'Network 503: 服务暂时不可用' })">danger</x-scaffold::btn>
            <x-scaffold::btn variant="secondary" x-on:click="$dispatch('toast', { tone: 'neutral', message: '已复制到剪贴板', duration: 1500 })">neutral · 1.5s</x-scaffold::btn>
        </div>

        <p style="margin:var(--space-3) 0 0;color:var(--text-desc);font-size:var(--font-sm);">
            <code>$dispatch('toast', { tone, title?, message, duration? })</code>；duration 默认 3500ms，传 0 则不自动消失。
        </p>
    </section>

    <section class="pv-section">
        <h2>Side Tree <code>&lt;x-scaffold::side-tree&gt;</code></h2>

        <div style="display:grid;grid-template-columns:280px 1fr;gap:var(--space-5);">
            <div style="border:1px solid var(--border-light);border-radius:var(--shell-card-radius);background:var(--bg-card);">
                <x-scaffold::side-tree
                    current="user-list"
                    :groups="[
                        ['key' => 'user', 'label' => 'UserController', 'count' => 4, 'items' => [
                            ['key' => 'user-list', 'label' => '用户列表', 'method' => 'GET', 'href' => '#'],
                            ['key' => 'user-show', 'label' => '用户详情', 'method' => 'GET', 'href' => '#'],
                            ['key' => 'user-store', 'label' => '创建用户', 'method' => 'POST', 'href' => '#'],
                            ['key' => 'user-destroy', 'label' => '删除用户（已弃用）', 'method' => 'DELETE', 'href' => '#', 'deprecated' => true],
                        ]],
                        ['key' => 'order', 'label' => 'OrderController', 'count' => 3, 'items' => [
                            ['key' => 'order-list', 'label' => '订单列表', 'method' => 'GET', 'href' => '#'],
                            ['key' => 'order-cancel', 'label' => '取消订单', 'method' => 'POST', 'href' => '#'],
                            ['key' => 'order-refund', 'label' => '订单退款', 'method' => 'POST', 'href' => '#'],
                        ]],
                        ['key' => 'acl', 'label' => 'AclController', 'count' => 2, 'items' => [
                            ['key' => 'acl-list', 'label' => '权限列表', 'method' => 'GET', 'href' => '#'],
                            ['key' => 'acl-assign', 'label' => '分配权限', 'method' => 'PUT', 'href' => '#'],
                        ]],
                    ]"
                />
            </div>

            <div style="color:var(--text-desc);font-size:var(--font-sm);">
                <p style="margin:0 0 var(--space-2);">特性：</p>
                <ul style="margin:0;padding-left:var(--space-5);line-height:1.8;">
                    <li>顶部搜索过滤——输入文本匹配 group/item label，自动展开命中分组</li>
                    <li>group head 点击折叠/展开，整体折叠状态记忆在 Alpine 内</li>
                    <li>当前 item 用 <code>:current="..."</code> 传入 key，激活态自动应用</li>
                    <li>弃用接口（<code>deprecated: true</code>）显示删除线</li>
                </ul>
            </div>
        </div>
    </section>

    <section class="pv-section">
        <h2>JSON Viewer <code>&lt;x-scaffold::json&gt;</code></h2>

        <p style="margin:0 0 var(--space-3);color:var(--text-desc);font-size:var(--font-sm);">
            自包含语法高亮（无 JS 依赖）。交互式折叠 / 展开仍由遗留 jsonFormat.js 提供。
        </p>

        <x-scaffold::json :data="[
            'code' => 0,
            'message' => 'ok',
            'data' => [
                'id' => 12345,
                'name' => '张三',
                'is_admin' => true,
                'roles' => ['user', 'editor'],
                'meta' => null,
                'created_at' => '2026-05-13T10:30:00+08:00',
            ],
        ]" />
    </section>

    <section class="pv-section">
        <h2>Shell / Header <code>&lt;x-scaffold::shell&gt; / &lt;x-scaffold::header&gt;</code></h2>

        <p style="margin:0;color:var(--text-desc);font-size:var(--font-sm);">
            这两个是布局级组件，不在预览页内联演示（header 是 <code>position: fixed</code> 会盖住预览页内容）。<br>
            访问任意已迁移的视图（如 phase 3 完成后的 dashboard / api 文档），就能看到 header 实际效果。<br>
            shell 用法见 <a href="/scaffold/docs/components" style="color:var(--accent-text);">docs/components.md §16-17</a>。
        </p>
    </section>

    {{-- Drawers + Toast 全局容器（放在最后避免影响布局） --}}
    <x-scaffold::drawer name="demo-records" title="最近调试记录" width="480px">
        <p style="margin:0 0 var(--space-3);color:var(--text-desc);">这里是抽屉内容示意。</p>

        <x-scaffold::table compact>
            <thead>
                <tr><th>方法</th><th>URI</th><th>状态</th><th>耗时</th></tr>
            </thead>
            <tbody>
                <tr><td><x-scaffold::method-badge method="GET" size="sm" /></td><td><code>/api/users/list</code></td><td><x-scaffold::badge tone="success" size="sm">200</x-scaffold::badge></td><td class="is-num">142ms</td></tr>
                <tr><td><x-scaffold::method-badge method="POST" size="sm" /></td><td><code>/api/users/store</code></td><td><x-scaffold::badge tone="success" size="sm">200</x-scaffold::badge></td><td class="is-num">88ms</td></tr>
                <tr><td><x-scaffold::method-badge method="POST" size="sm" /></td><td><code>/api/users/store</code></td><td><x-scaffold::badge tone="danger" size="sm">422</x-scaffold::badge></td><td class="is-num">61ms</td></tr>
            </tbody>
        </x-scaffold::table>

        <x-slot:footer>
            <x-scaffold::btn variant="ghost" x-on:click="$dispatch('close-drawer', 'demo-records')">关闭</x-scaffold::btn>
            <x-scaffold::btn variant="primary">查看更多</x-scaffold::btn>
        </x-slot:footer>
    </x-scaffold::drawer>

    <x-scaffold::drawer name="demo-create" title="新建条目" width="420px">
        <x-scaffold::field label="名称" for="dr-name" hint="3-20 字符">
            <x-scaffold::input id="dr-name" placeholder="输入名称" />
        </x-scaffold::field>
        <div style="margin-top:var(--space-4);">
            <x-scaffold::field label="描述" for="dr-desc">
                <x-scaffold::input id="dr-desc" placeholder="简单描述" />
            </x-scaffold::field>
        </div>

        <x-slot:footer>
            <x-scaffold::btn variant="ghost" x-on:click="$dispatch('close-drawer', 'demo-create')">取消</x-scaffold::btn>
            <x-scaffold::btn variant="primary" x-on:click="$dispatch('toast', { tone: 'success', message: '已创建' }); $dispatch('close-drawer', 'demo-create')">保存</x-scaffold::btn>
        </x-slot:footer>
    </x-scaffold::drawer>

    <x-scaffold::toast-container />

</div>
</body>
</html>
