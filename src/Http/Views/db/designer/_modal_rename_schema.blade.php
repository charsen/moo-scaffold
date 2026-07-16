{{-- 草稿态 schema 改名 modal,只在 index 页 include。
     依赖 dbDesigner Alpine 组件 state:renameSchemaOpen / renameSchemaCurrentKey / renameSchemaNewKey,
     handlers:openRenameSchema / cancelRenameSchema / setRenameSchemaNewKey / confirmRenameSchema。
     锁定态 schema 由 ⋯ 菜单层面屏蔽不显示,后端 SchemaLoader::renameSchema 也有 isSchemaDraft 双 guard。 --}}
<div x-show="renameSchemaOpen"
     x-cloak
     x-on:click="cancelRenameSchema"
     x-on:keydown.escape.window="cancelRenameSchema"
     class="p-designer-rename-popover__backdrop"
></div>
<div x-show="renameSchemaOpen"
     x-cloak
     x-on:keydown.enter.prevent="confirmRenameSchema"
     class="p-designer-rename-popover"
     role="dialog"
     aria-modal="true"
>
    <button type="button" class="p-designer-rename-popover__close" x-on:click="cancelRenameSchema" aria-label="关闭">×</button>
    <h4>重命名 schema（模块）</h4>
    <p>会 <code>mv</code> <code><span x-text="renameSchemaCurrentKey"></span>.yaml</code> → <code><span x-text="renameSchemaNewKey"></span>.yaml</code>，并更新 <code>module.folder</code> 字段；锁定态（已生成 migration）会被后端拒绝。</p>

    <label for="renameschema-current">原名</label>
    <input id="renameschema-current" name="rename_schema_current" type="text"
        class="p-designer-rename-popover__input"
        :value="renameSchemaCurrentKey"
        readonly
    />

    <label for="renameschema-newkey">新名（PascalCase）</label>
    <input id="renameschema-newkey" name="rename_schema_new_key" type="text" autocomplete="off"
        class="p-designer-rename-popover__input"
        :value="renameSchemaNewKey"
        x-on:input="setRenameSchemaNewKey"
        placeholder="如 OrderV2（对应 OrderV2.yaml）"
    />

    <div class="p-designer-rename-popover__actions">
        <x-scaffold::btn variant="ghost" size="sm" x-on:click="cancelRenameSchema">取消</x-scaffold::btn>
        <x-scaffold::btn variant="primary" size="sm" x-on:click="confirmRenameSchema" x-bind:disabled="renamingSchema">
            <span x-show="renamingSchemaIdle">改名</span>
            <span x-show="renamingSchema">改名中…</span>
        </x-scaffold::btn>
    </div>
</div>
