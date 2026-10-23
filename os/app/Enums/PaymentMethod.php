<?php

namespace App\Enums;

/**
 * Forma de pagamento do cliente no "A receber" (as mesmas opções do artefato do Financeiro).
 */
enum PaymentMethod: string
{
    case Pix = 'pix';
    case Cartao = 'cartao';
    case Boleto = 'boleto';
    case Dinheiro = 'dinheiro';
    case Outro = 'outro';

    public function label(): string
    {
        return match ($this) {
            self::Pix => 'PIX',
            self::Cartao => 'Cartão',
            self::Boleto => 'Boleto',
            self::Dinheiro => 'Dinheiro',
            self::Outro => 'Outro',
        };
    }

    /** @return array<string, string> valor => rótulo, na ordem das telas */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $method) => [$method->value => $method->label()])->all();
    }
}
