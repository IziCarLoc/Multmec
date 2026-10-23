<?php

namespace App\Services\Finance;

use App\Support\Money;
use DomainException;

/**
 * Conferência dos valores e datas que chegam das telas do Financeiro (mensagens em português).
 */
final class FinanceInput
{
    /** Teto de um valor digitado (R$ 10 milhões): o que passa disso é erro de digitação. */
    public const MAX_CENTS = 1_000_000_000;

    /** Centavos (inteiro) ou texto digitado como se escreve ("1.234" = R$ 1.234,00, pelo Money::parseBr). Vazio = 0. */
    public static function cents(mixed $value, string $field): int
    {
        $cents = match (true) {
            is_int($value) => $value,
            is_string($value) && trim($value) !== '' => Money::parseBr($value) ?? 0,
            default => 0,
        };

        if ($cents < 0) {
            throw new DomainException('O valor de '.$field.' não pode ser negativo.');
        }

        if ($cents > self::MAX_CENTS) {
            throw new DomainException('O valor de '.$field.' está alto demais: confira a digitação.');
        }

        return $cents;
    }

    /** Data "AAAA-MM-DD" válida (a do campo de data do navegador). */
    public static function date(string $value, string $message): string
    {
        $value = trim($value);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new DomainException($message);
        }

        return $value;
    }
}
