{{-- 草稿态 schema 删除 confirm modal,只在 index 页 include。
     需输入完整 schema 名才能 confirm(对齐 deleteTable 模式);锁定态由 ⋯ 菜单 + 后端双 guard。
     2026-05-23 plan-48 F1:迁 <x-scaffold::modal tone='danger'> 跟 accounts 删账号 modal 同款视觉 --}}
<div x-show="deleteSchemaOpen"
     x-cloak
     x-on:keydown.enter.prevent="confirmDeleteSchema"
     x-on:keydown.escape.window="cancelDeleteSchema">
    <x-scaffold::modal size="sm" tone="danger" onClose="cancelDeleteSchema" title="删除 schema（模块）">
        <p>会 <strong>彻底删</strong> <code><span x-text="deleteSchemaCurrentKey"></span>.yaml</code> 文件，不可恢复（走 git 找回）。锁定态会被后端拒绝。</p>

        <label for="deleteschema-confirm">为防误删，请输入完整模块名 <code x-text="deleteSchemaCurrentKey"></code> 确认</label>
        <input id="deleteschema-confirm" name="delete_schema_confirm" type="text" autocomplete="off"
            class="p-designer-rename-popover__input"
            :value="deleteSchemaConfirm"
            x-on:input="setDeleteSchemaConfirm"
            placeholder="输入完整模块名"
        />

        <x-slot:footer>
            <x-scaffold::btn variant="ghost" size="sm" x-on:click="cancelDeleteSchema">取消</x-scaffold::btn>
            <x-scaffold::btn variant="danger" size="sm" x-on:click="confirmDeleteSchema" x-bind:disabled="deleteSchemaBlocked">
                <span x-show="deleteSchemaCanConfirm">彻底删除</span>
                <span x-show="deleteSchemaBlocked">请输入完整模块名</span>
            </x-scaffold::btn>
        </x-slot:footer>
    </x-scaffold::modal>
</div>
