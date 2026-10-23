<?php

namespace App\Services\Finance;

use App\Models\User;
use App\Support\BusinessDays;
use App\Support\Money;
use App\Support\Settings;
use DomainException;
use Illuminate\Support\Facades\Gate;

/**
 * Regras do dinheiro (o `config` do financeiro em Node): meta, retirada dos sócios, percentuais sobre o
 * faturamento, dias úteis, saldo do caixa e a trava do crédito. Ficam nos Ajustes (`finance.*`); o gestor edita na
 * tela Meta do mês (a trava, na tela Crédito). Valor em branco é "preencher": o sistema nunca supõe um número.
 */
final class MoneyRules
{
    /** Campos em percentual (9.5 = 9,5%). */
    public const PERCENTS = [
        'tax_pct' => 'Impostos sobre a venda (%)',
        'card_fee_pct' => 'Taxas de maquininha (%)',
        'reserve_pct' => 'Reserva da oficina (%)',
        'contribution_margin_pct' => 'Margem de contribuição fixa (%)',
    ];

    /**
     * @return array{target_cents: ?int, withdrawal_cents: ?int, tax_pct: ?float, card_fee_pct: ?float, reserve_pct: ?float, contribution_margin_pct: ?float, saturday_weight: float, movable_holidays: list<string>, extra_holidays: list<string>, cash_opening_cents: ?int, cash_opening_date: ?string, credit_lock_days: int, credit_warning_pct: float}
     */
    public function values(): array
    {
        $float = fn (string $key) => ($v = Settings::get('finance.'.$key)) === null || $v === '' ? null : (float) $v;
        $date = Settings::get('finance.cash_opening_date');

        return [
            'target_cents' => Settings::int('finance.target_cents'),
            'withdrawal_cents' => Settings::int('finance.withdrawal_cents'),
            'tax_pct' => $float('tax_pct'),
            'card_fee_pct' => $float('card_fee_pct'),
            'reserve_pct' => $float('reserve_pct'),
            'contribution_margin_pct' => $float('contribution_margin_pct'),
            'saturday_weight' => BusinessDays::configuredSaturday(),
            'movable_holidays' => BusinessDays::configuredMovable(),
            'extra_holidays' => BusinessDays::configuredExtra(),
            'cash_opening_cents' => Settings::int('finance.cash_opening_cents'),
            'cash_opening_date' => is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null,
            'credit_lock_days' => Settings::int('finance.credit_lock_days') ?? 15,
            'credit_warning_pct' => (float) (Settings::get('finance.credit_warning_pct') ?? 80),
        ];
    }

    /** O que falta preencher para a Meta do mês mostrar números de verdade. @return list<string> */
    public function missing(): array
    {
        $v = $this->values();

        return array_values(array_filter([
            $v['target_cents'] === null ? 'meta de faturamento' : null,
            $v['tax_pct'] === null ? 'impostos sobre a venda (%)' : null,
            $v['card_fee_pct'] === null ? 'taxas de maquininha (%)' : null,
            $v['reserve_pct'] === null ? 'reserva da oficina (%)' : null,
            $v['withdrawal_cents'] === null ? 'retirada desejada dos sócios' : null,
            $v['cash_opening_date'] === null ? 'saldo do banco num dia' : null,
        ]));
    }

    /**
     * Grava as regras da Meta do mês (só o gestor). Texto como digitado: dinheiro "1.234,56", percentual "9,5",
     * feriados extras "dd/mm/aaaa" ou "aaaa-mm-dd" separados por vírgula ou linha.
     *
     * @param  array<string, mixed>  $input
     */
    public function save(array $input, User $by): void
    {
        $this->assertGestor($by);

        $target = self::money($input['target'] ?? null, 'a meta');
        $withdrawal = self::money($input['withdrawal'] ?? null, 'a retirada dos sócios');
        $percents = [];

        foreach (self::PERCENTS as $key => $label) {
            $percents[$key] = self::percent($input[$key] ?? null, $label, $key !== 'contribution_margin_pct');
        }

        if (($percents['tax_pct'] ?? 0) + ($percents['card_fee_pct'] ?? 0) + ($percents['reserve_pct'] ?? 0) >= 100) {
            throw new DomainException('Imposto, maquininha e reserva somam 100% ou mais do faturamento: confira os percentuais.');
        }

        $saturday = (float) str_replace(',', '.', (string) ($input['saturday_weight'] ?? '0'));

        if (! in_array($saturday, [0.0, 0.5, 1.0], true)) {
            throw new DomainException('Sábado conta 0, meio dia ou o dia inteiro.');
        }

        $movable = array_values(array_intersect(array_map('strval', (array) ($input['movable_holidays'] ?? [])), array_keys(BusinessDays::MOVABLE)));
        $extra = self::dates((string) ($input['extra_holidays'] ?? ''));
        $cash = self::money($input['cash_opening'] ?? null, 'o saldo do caixa', allowNegative: true);
        $cashDate = trim((string) ($input['cash_opening_date'] ?? ''));
        $cashDate = $cashDate === '' ? null : FinanceInput::date($cashDate, 'Data do saldo do caixa inválida.');

        if ($cash !== null && $cashDate === null) {
            throw new DomainException('Informe a data do saldo do caixa (o dia em que o banco tinha esse valor).');
        }

        Settings::set('finance.target_cents', $target);
        Settings::set('finance.withdrawal_cents', $withdrawal);

        foreach ($percents as $key => $value) {
            Settings::set('finance.'.$key, $value);
        }

        Settings::set('finance.saturday_weight', $saturday);
        Settings::set('finance.movable_holidays', $movable);
        Settings::set('finance.extra_holidays', $extra);
        Settings::set('finance.cash_opening_cents', $cashDate === null ? null : ($cash ?? 0));
        Settings::set('finance.cash_opening_date', $cashDate);
    }

    /** A trava do crédito (só o gestor): dias de atraso e o aviso de uso do limite. */
    public function saveCredit(mixed $lockDays, mixed $warningPct, User $by): void
    {
        $this->assertGestor($by);
        $days = filter_var(trim((string) $lockDays), FILTER_VALIDATE_INT);

        if ($days === false || $days < 0 || $days > 365) {
            throw new DomainException('Dias de atraso para travar: de 0 a 365.');
        }

        $warning = self::percent($warningPct, 'Aviso de uso do limite (%)', false);

        if ($warning === null || $warning > 100) {
            throw new DomainException('Aviso de uso do limite: de 1% a 100%.');
        }

        Settings::set('finance.credit_lock_days', $days);
        Settings::set('finance.credit_warning_pct', $warning);
    }

    /** Percentual digitado ("9,5" ou "9.5") → 9.5; vazio = nulo. */
    public static function percent(mixed $value, string $label, bool $allowZero = true): ?float
    {
        $text = trim(str_replace(['%', ' '], '', (string) $value));

        if ($text === '') {
            return null;
        }

        $text = str_replace(',', '.', $text);

        if (! is_numeric($text)) {
            throw new DomainException($label.': use só números, por exemplo 9,5.');
        }

        $number = round((float) $text, 2);

        if ($number < 0 || $number >= 100 || (! $allowZero && $number == 0.0)) {
            throw new DomainException($label.': o valor fica entre '.($allowZero ? '0' : 'mais de 0').' e 100.');
        }

        return $number;
    }

    /**
     * Datas digitadas → lista AAAA-MM-DD sem repetir, em ordem.
     *
     * @return list<string>
     */
    public static function dates(string $text): array
    {
        $out = [];

        foreach (preg_split('/[\s,;]+/', trim($text)) ?: [] as $piece) {
            if ($piece === '') {
                continue;
            }

            if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $piece, $m) === 1) {
                $piece = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            }

            $out[] = FinanceInput::date($piece, 'Feriado inválido: '.$piece.' (use dd/mm/aaaa).');
        }

        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    private static function money(mixed $value, string $label, bool $allowNegative = false): ?int
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        $cents = Money::parseBr($text);

        if ($cents === null) {
            throw new DomainException('Confira '.$label.': use só números, por exemplo 1.234,56.');
        }

        if (! $allowNegative && $cents < 0) {
            throw new DomainException('O valor de '.$label.' não pode ser negativo.');
        }

        if (abs($cents) > FinanceInput::MAX_CENTS) {
            throw new DomainException('O valor de '.$label.' está alto demais: confira a digitação.');
        }

        return $cents;
    }

    private function assertGestor(User $by): void
    {
        if (! Gate::forUser($by)->allows('gestor')) {
            throw new DomainException('As regras do dinheiro são do gestor.');
        }
    }
}
