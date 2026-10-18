<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Ordens de serviço')] class extends Component
{
    /** Etapas do quadro (a OS chega no incremento 1; aqui o quadro já mostra o caminho). */
    public function stages(): array
    {
        return [
            'Em vistoria', 'Em diagnóstico', 'Aguardando cotação', 'Aguardando aprovação',
            'Aprovada', 'Em execução', 'Em conferência', 'Pronta para retirada',
        ];
    }
};
?>

<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-3">
        <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide flex items-center gap-2">
            <span class="w-1 h-6 rounded bg-brand-600"></span>Ordens de serviço
        </h1>
    </div>

    <div class="{{ $cardClass }} p-5">
        <p class="font-semibold">Olá, {{ rtrim(auth()->user()->short_name, '.') }}!</p>
        <p class="text-sm text-gray-600 dark:text-gray-300 mt-1">
            O sistema está sendo montado por partes. Nesta primeira versão já funcionam o acesso, os perfis e os ajustes.
            A abertura de OS chega na próxima entrega.
        </p>
        @can('gestor')
            <div class="flex flex-wrap gap-2 mt-4">
                <a href="{{ route('settings') }}" class="{{ $btnSecondary }}"><i class="fas fa-gear"></i> Ajustes</a>
                <a href="{{ route('settings.health') }}" class="{{ $btnSecondary }}"><i class="fas fa-heart-pulse"></i> Saúde do sistema</a>
            </div>
        @endcan
    </div>

    <div class="grid gap-3 grid-cols-2 lg:grid-cols-4">
        @foreach ($this->stages() as $stage)
            <div class="{{ $cardClass }} p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $stage }}</p>
                <p class="font-display font-extrabold text-3xl mt-1 text-gray-300 dark:text-gray-600">0</p>
            </div>
        @endforeach
    </div>
</div>
