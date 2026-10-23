<?php

namespace App\Services\Purchases;

use DomainException;

/**
 * Pagamento travado pela conferência de compras: a mensagem para a pessoa e as ocorrências que travam (a tela mostra
 * a lista e pede o motivo para "pagar mesmo assim").
 */
final class PaymentBlocked extends DomainException
{
    /** @param  list<array<string, mixed>>  $occurrences */
    public function __construct(string $message, public readonly array $occurrences = [])
    {
        parent::__construct($message);
    }
}
