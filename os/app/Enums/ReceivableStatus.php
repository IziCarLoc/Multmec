<?php

namespace App\Enums;

/**
 * Situação de um lançamento do "A receber". Não é gravada: sai dos pagamentos e da baixa
 * (App\Models\Receivable::status()).
 */
enum ReceivableStatus: string
{
    case Aberto = 'aberto';
    case Parcial = 'parcial';
    case Recebido = 'recebido';
    case Prejuizo = 'prejuizo';

    public function label(): string
    {
        return match ($this) {
            self::Aberto => 'Em aberto',
            self::Parcial => 'Parcial',
            self::Recebido => 'Recebido',
            self::Prejuizo => 'Baixado como prejuízo',
        };
    }

    /** Ainda falta receber (aberto ou parcial). */
    public function isOpen(): bool
    {
        return $this === self::Aberto || $this === self::Parcial;
    }
}
