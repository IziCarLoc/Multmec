<?php

namespace App\Services\Finance;

use DomainException;

/**
 * Divide um valor em parcelas em centavos, sem perder nem sobrar centavo: o resto vai, um centavo para cada,
 * nas primeiras parcelas (100,00 em 3 = 33,34 + 33,33 + 33,33).
 */
final class Installments
{
    public const MAX = 60;

    /** @return list<int> */
    public static function split(int $totalCents, int $parts): array
    {
        if ($parts < 1 || $parts > self::MAX) {
            throw new DomainException('O número de parcelas vai de 2 a '.self::MAX.'.');
        }

        $base = intdiv($totalCents, $parts);
        $rest = $totalCents - $base * $parts;

        return array_map(fn (int $i) => $base + ($i < $rest ? 1 : 0), range(0, $parts - 1));
    }
}
