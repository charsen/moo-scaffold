{{-- plan-22 P1-S3:layouts.two_columns 兼容层删,直接用 <x-scaffold::shell> --}}
<x-scaffold::shell title="Scaffold - 开发人员" containerClass="is-route">
{{-- C 方案:layouts.app → two_columns,sidebar+right 合到单 content --}}
<div class="p-acc-page">
    @include('scaffold::config._sidebar', ['all_groups' => $all_groups, 'active' => '__accounts', 'sidebar_class' => 'p-acc-page__sidebar'])
    <section class="p-acc-shell" @if ($readonly || $is_prod) data-locked="true" @endif>
    {{-- Alpine 顶层 scope（accountsPage 注册在 alpine-init.js）同时管表单 modal + 删除确认 modal
         data-store-base 注入 store URL,accountsPage.init() 拿,避免 CSP build 模板不允许 method call 带 literal --}}
    <div x-data="accountsPage"
         data-store-base="{{ url(route('scaffold.accounts.store', [], false)) }}"
         @keydown.escape.window="handleEscape">

    {{-- 2026-05-28 phase C-1:sticky lock banner — 跟 designer 一致(ship 清单 #11) --}}
    @if ($readonly || $is_prod)
        <div class="p-designer-locked-banner" role="status" aria-live="polite">
            <x-scaffold::icon name="warn" :size="14" />
            <strong>{{ $is_prod ? '生产环境' : '只读模式' }}</strong>
            <span>所有写操作已禁用 — 新增 / 编辑 / 删除 / 启停。</span>
            <span class="p-designer-locked-banner__hint">{{ $is_prod ? 'APP_ENV=production' : 'SCAFFOLD_CONFIG_READONLY=true' }}</span>
        </div>
    @endif

    {{-- plan-22 P1-S1: 改用统一 <x-scaffold::hero> 组件 --}}
    <x-scaffold::hero icon="shield" title="开发人员">
        <x-slot:badges>
            @if ($is_prod)
                <x-scaffold::badge tone="danger" solid>生产 · 只读</x-scaffold::badge>
            @elseif ($readonly)
                <x-scaffold::badge tone="warning">只读</x-scaffold::badge>
            @endif
        </x-slot:badges>
        <x-slot:desc>管理面板登录账号。</x-slot:desc>
        {{-- plan-22 P1-S1: @if 不能包 named slot(Blade 解析报错),把条件移进 slot 内 --}}
        <x-slot:actions>
            @if (! $readonly && ! $is_prod)
                <x-scaffold::btn variant="primary" size="sm" @click="openCreate">
                    <x-scaffold::icon name="plus" :size="14" />
                    新增开发人员
                </x-scaffold::btn>
            @endif
        </x-slot:actions>
    </x-scaffold::hero>
    <div class="p-acc-meta">
        <span>总数 <strong>{{ count($accounts) }}</strong></span>
        <span>启用 <strong>{{ collect($accounts)->where('enabled', true)->count() }}</strong></span>
        @if (! empty($meta['updated_at']))
            <span>最后更新 <strong>{{ $meta['updated_at'] }}</strong> by <code>{{ $meta['updated_by'] ?? '—' }}</code></span>
        @endif
    </div>

    {{-- flash --}}
    @if (! empty($flash_message))
        <x-scaffold::panel class="p-acc-flash p-acc-flash--ok">{{ $flash_message }}</x-scaffold::panel>
    @endif
    @if (! empty($flash_error))
        <x-scaffold::panel class="p-acc-flash p-acc-flash--err">{{ $flash_error }}</x-scaffold::panel>
    @endif

        @if (empty($accounts))
            <x-scaffold::empty title="还没有任何账号" desc="点上方“新增开发人员”按钮创建第一个。" />
        @else
        <x-scaffold::table compact striped class="p-acc-table">
            <thead>
                <tr>
                    <th style="width:56px;" class="p-acc-idx">#</th>
                    {{-- 用户名留 auto:table-layout:fixed 下唯一无宽度列,吸收全部富余横向空间,
                         其它列宽度才能精确生效(序号/操作固定 px,中间三列百分比) --}}
                    <th>用户名</th>
                    <th style="width:15%;">角色</th>
                    <th style="width:18%;">数据库设计</th>
                    <th style="width:24%;">最近更新</th>
                    <th style="width:124px;">操作</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($accounts as $a)
                @php
                    $isSelf = $a['username'] === $me;
                    $canDesign = $a['role'] === 'admin' || ($a['can_design_db'] ?? false);
                    $hue = crc32($a['username']) % 360;
                    $initial = mb_strtoupper(mb_substr($a['username'], 0, 1));
                    // 去秒:2026-06-17 22:54:22 → 2026-06-17 22:54(完整值进 title hover)
                    $updFull = $a['updated_at'] ?? '';
                    $updShort = $updFull ? preg_replace('/(\d{2}:\d{2}):\d{2}$/', '$1', $updFull) : '—';
                @endphp
                <tr class="{{ $a['enabled'] ? '' : 'is-disabled' }}">
                    <td class="p-acc-idx">{{ $loop->iteration }}</td>
                    <td>
                        <div class="p-acc-id">
                            <span class="p-acc-avatar {{ $a['enabled'] ? '' : 'is-off' }}" style="--h:{{ $hue }}" aria-hidden="true">
                                {{ $initial }}
                                <i class="p-acc-avatar__dot"></i>
                            </span>
                            <span class="p-acc-id__text">
                                <span class="p-acc-id__name">
                                    <strong>{{ $a['username'] }}</strong>
                                    @if ($isSelf)
                                        <x-scaffold::badge tone="info" size="sm">你</x-scaffold::badge>
                                    @endif
                                    @unless ($a['enabled'])
                                        <span class="p-acc-id__off">停用</span>
                                    @endunless
                                </span>
                                @if (! empty($a['phone']))
                                    <span class="p-acc-id__phone">{{ $a['phone'] }}</span>
                                @endif
                            </span>
                        </div>
                    </td>
                    <td>
                        <x-scaffold::badge :tone="$a['role'] === 'admin' ? 'accent' : 'neutral'" size="sm">{{ $a['role'] }}</x-scaffold::badge>
                    </td>
                    <td>
                        @if ($canDesign)
                            <x-scaffold::badge tone="success" size="sm" :title="$a['role'] === 'admin' ? 'admin 角色恒可编辑设计器' : '已授权编辑设计器'">可设计</x-scaffold::badge>
                        @else
                            <span class="acc-null">—</span>
                        @endif
                    </td>
                    <td class="small text-muted" title="{{ $updFull }}">{{ $updShort }}</td>
                    <td class="p-acc-actions">
                        @if (! $readonly && ! $is_prod)
                            <button type="button" class="p-acc-iconbtn" title="编辑" aria-label="编辑账号 {{ $a['username'] }}"
                                data-row='{{ json_encode(['username' => $a['username'], 'phone' => $a['phone'] ?? '', 'role' => $a['role'], 'enabled' => (bool) $a['enabled'], 'can_design_db' => (bool) ($a['can_design_db'] ?? false)], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) }}'
                                @click="openEditFromButton">
                                <x-scaffold::icon name="edit" :size="14" />
                            </button>

                            @if (! $isSelf)
                                <form method="POST" action="{{ route('scaffold.accounts.toggle', ['username' => $a['username']]) }}">
                                    @csrf
                                    <button type="submit" class="p-acc-iconbtn {{ $a['enabled'] ? '' : 'is-off' }}"
                                        title="{{ $a['enabled'] ? '停用' : '启用' }}"
                                        aria-label="{{ $a['enabled'] ? '停用' : '启用' }}账号 {{ $a['username'] }}">
                                        <x-scaffold::icon name="power" :size="14" />
                                    </button>
                                </form>

                                <button type="button" class="p-acc-iconbtn p-acc-iconbtn--danger"
                                    title="删除"
                                    aria-label="删除账号 {{ $a['username'] }}"
                                    data-username="{{ $a['username'] }}"
                                    @click="askDelete">
                                    <x-scaffold::icon name="trash" :size="14" />
                                </button>
                            @endif
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </x-scaffold::table>
        @endif

        {{-- 新增 / 编辑 弹窗(CSP-safe:x-model nested 拒 → :value+x-on:input 单 setter ref;
             :action 用 getter formActionUrl,不带 literal method call)
             2026-05-22 plan-43 Batch D:删外层 x-transition.opacity,modal 内层 x-transition 自带
             单层 fade — 避免双层 transition 闪烁 --}}
        <form method="POST" :action="formActionUrl"
              x-show="formOpen" x-cloak>
            @csrf
            <x-scaffold::modal size="md" onClose="closeForm">
                <x-slot:header>
                    <h3 x-text="modalTitle"></h3>
                    <button type="button" class="modal-close" @click="closeForm" aria-label="关闭">
                        <x-scaffold::icon name="close" :size="18" />
                    </button>
                </x-slot:header>

                <div class="p-acc-form-grid">
                    <label for="acc-form-username">
                        <span>用户名 <em x-show="passwordRequired">*</em></span>
                        <input type="text" id="acc-form-username" name="username" autocomplete="username"
                            :value="editingUsername" x-on:input="setEditing" x-ref="firstField"
                            :readonly="editTarget" :required="passwordRequired"
                            pattern="[A-Za-z0-9._-]+" maxlength="64">
                    </label>
                    <label for="acc-form-password">
                        <span>密码 <em x-show="passwordRequired">*</em></span>
                        <input type="password" id="acc-form-password" name="password" autocomplete="new-password"
                            :value="editingPassword" x-on:input="setEditing" x-ref="pwdField"
                            :required="passwordRequired"
                            :placeholder="passwordPlaceholder">
                        <small>存储为 bcrypt hash；旧明文账号在下次登录时会自动升级</small>
                    </label>
                    <label for="acc-form-phone">
                        <span>手机号</span>
                        <input type="text" id="acc-form-phone" name="phone" autocomplete="tel"
                            :value="editingPhone" x-on:input="setEditing" maxlength="20">
                    </label>
                    <label for="acc-form-role">
                        <span>角色</span>
                        <select id="acc-form-role" name="role" :value="editingRole" x-on:change="setEditing">
                            <option value="admin">admin（可改任何账号 / 配置）</option>
                            <option value="member">member（仅自管资料）</option>
                        </select>
                    </label>
                    <label class="p-acc-form-toggle" for="acc-form-enabled">
                        {{-- hidden 兜底:原生表单里未勾选的 checkbox 不进 POST → 后端 has('enabled') 假 →
                             停用意图被丢。hidden 在前、checkbox 在后,勾选时 PHP 取后者 "1",未勾选只剩 "0"。 --}}
                        <input type="hidden" name="enabled" value="0">
                        <input type="checkbox" id="acc-form-enabled" name="enabled" value="1" :checked="editingEnabled" x-on:change="setEditing">
                        <span>启用</span>
                    </label>
                    <label class="p-acc-form-toggle" for="acc-form-can-design">
                        {{-- 同 enabled:hidden 兜底,未勾选的 checkbox 不进 POST。admin 角色恒可设计,此勾主要给 member 授权 --}}
                        <input type="hidden" name="can_design_db" value="0">
                        <input type="checkbox" id="acc-form-can-design" name="can_design_db" value="1" :checked="editingCanDesignDb" x-on:change="setEditing">
                        <span>设计数据库（可编辑 designer；admin 恒可）</span>
                    </label>
                </div>

                <x-slot:footer>
                    <x-scaffold::btn variant="ghost" @click="closeForm">取消</x-scaffold::btn>
                    <x-scaffold::btn type="submit" variant="primary">保存</x-scaffold::btn>
                </x-slot:footer>
            </x-scaffold::modal>
        </form>

        {{-- 删除确认弹窗。
             外层 <div> 持有 x-show/x-cloak directives:Blade $attributes->merge 会把 no-value directives
             序列化成 x-cloak="x-cloak",被 Alpine CSP build 当成表达式解析失败 throw "is not a function"。
             换 inline <div> 后这些 directives 原样渲染,Alpine 正常识别。
             2026-05-22 plan-43 Batch D:删 x-transition.opacity,modal 内层 x-transition 自带单层 fade --}}
        <div x-show="deleteVisible" x-cloak>
        <x-scaffold::modal size="sm" role="alertdialog" :dismissible="true" onClose="cancelDelete" title="确认删除" tone="danger">
            <p>
                确认删除账号 <code x-text="deleteTarget"></code>？<br>
                该账号将无法再登录管理面板，yaml 中的记录会被移除。
            </p>
            <x-slot:footer>
                <x-scaffold::btn variant="ghost" @click="cancelDelete">取消</x-scaffold::btn>
                <form method="POST" :action="deleteActionUrl">
                    @csrf
                    <x-scaffold::btn type="submit" variant="danger">确认删除</x-scaffold::btn>
                </form>
            </x-slot:footer>
        </x-scaffold::modal>
        </div>
    </div>{{-- /x-data wrapper --}}
    </section>
</div>
</x-scaffold::shell>

{{-- window.scaffoldCopyText 已抽到 public/javascript/main.js（全局共享），此处不再重复 --}}

{{-- plan-22 T8: inline <style> 已外迁到 public/sass/7-pages/_accounts.scss --}}
