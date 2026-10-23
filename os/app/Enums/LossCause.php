<?php

namespace App\Enums;

/**
 * Causa do prejuízo (as do artefato). "Inadimplência" é a que nasce da baixa de um "A receber" vencido.
 */
enum LossCause: string
{
    case Avariada = 'avariada';
    case Erro = 'erro';
    case Perda = 'perda';
    case Inadimplencia = 'inadimplencia';
    case Outro = 'outro';

    public function label(): string
    {
        return match ($this) {
            self::Avariada => 'Peça avariada',
            self::Erro => 'Erro de serviço',
            self::Perda => 'Perda / furto',
            self::Inadimplencia => 'Inadimplência (serviço não pago)',
            self::Outro => 'Outro',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $cause) => [$cause->value => $cause->label()])->all();
    }
}
