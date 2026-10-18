<div
    x-data="{
        toasts: [],
        init() {
            @if (session('toast'))
                this.addToast({{ \Illuminate\Support\Js::from(session('toast')['message'] ?? '') }}, {{ \Illuminate\Support\Js::from(session('toast')['type'] ?? 'success') }});
            @endif

            window.addEventListener('toast', (event) => {
                const detail = event.detail || {};
                const message = detail.message || (Array.isArray(detail) && detail[0]?.message) || 'Pronto.';
                const type = detail.type || (Array.isArray(detail) && detail[0]?.type) || 'success';
                this.addToast(message, type);
            });
        },
        addToast(message, type = 'success') {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, message, type });
            setTimeout(() => this.removeToast(id), type === 'error' ? 8000 : 5000);
        },
        removeToast(id) {
            this.toasts = this.toasts.filter(toast => toast.id !== id);
        }
    }"
    class="fixed top-4 inset-x-4 sm:inset-x-auto sm:right-4 sm:w-96 z-[100] space-y-2 pointer-events-none"
    aria-live="polite"
>
    <template x-for="toast in toasts" :key="toast.id">
        <div
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 -translate-y-2"
            x-transition:enter-end="opacity-100 translate-y-0"
            class="w-full bg-white dark:bg-gray-800 shadow-lg rounded-lg pointer-events-auto ring-1 ring-black/5 overflow-hidden border-l-4"
            :class="toast.type === 'error' ? 'border-red-500' : 'border-green-500'"
        >
            <div class="p-4 flex items-start gap-3">
                <i class="fas text-xl mt-0.5" :class="toast.type === 'error' ? 'fa-circle-xmark text-red-500' : 'fa-circle-check text-green-500'"></i>
                <p class="flex-1 min-w-0 text-sm font-medium text-gray-900 dark:text-gray-100 break-words" x-text="toast.message"></p>
                <button type="button" @click="removeToast(toast.id)" class="text-gray-400 hover:text-gray-600" aria-label="Fechar">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>
        </div>
    </template>
</div>
