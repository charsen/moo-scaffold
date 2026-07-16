{{-- 表 key 改名 modal,show 页 include。
     依赖 dbDesigner Alpine 组件 state:renameTableOpen / renameTableCurrentKey / renameTableNewKey,
     handlers:openRenameTable / cancelRenameTable / setRenameTableNewKey / confirmRenameTable。
     2026-07-04:migration 锁撤除 —— 已生成 migration 的表改名由后端闭环(自动 Schema::rename migration + 迁 snapshot)。 --}}
<div x-show="renameTableOpen"
     x-cloak
     x-on:click="cancelRenameTable"
     x-on:keydown.escape.window="cancelRenameTable"
     class="p-designer-rename-popover__backdrop"
></div>
<div x-show="renameTableOpen"
     x-cloak
     x-on:keydown.enter.prevent="confirmRenameTable"
     class="p-designer-rename-popover"
     role="dialog"
     aria-modal="true"
>
    <button type="button" class="p-designer-rename-popover__close" x-on:click="cancelRenameTable" aria-label="关闭">×</button>
    <h4>重命名表 key</h4>
    <p>改 yaml <code>tables.<span x-text="renameTableCurrentKey"></span></code> → <code>tables.<span x-text="renameTableNewKey"></span></code>（controller / ACL 不受影响）。<br>
    <strong>已生成 migration 的表</strong>：会自动生成 <code>Schema::rename</code> migration 并同步 snapshot，之后跑 <code>php artisan migrate</code> 真改 DB 表名。<br>
    <strong>若已生成 Model</strong>，其 <code>$table</code> 下次 <code>moo:model</code> 重生成对齐（或手动改）。</p>

    <label for="renametable-current">原 key</label>
    <input id="renametable-current" name="rename_table_current" type="text"
        class="p-designer-rename-popover__input"
        :value="renameTableCurrentKey"
        readonly
    />

    <label for="renametable-newkey">新 key(snake_case)</label>
    <input id="renametable-newkey" name="rename_table_new_key" type="text" autocomplete="off"
        class="p-designer-rename-popover__input"
        :value="renameTableNewKey"
        x-on:input="setRenameTableNewKey"
        placeholder="如 market_services"
    />

    <div class="p-designer-rename-popover__actions">
        <x-scaffold::btn variant="ghost" size="sm" x-on:click="cancelRenameTable">取消</x-scaffold::btn>
        <x-scaffold::btn variant="primary" size="sm" x-on:click="confirmRenameTable" x-bind:disabled="renamingTable">
            <span x-show="renamingTableIdle">改名</span>
            <span x-show="renamingTable">改名中…</span>
        </x-scaffold::btn>
    </div>
</div>
