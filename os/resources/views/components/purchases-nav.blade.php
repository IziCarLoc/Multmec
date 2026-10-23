@php
    // Abas da conferência de compras (rota, rótulo, ícone, só do gestor). Grupo, DDA e os parâmetros são do gestor.
    $gestor = (bool) auth()->user()?->isGestor();
    $links = array_filter([
        ['finance.purchases', 'Conferência', 'fa-list-check', false],
        ['finance.purchases.invoices', 'Notas', 'fa-file-lines', false],
        ['finance.purchases.slips', 'Boletos', 'fa-barcode', false],
        ['finance.purchases.suppliers', 'Fornecedores', 'fa-truck-field', false],
        ['finance.purchases.group', 'Grupo', 'fa-building', true],
        ['finance.purchases.dda', 'DDA', 'fa-building-columns', true],
        ['settings.purchases', 'Parâmetros', 'fa-sliders', true],
    ], fn ($link) => ! $link[3] || $gestor);
    $tab = 'shrink-0 inline-flex items-center gap-2 min-h-11 px-3 border-b-2 text-sm font-semibold whitespace-nowrap';
@endphp
<nav class="flex gap-1 overflow-x-auto -mx-4 px-4 border-b border-gray-200 dark:border-gray-700" aria-label="Conferência de compras" data-test="menu-compras">
    @foreach ($links as [$route, $label, $icon])
        @php($current = request()->routeIs($route))
        <a href="{{ route($route) }}" wire:navigate @class([$tab, 'border-brand-600 text-brand-700 dark:text-brand-300' => $current, 'border-transparent text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white' => ! $current]) @if ($current) aria-current="page" @endif>
            <i class="fas {{ $icon }} text-xs"></i>{{ $label }}
        </a>
    @endforeach
</nav>
