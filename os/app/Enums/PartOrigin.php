<?php

namespace App\Enums;

/**
 * Origem da peça (vai no orçamento; peça não original precisa de autorização do cliente no aceite).
 * "Do cliente" e "estoque" não são cotadas.
 */
enum PartOrigin: string
{
    case Genuina = 'genuina';
    case Original = 'original';
    case Paralela = 'paralela';
    case Usada = 'usada';
    case Cliente = 'cliente';
    case Estoque = 'estoque';

    public function label(): string
    {
        return match ($this) {
            self::Genuina => 'Genuína (da montadora)',
            self::Original => 'Original (fabricante de equipamento original)',
            self::Paralela => 'Paralela',
            self::Usada => 'Usada',
            self::Cliente => 'Trazida pelo cliente',
            self::Estoque => 'Do estoque',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Genuina => 'Genuína',
            self::Original => 'Original',
            self::Paralela => 'Paralela',
            self::Usada => 'Usada',
            self::Cliente => 'Do cliente',
            self::Estoque => 'Estoque',
        };
    }

    /** Não precisa cotar com fornecedor. */
    public function skipsQuote(): bool
    {
        return $this === self::Cliente || $this === self::Estoque;
    }

    /** Peça que não é genuína nem original: o cliente autoriza no aceite. */
    public function needsAuthorization(): bool
    {
        return $this === self::Paralela || $this === self::Usada;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $o) => [$o->value => $o->label()])->all();
    }
}
