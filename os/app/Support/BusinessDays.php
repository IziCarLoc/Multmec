<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Dias úteis do mês (o `diasUteis` do financeiro em Node): segunda a sexta valem 1, o sábado vale o peso dos
 * Ajustes (0, meio dia ou dia inteiro) e feriado não conta. Base do ritmo e do "quanto falta por dia útil".
 *
 * Feriados:
 *  - nacionais de data fixa (Leis 662/1949, 6.802/1980 e 14.759/2023), sempre;
 *  - os que dependem da Páscoa (Carnaval, Sexta-feira Santa, Corpus Christi) quando marcados nos Ajustes;
 *  - os outros dias que o gestor informar (estaduais, municipais, pontes). O financeiro em Node não trazia
 *    feriado nenhum pronto: todos eram digitados.
 */
final class BusinessDays
{
    /** Feriados que dependem da Páscoa (chave => nome). */
    public const MOVABLE = [
        'carnaval' => 'Carnaval (segunda e terça)',
        'sexta_santa' => 'Sexta-feira Santa',
        'corpus_christi' => 'Corpus Christi',
    ];

    /** Nacionais de data fixa (mês-dia => nome). */
    private const FIXED = [
        '01-01' => 'Confraternização Universal',
        '04-21' => 'Tiradentes',
        '05-01' => 'Dia do Trabalho',
        '09-07' => 'Independência',
        '10-12' => 'Nossa Senhora Aparecida',
        '11-02' => 'Finados',
        '11-15' => 'Proclamação da República',
        '11-20' => 'Consciência Negra',
        '12-25' => 'Natal',
    ];

    /** Domingo de Páscoa (calendário gregoriano, algoritmo de Meeus/Jones/Butcher). */
    public static function easter(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day)->startOfDay();
    }

    /**
     * Feriados do ano: os nacionais fixos, os da Páscoa pedidos e os extras (datas AAAA-MM-DD).
     *
     * @param  list<string>  $movable  chaves de MOVABLE
     * @param  list<string>  $extra
     * @return array<string, string> data => nome, em ordem
     */
    public static function holidays(int $year, array $movable = [], array $extra = []): array
    {
        $out = [];

        foreach (self::FIXED as $monthDay => $name) {
            // Consciência Negra é feriado nacional desde 2024 (Lei 14.759/2023).
            if ($monthDay === '11-20' && $year < 2024) {
                continue;
            }

            $out[$year.'-'.$monthDay] = $name;
        }

        $easter = self::easter($year);
        $byEaster = [
            'carnaval' => [[-48, 'Carnaval'], [-47, 'Carnaval']],
            'sexta_santa' => [[-2, 'Sexta-feira Santa']],
            'corpus_christi' => [[60, 'Corpus Christi']],
        ];

        foreach ($movable as $key) {
            foreach ($byEaster[$key] ?? [] as [$offset, $name]) {
                $out[$easter->addDays($offset)->toDateString()] = $name;
            }
        }

        foreach ($extra as $date) {
            if (is_string($date) && str_starts_with($date, $year.'-')) {
                $out[$date] ??= 'Sem expediente';
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * Dias úteis do mês com o peso de cada um (o `diasUteis(ym, feriados, sabadoConta)` do Node).
     *
     * @param  list<string>  $holidays  datas AAAA-MM-DD que não contam
     * @return list<array{date: string, weight: float}>
     */
    public static function month(string $month, array $holidays = [], float $saturdayWeight = 0): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        $days = [];

        for ($day = $start; $day->format('Y-m') === $month; $day = $day->addDay()) {
            $date = $day->toDateString();

            if (in_array($date, $holidays, true)) {
                continue;
            }

            $weekday = $day->dayOfWeek; // 0 domingo … 6 sábado

            if ($weekday >= 1 && $weekday <= 5) {
                $days[] = ['date' => $date, 'weight' => 1.0];
            } elseif ($weekday === 6 && $saturdayWeight > 0) {
                $days[] = ['date' => $date, 'weight' => $saturdayWeight];
            }
        }

        return $days;
    }

    /**
     * Dias úteis do mês pelos Ajustes (sábado, feriados da Páscoa marcados e os dias extras).
     *
     * @return list<array{date: string, weight: float}>
     */
    public static function forMonth(string $month): array
    {
        $year = (int) substr($month, 0, 4);
        $holidays = array_keys(self::holidays($year, self::configuredMovable(), self::configuredExtra()));

        return self::month($month, $holidays, self::configuredSaturday());
    }

    /** @param  list<array{date: string, weight: float}>  $days */
    public static function total(array $days, ?string $from = null, ?string $to = null): float
    {
        $sum = 0.0;

        foreach ($days as $day) {
            if (($from === null || $day['date'] >= $from) && ($to === null || $day['date'] <= $to)) {
                $sum += $day['weight'];
            }
        }

        return $sum;
    }

    /** @return list<string> */
    public static function configuredMovable(): array
    {
        $value = Settings::get('finance.movable_holidays');

        return array_values(array_intersect(is_array($value) ? $value : [], array_keys(self::MOVABLE)));
    }

    /** @return list<string> */
    public static function configuredExtra(): array
    {
        $value = Settings::get('finance.extra_holidays');

        return array_values(array_filter(is_array($value) ? $value : [], fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1));
    }

    public static function configuredSaturday(): float
    {
        $value = (float) Settings::get('finance.saturday_weight');

        return in_array($value, [0.0, 0.5, 1.0], true) ? $value : 0.0;
    }
}
