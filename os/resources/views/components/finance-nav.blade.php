@php
    // Abas do Financeiro (rota, rótulo, ícone). Clientes e fornecedores são os cadastros do sistema (não se repetem aqui).
    // A Meta do mês é só do gestor (tem a cascata até o disponível para os sócios).
    $links = array_values(array_filter([
        ['finance', 'Painel', 'fa-chart-column'],
        auth()->user()?->can('gestor') ? ['finance.goal', 'Meta do mês', 'fa-bullseye'] : null,
        ['finance.receivables', 'A receber', 'fa-hand-holding-dollar'],
        ['finance.payables', 'A pagar', 'fa-file-invoice-dollar'],
        ['finance.recurring', 'Contas fixas', 'fa-repeat'],
        ['finance.credit', 'Crédito', 'fa-handshake'],
        ['finance.purchases', 'Compras', 'fa-receipt', 'finance.purchases*'],
        ['finance.providers', 'Prestadores', 'fa-screwdriver-wrench'],
        ['finance.delinquents', 'Inadimplentes', 'fa-user-clock'],
        ['finance.losses', 'Prejuízos', 'fa-triangle-exclamation'],
        ['finance.reports', 'Relatórios', 'fa-chart-line'],
    ]));
    $chip = 'shrink-0 inline-flex items-center gap-2 min-h-11 px-3 rounded-full border text-sm font-semibold whitespace-nowrap';
@endphp
<nav class="flex gap-2 overflow-x-auto pb-1 -mx-4 px-4" aria-label="Financeiro" data-test="menu-financeiro">
    @foreach ($links as $link)
        @php([$route, $label, $icon] = $link)
        @php($current = request()->routeIs($link[3] ?? $route))
        <a href="{{ route($route) }}" wire:navigate @class([$chip, 'bg-brand-600 text-white border-brand-600' => $current, 'border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700' => ! $current]) @if ($current) aria-current="page" @endif>
            <i class="fas {{ $icon }} text-xs"></i>{{ $label }}
        </a>
    @endforeach
    <a href="{{ route('registry', ['aba' => 'clientes']) }}" wire:navigate class="{{ $chip }} border-dashed border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700"><i class="fas fa-users text-xs"></i>Clientes</a>
    <a href="{{ route('suppliers') }}" wire:navigate class="{{ $chip }} border-dashed border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700"><i class="fas fa-truck-field text-xs"></i>Fornecedores</a>
</nav>
