{{-- 全局确认弹窗:监听 window 'scaffold-confirm' 事件,替代浏览器 confirm() --}}
{{-- 2026-05-22 plan-43 Batch D:--}}
{{--  · Enter 键 confirm(若已 disabled 不触发)— 跟 designer popover 一致 --}}
{{--  · danger=true 时 .modal-panel--danger tone class — 跟 designer danger popover 视觉一脉相承 --}}
<div x-data="confirmContainer"
     @scaffold-confirm.window="handleEvent"
     @keydown.escape.window="handleEscape"
     @keydown.enter.window="handleEnter">
    <template x-if="visible">
        <div class="modal" role="alertdialog" aria-modal="true">
            <div class="modal-backdrop" @click="cancel"></div>
            <div class="modal-panel modal-panel--sm" :class="panelClass" x-transition>
                <header class="modal-header">
                    <h3 x-text="title"></h3>
                </header>
                <div class="modal-body">
                    <p x-text="message"></p>
                    {{-- plan-22 安全审计 Q4:challenge text 防误触(单条 purge 输 hash 前 8 位,批量输"清除 N 条") --}}
                    <template x-if="challenge">
                        <div class="modal-challenge">
                            <label class="modal-challenge__label" x-text="challengeLabel"></label>
                            <input type="text" class="modal-challenge__input"
                                   x-model="challengeInput"
                                   x-ref="challengeInput"
                                   autocomplete="off"
                                   spellcheck="false">
                        </div>
                    </template>
                </div>
                <footer class="modal-footer">
                    <button type="button" class="btn btn--ghost" @click="cancel" x-text="cancelLabel"></button>
                    <button type="button" class="btn" :class="confirmClass" :disabled="confirmDisabled" @click="confirm" x-text="confirmLabel"></button>
                </footer>
            </div>
        </div>
    </template>
</div>
