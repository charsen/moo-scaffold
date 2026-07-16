{{-- plan 19 v9 F2:新建 schema modal,抽出 partial 让 index / show 都能 include。
     依赖 dbDesigner Alpine 组件的 state:newSchemaOpen / newSchemaKey/Name/Desc,
     handlers:openNewSchema / cancelNewSchema / set*  / confirmNewSchema。 --}}
<div x-show="newSchemaOpen"
     x-cloak
     x-on:click="cancelNewSchema"
     x-on:keydown.escape.window="cancelNewSchema"
     class="p-designer-rename-popover__backdrop"
></div>
<div x-show="newSchemaOpen"
     x-cloak
     x-on:keydown.enter.prevent="confirmNewSchema"
     class="p-designer-rename-popover"
     role="dialog"
     aria-modal="true"
>
    <button type="button" class="p-designer-rename-popover__close" x-on:click="cancelNewSchema" aria-label="关闭">×</button>
    <h4>新建 schema（模块）</h4>
    <p>会在 <code>scaffold/database/</code> 创建 minimal yaml，后续在 designer 加表。</p>

    <label for="newschema-key">模块名（PascalCase）</label>
    <input id="newschema-key" name="new_schema_key" type="text" autocomplete="off"
        class="p-designer-rename-popover__input"
        :value="newSchemaKey"
        x-on:input="setNewSchemaKey"
        placeholder="如 Order（对应 Order.yaml）"
    />

    <label for="newschema-name">显示名</label>
    <input id="newschema-name" name="new_schema_name" type="text" autocomplete="off"
        class="p-designer-rename-popover__input"
        :value="newSchemaName"
        x-on:input="setNewSchemaName"
        placeholder="如 订单管理"
    />

    <label for="newschema-desc">描述（可选）</label>
    <input id="newschema-desc" name="new_schema_desc" type="text" autocomplete="off"
        class="p-designer-rename-popover__input"
        :value="newSchemaDesc"
        x-on:input="setNewSchemaDesc"
        placeholder="如 订单 / 退款 / 发票"
    />

    <div class="p-designer-rename-popover__actions">
        <x-scaffold::btn variant="ghost" size="sm" x-on:click="cancelNewSchema">取消</x-scaffold::btn>
        <x-scaffold::btn variant="primary" size="sm" x-on:click="confirmNewSchema" x-bind:disabled="creatingSchema">
            <span x-show="creatingSchemaIdle">创建</span>
            <span x-show="creatingSchema">创建中…</span>
        </x-scaffold::btn>
    </div>
</div>
