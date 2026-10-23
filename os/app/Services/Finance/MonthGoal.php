<?php

namespace App\Services\Finance;

use App\Enums\ExpenseCategory;
use App\Models\Payable;
use App\Models\ProviderPayment;
use App\Models\ReceivablePayment;
use App\Services\Import\SpreadsheetImport;
use App\Support\BusinessDays;
use Carbon\CarbonImmutable;

/**
 * Meta do mês (o termômetro, a cascata, o ponto de equilíbrio, o caixa e o simulador do financeiro em Node).
 *  - Faturamento: Revenue (A receber por competência, sem matriz e sem saldo anterior).
 *  - Folha, custos fixos e outros: contas do "A pagar" com categoria (as contas fixas) que vencem no mês.
 *  - Percentuais, meta e retirada: MoneyRules. Percentual em branco conta como zero e aparece como "preencher".
 * Dinheiro sempre em centavos.
 */
final class MonthGoal
{
    public function __construct(private Revenue $revenue, private MoneyRules $rules, private FinanceSummary $summary) {}

    /**
     * Ritmo, projeção e quanto falta por dia útil (o `termometro` do Node).
     *
     * @return array<string, mixed>
     */
    public function thermometer(string $month, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $r = $this->revenue->month($month);
        $target = $this->rules->values()['target_cents'];
        $days = BusinessDays::forMonth($month);
        $todayKey = $today->toDateString();
        $total = BusinessDays::total($days);
        $until = BusinessDays::total($days, null, $todayKey);
        $after = BusinessDays::total($days, $today->addDay()->toDateString());
        $current = Month::current($today) === $month;
        $past = Month::current($today) > $month;
        $counted = $past ? $total : $until;
        $rhythm = $counted > 0 ? $r['revenue'] / $counted : 0;
        $projectedOrders = $past ? $r['orders'] : ($counted > 0 ? (int) round($r['orders'] / $counted * $total) : 0);

        return [
            'month' => $month,
            'target' => $target,
            'revenue' => $r['revenue'],
            'pct' => $target ? $r['revenue'] / $target : null,
            'orders' => $r['orders'],
            'ticket' => $r['ticket'],
            'days_total' => $total,
            'days_passed' => $until,
            'days_left' => $current ? $after : ($past ? 0.0 : $total),
            'rhythm' => (int) round($rhythm),
            'projection' => $past ? $r['revenue'] : (int) round($rhythm * $total),
            'needed_per_day' => $current && $after > 0 && $target ? (int) round(max(0, $target - $r['revenue']) / $after) : null,
            'target_per_day' => $total > 0 && $target ? (int) round($target / $total) : null,
            'orders_needed' => $r['ticket'] > 0 && $target ? (int) ceil($target / $r['ticket']) : null,
            'orders_projected' => $projectedOrders ?: null,
            'ticket_needed' => $projectedOrders > 0 && $target ? (int) round($target / $projectedOrders) : null,
            // Onde a barra deveria estar hoje (meta × dias úteis passados ÷ total).
            'expected' => $current && $total > 0 && $target ? (int) round($target * $until / $total) : null,
            // Com 1 ou 2 dias úteis a projeção é ruído.
            'early' => $current && $until < 3,
            'current' => $current,
            'past' => $past,
        ];
    }

    /**
     * Para onde vai o dinheiro do mês: faturamento → custos da venda → impostos e maquininha → folha → fixos e outros
     * → resultado → reserva → disponível para os sócios (a `cascata` do Node).
     *
     * @return array<string, mixed>
     */
    public function cascade(string $month): array
    {
        $r = $this->revenue->month($month);
        $rules = $this->rules->values();
        $cmv = $this->revenue->measuredCmv($month);
        // OS sem custo informado: estima pelo custo médio para não inflar a sobra.
        $estimated = (int) round($r['no_cost_revenue'] * $cmv['pct']);
        $costs = $r['costs'] + $estimated;
        $taxes = (int) round($r['revenue'] * (($rules['tax_pct'] ?? 0) + ($rules['card_fee_pct'] ?? 0)) / 100);
        $contribution = $r['revenue'] - $costs - $taxes;
        $bills = $this->bills($month);
        $result = $contribution - $bills['folha'] - $bills['fixo'] - $bills['outros'];
        $reserve = max(0, (int) round($r['revenue'] * ($rules['reserve_pct'] ?? 0) / 100));

        return [
            'month' => $month,
            'revenue' => $r['revenue'],
            'costs' => $costs,
            'estimated' => $estimated,
            'no_cost_orders' => $r['no_cost_orders'],
            'taxes' => $taxes,
            'contribution' => $contribution,
            'payroll' => $bills['folha'],
            'fixed' => $bills['fixo'],
            'other' => $bills['outros'],
            'tax_bills' => $bills['imposto'],
            'no_bills' => $bills['count'] === 0,
            'result' => $result,
            'reserve' => $reserve,
            'partners' => $result - $reserve,
            'withdrawn' => $bills['socios_pagos'],
            'withdrawal_target' => $rules['withdrawal_cents'],
            'cmv_pct' => $cmv['pct'],
            'cmv_basis' => $cmv['basis'],
            'missing' => array_values(array_filter([
                $rules['tax_pct'] === null ? 'impostos (%)' : null,
                $rules['card_fee_pct'] === null ? 'maquininha (%)' : null,
                $rules['reserve_pct'] === null ? 'reserva (%)' : null,
            ])),
        ];
    }

    /**
     * Quanto faturar no mês para cobrir folha, fixos e outros (e, com a retirada, também ela): custos fixos ÷
     * margem de contribuição (a fixa dos Ajustes ou 1 − custo medido − imposto − maquininha − reserva).
     *
     * @return array{fixed: int, margin_pct: float, margin_basis: string, break_even: ?int, with_withdrawal: ?int, withdrawal: int}
     */
    public function breakEven(string $month, ?int $withdrawalCents = null): array
    {
        $rules = $this->rules->values();
        $bills = $this->bills($month);
        $fixed = $bills['folha'] + $bills['fixo'] + $bills['outros'];
        $withdrawal = $withdrawalCents ?? ($rules['withdrawal_cents'] ?? 0);

        if ($rules['contribution_margin_pct'] !== null) {
            $margin = $rules['contribution_margin_pct'] / 100;
            $basis = 'margem fixa dos Ajustes';
        } else {
            $cmv = $this->revenue->measuredCmv($month);
            $margin = 1 - $cmv['pct'] - (($rules['tax_pct'] ?? 0) + ($rules['card_fee_pct'] ?? 0) + ($rules['reserve_pct'] ?? 0)) / 100;
            $basis = 'custo das peças '.$cmv['basis'];
        }

        return [
            'fixed' => $fixed,
            'margin_pct' => $margin,
            'margin_basis' => $basis,
            'break_even' => $margin > 0 && $fixed > 0 ? (int) round($fixed / $margin) : null,
            'with_withdrawal' => $margin > 0 && $fixed + $withdrawal > 0 ? (int) round(($fixed + $withdrawal) / $margin) : null,
            'withdrawal' => $withdrawal,
        ];
    }

    /**
     * Caixa real a partir do saldo do banco num dia (o `caixa` do Node): o que entrou de cliente (sem a matriz e sem
     * a quitação histórica da importação da planilha) menos boletos e prestadores pagos, depois daquele dia.
     *
     * @return array{configured: bool, balance: ?int, in: int, out: int, since: ?string}
     */
    public function cash(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $rules = $this->rules->values();
        $since = $rules['cash_opening_date'];

        if ($since === null) {
            return ['configured' => false, 'balance' => null, 'in' => 0, 'out' => 0, 'since' => null];
        }

        $to = $today->toDateString();
        $in = (int) ReceivablePayment::query()
            ->where('paid_on', '>', $since)->where('paid_on', '<=', $to)
            ->where(fn ($q) => $q->whereNull('legacy_id')->orWhere('legacy_id', 'not like', SpreadsheetImport::PREFIX.'%'))
            ->whereHas('receivable', fn ($q) => $q->counted())
            ->sum('amount_cents');
        $out = (int) Payable::query()->paid()->where('paid_on', '>', $since)->where('paid_on', '<=', $to)->sum('amount_cents')
            + (int) ProviderPayment::query()->paid()->where('paid_on', '>', $since)->where('paid_on', '<=', $to)->sum('amount_cents');

        return ['configured' => true, 'balance' => ($rules['cash_opening_cents'] ?? 0) + $in - $out, 'in' => $in, 'out' => $out, 'since' => $since];
    }

    /**
     * O que a meta exige combinando OS e ticket (o `simulador` do Node).
     *
     * @return array{revenue: int, contribution: int, surplus: int, missing: int, ticket_for_target: ?int, orders_for_target: ?int}
     */
    public static function simulate(int $orders, int $ticketCents, int $targetCents, float $marginPct, int $fixedCents): array
    {
        $revenue = max(0, $orders) * max(0, $ticketCents);
        $contribution = (int) round($revenue * $marginPct);

        return [
            'revenue' => $revenue,
            'contribution' => $contribution,
            'surplus' => $contribution - $fixedCents,
            'missing' => max(0, $targetCents - $revenue),
            'ticket_for_target' => $orders > 0 && $targetCents > 0 ? (int) round($targetCents / $orders) : null,
            'orders_for_target' => $ticketCents > 0 && $targetCents > 0 ? (int) ceil($targetCents / $ticketCents) : null,
        ];
    }

    /**
     * Mês a mês (o `serieMensal` do Node): faturamento por competência e o recebido e o pago no mês (pela data do
     * pagamento, como o "Por mês" do Painel). Do mais antigo ao mais novo.
     *
     * @return list<array<string, mixed>>
     */
    public function series(string $lastMonth, int $count = 13): array
    {
        $movement = collect($this->summary->months())->keyBy('month');
        $out = [];

        for ($i = $count - 1; $i >= 0; $i--) {
            $month = Month::shift($lastMonth, -$i);
            $out[] = $this->revenue->month($month) + [
                'received' => (int) ($movement[$month]['received'] ?? 0),
                'paid' => (int) ($movement[$month]['paid'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Contas com categoria que vencem no mês, somadas por categoria (sócios: só as já pagas, o "já retirado").
     *
     * @return array{folha: int, fixo: int, outros: int, imposto: int, socios_pagos: int, count: int}
     */
    private function bills(string $month): array
    {
        [$from, $to] = Month::bounds($month);
        $rows = Payable::query()->whereNotNull('category')->whereBetween('due_date', [$from, $to])->get(['category', 'amount_cents', 'status']);
        $sum = fn (ExpenseCategory $c, bool $paidOnly = false) => (int) $rows->filter(fn (Payable $p) => $p->category === $c && (! $paidOnly || $p->isPaid()))->sum('amount_cents');

        return [
            'folha' => $sum(ExpenseCategory::Folha),
            'fixo' => $sum(ExpenseCategory::Fixo),
            'outros' => $sum(ExpenseCategory::Outros),
            'imposto' => $sum(ExpenseCategory::Imposto),
            'socios_pagos' => $sum(ExpenseCategory::Socios, true),
            'count' => $rows->filter(fn (Payable $p) => in_array($p->category, [ExpenseCategory::Folha, ExpenseCategory::Fixo, ExpenseCategory::Outros], true))->count(),
        ];
    }
}
