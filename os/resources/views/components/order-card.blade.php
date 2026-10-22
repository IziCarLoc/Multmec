@props(['order', 'task' => null])
{{-- Cartão da OS no quadro, na lista e nos avisos: placa, número, carro, cliente, etapa e há quanto tempo. --}}
<a href="{{ route('orders.show', $order) }}" wire:navigate {{ $attributes->class('block bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-3 hover:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-500') }} data-test="os-{{ $order->number }}">
    <div class="flex items-start gap-3">
        <x-plate :plate="$order->vehicle?->plate" />
        <div class="min-w-0 flex-1">
            <p class="font-display font-bold text-lg leading-tight">{{ $order->label() }}
                @if ($order->vehicle?->is_izi_fleet) <span class="ml-1 align-middle rounded bg-gray-100 dark:bg-gray-700 px-1.5 py-0.5 font-sans text-[10px] font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Izi</span> @endif
            </p>
            <p class="text-sm text-gray-600 dark:text-gray-300 truncate">{{ $order->vehicle?->brand_model ?: 'Modelo não informado' }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ $order->customer?->name }}</p>
        </div>
    </div>
    @if ($task)
        <p class="mt-2 text-sm font-semibold text-brand-700 dark:text-brand-300"><i class="fas fa-arrow-right text-xs"></i> {{ $task }}</p>
    @endif
    <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
        <x-stage-badge :stage="$order->stage" />
        @php($days = $order->daysInStage())
        <span @class(['font-semibold text-amber-700 dark:text-amber-300' => $days >= 3])><i class="far fa-clock"></i> {{ $days === 0 ? 'hoje' : ($days === 1 ? '1 dia' : $days.' dias') }}</span>
        @if ($order->responsible)
            <span><i class="fas fa-user"></i> {{ $order->responsible->short_name }}</span>
        @endif
    </div>
</a>
