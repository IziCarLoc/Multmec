<?php

namespace App\Livewire\Concerns;

/**
 * Telas do Financeiro: só gestor e atendimento (a cada pedido, não só ao abrir). Lucro e margem só o gestor vê:
 * a tela pergunta `canSeeProfit()` antes de calcular ou mostrar qualquer lucro.
 */
trait FinanceAccess
{
    public function bootFinanceAccess(): void
    {
        abort_unless(auth()->user()?->can('atendimento'), 403);
    }

    protected function canSeeProfit(): bool
    {
        return (bool) auth()->user()?->can('gestor');
    }
}
