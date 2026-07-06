<div
    {{ $attributes->class(['toast-container']) }}
    x-data="toastContainer"
    @toast.window="handleToastEvent"
    aria-live="polite"
    aria-atomic="false"
>
    <template x-for="t in toasts" :key="t.id">
        <div
            class="toast"
            :class="t.toneClass"
            x-transition:enter="toast-enter"
            x-transition:enter-start="toast-enter-start"
            x-transition:enter-end="toast-enter-end"
            x-transition:leave="toast-leave"
            x-transition:leave-start="toast-leave-start"
            x-transition:leave-end="toast-leave-end"
            role="status"
        >
            <div class="toast__icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    {{-- CSP build:nested <template x-if> 在 <template x-for> 内 cloneNode 报错(Alpine quirk);
                         改 <g x-show> + 单层属性访问 — 不走 cloneNode 路径,也不挑 svg child --}}
                    <g x-show="t.isSuccess">
                        <polyline points="20 6 9 17 4 12" />
                    </g>
                    <g x-show="t.isInfo">
                        <circle cx="12" cy="12" r="10" /><line x1="12" y1="16" x2="12" y2="12" /><line x1="12" y1="8" x2="12.01" y2="8" />
                    </g>
                    <g x-show="t.isWarning">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                    </g>
                    <g x-show="t.isDanger">
                        <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
                    </g>
                    <g x-show="t.isNeutral">
                        <circle cx="12" cy="12" r="10" />
                    </g>
                </svg>
            </div>

            <div class="toast__body">
                <div class="toast__title" x-show="t.title" x-text="t.title"></div>
                <div class="toast__message" x-text="t.message"></div>
            </div>

            <button type="button" class="toast__close" :data-toast-id="t.id" @click="dismissFromButton" aria-label="关闭">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" />
                </svg>
            </button>
        </div>
    </template>
</div>
