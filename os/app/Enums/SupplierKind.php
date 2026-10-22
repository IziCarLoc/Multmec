<?php

namespace App\Enums;

/**
 * O que o fornecedor faz para a oficina: vende peça, presta serviço (prestador externo: eletricista,
 * ar-condicionado, retífica...) ou os dois.
 */
enum SupplierKind: string
{
    case Pecas = 'pecas';
    case Servico = 'servico';
    case Ambos = 'ambos';

    public function label(): string
    {
        return match ($this) {
            self::Pecas => 'Peças',
            self::Servico => 'Prestador de serviço',
            self::Ambos => 'Peças e serviço',
        };
    }

    public function sellsParts(): bool
    {
        return $this !== self::Servico;
    }

    public function providesService(): bool
    {
        return $this !== self::Pecas;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $kind) => [$kind->value => $kind->label()])->all();
    }
}
