<?php

namespace App\Enums;

/**
 * Tipo do cliente para o crédito a prazo (o mesmo do financeiro em Node). Avulso paga na retirada; frota, locadora
 * e revenda compram a prazo com prazo e limite (App\Services\Finance\Credit).
 */
enum CreditKind: string
{
    case Avulso = 'avulso';
    case Frota = 'frota';
    case Locadora = 'locadora';
    case Revenda = 'revenda';

    public function label(): string
    {
        return match ($this) {
            self::Avulso => 'Avulso (paga na retirada)',
            self::Frota => 'Empresa / frota a prazo',
            self::Locadora => 'Locadora',
            self::Revenda => 'Revenda / garagem',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $kind) => [$kind->value => $kind->label()])->all();
    }
}
