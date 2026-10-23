<?php

namespace App\Services\Finance;

use Carbon\CarbonImmutable;

/**
 * Mês do Financeiro como chave "AAAA-MM" (navegação das listas e tabela "Por mês").
 */
final class Month
{
    private const NAMES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

    public static function current(?CarbonImmutable $today = null): string
    {
        return ($today ?? CarbonImmutable::today())->format('Y-m');
    }

    public static function isValid(?string $key): bool
    {
        return is_string($key) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $key) === 1;
    }

    /** "out/2026". */
    public static function label(?string $key): string
    {
        if (! self::isValid($key)) {
            return 'sem data';
        }

        return self::NAMES[(int) substr($key, 5, 2) - 1].'/'.substr($key, 0, 4);
    }

    public static function shift(string $key, int $months): string
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $key.'-01')->startOfMonth()->addMonthsNoOverflow($months)->format('Y-m');
    }

    /** @return array{0: string, 1: string} primeiro e último dia do mês (Y-m-d) */
    public static function bounds(string $key): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $key.'-01')->startOfMonth();

        return [$start->toDateString(), $start->endOfMonth()->toDateString()];
    }

    /** "AAAA-MM" de uma data "AAAA-MM-DD" (ou nulo). */
    public static function of(mixed $date): ?string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m');
        }

        return is_string($date) && strlen($date) >= 7 ? substr($date, 0, 7) : null;
    }
}
