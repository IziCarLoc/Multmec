<?php

namespace App\Livewire\Concerns;

use DomainException;

/**
 * Regra de negócio barrada (DomainException) vira erro para o operador num lugar só: aviso vermelho
 * (toast), ou a mensagem no campo informado (o formulário continua aberto).
 *
 *     if (! $os = $this->attempt(fn () => $machine->move($os, $to, $user), 'stage')) {
 *         return;
 *     }
 */
trait RunsDomainActions
{
    /**
     * Roda a ação e devolve o que ela devolveu (`true` quando ela não devolve nada); `null` quando a
     * regra de negócio barrou e o erro já foi mostrado. Ação que devolve `false` conta como barrada
     * para quem testa só a verdade do retorno: devolva um objeto ou nada.
     */
    protected function attempt(callable $action, ?string $errorField = null): mixed
    {
        try {
            $result = $action();
        } catch (DomainException $e) {
            $errorField !== null
                ? $this->addError($errorField, $e->getMessage())
                : $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return null;
        }

        return $result ?? true;
    }
}
