@php
    // Abas do Financeiro (rota, rótulo, ícone). Clientes e fornecedores são os cadastros do sistema (não se repetem aqui).
    $links = [
        ['finance', 'Painel', 'fa-chart-column'],
        ['finance.receivables', 'A receber', 'fa-hand-holding-dollar'],
        ['finance.payables', 'A pagar', 'fa-file-invoice-dollar'],
        ['finance.providers', 'Prestadores', 'fa-screwdriver-wrench'],
        ['finance.delinquents', 'Inadimplentes', 'fa-user-clock'],
        ['finance.losses', 'Prejuízos', 'fa-triangle-exclamation'],
    ];
    $chip = 'shrink-0 inline-flex items-center gap-2 min-h-11 px-3 rounded-full border text-sm font-semibold whitespace-nowrap';
@endphp
<nav class="flex gap-2 overflow-x-auto pb-1 -mx-4 px-4" aria-label="Financeiro" data-test="menu-financeiro">
    @foreach ($links as [$route, $label, $icon])
        @php($current = request()->routeIs($route))
        <a href="{{ route($route) }}" wire:navigate @class([$chip, 'bg-brand-600 text-white border-brand-600' => $current, 'border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700' => ! $current]) @if ($current) aria-current="page" @endif>
            <i class="fas {{ $icon }} text-xs"></i>{{ $label }}
        </a>
    @endforeach
    <a href="{{ route('registry', ['aba' => 'clientes']) }}" wire:navigate class="{{ $chip }} border-dashed border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700"><i class="fas fa-users text-xs"></i>Clientes</a>
    <a href="{{ route('suppliers') }}" wire:navigate class="{{ $chip }} border-dashed border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700"><i class="fas fa-truck-field text-xs"></i>Fornecedores</a>
</nav>
