@php
    $user = auth()->user();
    $unit = \App\Models\Unit::default();
    // O que está esperando por esta pessoa (contador ao lado de "Avisos").
    $inboxCount = app(\App\Services\Orders\Inbox::class)->count($user);
    // Itens do menu: rota, rótulo, ícone e quem vê. Novas telas entram aqui.
    $items = array_values(array_filter([
        ['route' => 'dashboard', 'label' => 'OS', 'icon' => 'fa-clipboard-list', 'active' => ['dashboard', 'orders.*']],
        ['route' => 'inbox', 'label' => 'Avisos', 'icon' => 'fa-bell', 'active' => ['inbox'], 'count' => $inboxCount],
        $user->can('atendimento') ? ['route' => 'registry', 'label' => 'Clientes e veículos', 'icon' => 'fa-car', 'active' => ['registry']] : null,
        $user->can('atendimento') ? ['route' => 'suppliers', 'label' => 'Fornecedores', 'icon' => 'fa-truck-field', 'active' => ['suppliers']] : null,
        $user->can('atendimento') ? ['route' => 'finance', 'label' => 'Financeiro', 'icon' => 'fa-sack-dollar', 'active' => ['finance', 'finance.*']] : null,
        $user->isGestor() ? ['route' => 'settings', 'label' => 'Ajustes', 'icon' => 'fa-gear', 'active' => ['settings*']] : null,
    ]));
@endphp
<header class="sticky top-0 z-40 bg-gradient-to-b from-plate-2 to-plate text-plate-ink border-b-4 border-stripe shadow" style="padding-top: env(safe-area-inset-top, 0px)">
    <div class="max-w-6xl mx-auto px-4">
        <div class="flex items-center gap-3 pt-2 pb-1">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-3 min-w-0">
                <img src="{{ asset('images/logo.png') }}" alt="Multmec" class="size-11 shrink-0 rounded-full ring-2 ring-plate-2 shadow">
                <span class="min-w-0">
                    <span class="block font-display font-extrabold text-2xl uppercase tracking-wide leading-none truncate">Multmec</span>
                    <span class="block text-xs text-plate-muted truncate">Ordens de serviço{{ $unit ? ' · '.$unit->name : '' }}</span>
                </span>
            </a>
            <div class="ml-auto flex items-center gap-2" x-data="{ open: false }">
                <button type="button" onclick="toggleTheme()" class="size-9 inline-flex items-center justify-center rounded-lg text-plate-muted hover:text-plate-ink hover:bg-white/10" title="Tema claro ou escuro">
                    {{-- O Font Awesome força display nos <i>: quem esconde é o <span> de fora. --}}
                    <span class="dark:hidden"><i class="fas fa-moon"></i></span><span class="hidden dark:inline"><i class="fas fa-sun"></i></span>
                </button>
                <button type="button" @click="open = ! open" @click.outside="open = false" class="inline-flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-white/10" data-test="menu-usuario">
                    <span class="size-8 inline-flex items-center justify-center rounded-full bg-brand-600 text-white font-semibold text-sm">{{ mb_strtoupper(mb_substr($user->short_name, 0, 1)) }}</span>
                    <span class="hidden sm:block text-left leading-tight">
                        <span class="block text-sm font-semibold">{{ $user->short_name }}</span>
                        <span class="block text-xs text-plate-muted">{{ $user->role->label() }}</span>
                    </span>
                    <i class="fas fa-chevron-down text-xs text-plate-muted"></i>
                </button>
                <div x-show="open" x-cloak x-transition class="absolute right-4 top-14 w-56 rounded-lg bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 shadow-xl ring-1 ring-black/10 py-1 z-50">
                    <div class="px-4 py-2 border-b border-gray-100 dark:border-gray-700">
                        <p class="text-sm font-semibold truncate">{{ $user->name }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $user->role->label() }}</p>
                    </div>
                    <a href="{{ route('profile') }}" class="block px-4 py-2 text-sm hover:bg-gray-100 dark:hover:bg-gray-700"><i class="fas fa-key w-5 text-gray-400"></i> Trocar minha senha</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="w-full text-left px-4 py-2 text-sm hover:bg-gray-100 dark:hover:bg-gray-700"><i class="fas fa-right-from-bracket w-5 text-gray-400"></i> Sair</button>
                    </form>
                </div>
            </div>
        </div>
        <nav class="flex gap-1 overflow-x-auto -mb-1" aria-label="Menu">
            @foreach ($items as $item)
                @php($current = request()->routeIs(...$item['active']))
                <a href="{{ route($item['route']) }}"
                   @class([
                       'whitespace-nowrap px-3 py-2 font-display font-semibold text-[17px] uppercase tracking-wide border-b-[3px]',
                       'text-plate-ink border-brand-400' => $current,
                       'text-plate-muted border-transparent hover:text-plate-ink' => ! $current,
                   ])
                   @if ($current) aria-current="page" @endif>
                    <i class="fas {{ $item['icon'] }} mr-1 text-sm"></i>{{ $item['label'] }}
                    @if (($item['count'] ?? 0) > 0)
                        <span class="ml-1 inline-flex min-w-5 h-5 items-center justify-center rounded-full bg-stripe px-1.5 font-sans text-xs font-bold text-white" data-test="contador-avisos">{{ $item['count'] }}</span>
                    @endif
                </a>
            @endforeach
        </nav>
    </div>
</header>
