<!DOCTYPE html>
<html lang="pt-BR">
<head>
    @include('layouts.partials.head')
</head>
<body class="min-h-screen bg-slate-100 dark:bg-gray-900 text-gray-900 dark:text-gray-100 font-sans antialiased">
    {{-- Barra de progresso de todo pedido ao servidor (clique, formulário, navegação): resposta visível na hora. --}}
    <div id="lw-progress" class="fixed top-0 left-0 z-[200] h-0.5 w-0 bg-brand-400 opacity-0 transition-all duration-300 ease-out pointer-events-none" aria-hidden="true"></div>
    @auth
        @include('components.topbar')
        {{-- Deploy sem composer install: o código novo roda com pacotes antigos e telas quebram. Só o gestor vê. --}}
        @if (auth()->user()->isGestor() && ($outdatedPackages = \App\Support\DeployCheck::summary()))
            <div class="bg-red-600 text-white text-sm px-4 py-2 text-center" data-test="aviso-pacotes-desatualizados">
                <i class="fas fa-triangle-exclamation mr-1"></i>O servidor está com pacotes desatualizados: {{ $outdatedPackages }}. Rode <code class="font-mono">composer install --no-dev --optimize-autoloader</code> e <code class="font-mono">php artisan optimize</code> (confira com <code class="font-mono">php artisan sistema:conferir-deploy</code>).
            </div>
        @endif
    @endauth
    <main class="max-w-6xl mx-auto px-4 py-4 sm:py-6 pb-24">
        {{ $slot }}
    </main>
    @include('components.toast')
    @include('layouts.partials.scripts')
    @livewireScripts
</body>
</html>
