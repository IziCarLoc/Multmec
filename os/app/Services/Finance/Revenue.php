<?php

namespace App\Services\Finance;

use App\Models\Receivable;
use Illuminate\Support\Collection;

/**
 * Faturamento por competência (o `resumoMes` do financeiro em Node), a partir do "A receber":
 *  - conta o que entra no saldo (`Receivable::billed()`: sem o recebimento pela matriz e sem o "saldo anterior");
 *  - na data do serviço (`service_date`; sem ela, o vencimento);
 *  - uma OS = um serviço: as parcelas se juntam (mesma OS ou mesmo grupo de parcelas);
 *  - custos e lucro pelas regras do Profit (peças, frete e prestador; "cliente trouxe a peça" zera as peças).
 * OS com mais de R$ 50 além da mão de obra e sem custo de peça é "sem custo": a cascata estima o custo dela.
 */
final class Revenue
{
    /** Acima disto (além da mão de obra) a OS tem peça: sem custo informado, fica sinalizada (regra do Node). */
    public const NO_COST_THRESHOLD_CENTS = 5000;

    /** Custo das peças sobre o faturamento sem histórico confiável: os ~33% medidos na planilha (docs/02). */
    public const DEFAULT_CMV = 0.33;

    /** @var array<string, array{pct: float, basis: string, measured: bool}> */
    private array $cmv = [];

    public function __construct(private Profit $profit) {}

    /**
     * Serviços faturados no período (Y-m-d), uma linha por OS.
     *
     * @return Collection<int, array{key: string, customer_id: ?int, customer: string, service_order_id: ?int, order_number: string, date: string, plate: ?string, vehicle_model: ?string, total: int, labor: int, parts: int, freight: int, provider: int, gross: int, part_profit: int, brought: bool, no_cost: bool}>
     */
    public function orders(string $from, string $to): Collection
    {
        $receivables = Receivable::query()->billed()->serviceBetween($from, $to)->with('customer:id,name')->orderBy('id')->get();
        $providers = $this->profit->providerCosts($receivables);

        return $receivables
            ->groupBy(fn (Receivable $r) => $r->service_order_id ? 'os'.$r->service_order_id : ($r->installment_group ? 'g'.$r->installment_group : 'r'.$r->id))
            ->map(function (Collection $items, string $key) use ($providers) {
                /** @var Receivable $first */
                $first = $items->first();
                $row = ['total' => 0, 'labor' => 0, 'parts' => 0, 'freight' => 0, 'provider' => 0, 'gross' => 0, 'part_profit' => 0];

                foreach ($items as $receivable) {
                    $parts = $this->profit->partsCostOf($receivable);
                    $provider = $providers[$receivable->id] ?? 0;
                    $row['total'] += $receivable->total_cents;
                    $row['labor'] += $receivable->labor_cents;
                    $row['parts'] += $parts;
                    $row['freight'] += $receivable->freight_cents;
                    $row['provider'] += $provider;
                    $row['gross'] += $this->profit->gross($receivable->total_cents, $parts, $receivable->freight_cents, $provider);
                    $row['part_profit'] += $this->profit->part($receivable->total_cents, $receivable->labor_cents, $parts, $receivable->freight_cents);
                }

                $brought = $items->contains(fn (Receivable $r) => $r->customer_brought_part);

                return $row + [
                    'key' => $key,
                    'customer_id' => $first->customer_id,
                    'customer' => $first->customer?->name ?? '(sem cliente)',
                    'service_order_id' => $first->service_order_id,
                    'order_number' => (string) $first->order_number,
                    'date' => $items->map(fn (Receivable $r) => $r->serviceDay()->toDateString())->min(),
                    'plate' => $first->plate,
                    'vehicle_model' => $first->vehicle_model,
                    'brought' => $brought,
                    'no_cost' => ! $brought && $row['parts'] === 0 && $row['total'] - $row['labor'] > self::NO_COST_THRESHOLD_CENTS,
                ];
            })
            ->values();
    }

    /**
     * Totais do mês (AAAA-MM).
     *
     * @return array{month: string, orders: int, revenue: int, ticket: int, labor: int, parts: int, freight: int, provider: int, costs: int, gross: int, gross_pct: float, part_profit: int, no_cost_orders: int, no_cost_revenue: int}
     */
    public function month(string $month): array
    {
        [$from, $to] = Month::bounds($month);

        return $this->summarize($this->orders($from, $to), $month);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $orders
     * @return array{month: string, orders: int, revenue: int, ticket: int, labor: int, parts: int, freight: int, provider: int, costs: int, gross: int, gross_pct: float, part_profit: int, no_cost_orders: int, no_cost_revenue: int}
     */
    public function summarize(Collection $orders, string $month = ''): array
    {
        $sum = fn (string $field) => (int) $orders->sum($field);
        $revenue = $sum('total');
        $costs = $sum('parts') + $sum('freight') + $sum('provider');
        $noCost = $orders->where('no_cost', true);

        return [
            'month' => $month,
            'orders' => $orders->count(),
            'revenue' => $revenue,
            'ticket' => $orders->count() > 0 ? (int) round($revenue / $orders->count()) : 0,
            'labor' => $sum('labor'),
            'parts' => $sum('parts'),
            'freight' => $sum('freight'),
            'provider' => $sum('provider'),
            'costs' => $costs,
            'gross' => $sum('gross'),
            'gross_pct' => $revenue > 0 ? $sum('gross') / $revenue : 0.0,
            'part_profit' => $sum('part_profit'),
            'no_cost_orders' => $noCost->count(),
            'no_cost_revenue' => (int) $noCost->sum('total'),
        ];
    }

    /**
     * Custo de peças, frete e prestador sobre o faturamento nos últimos meses fechados confiáveis (20 OS ou mais,
     * no máximo 10% sem custo): a média de até 3 meses dos últimos 12. Sem histórico, os 33% da planilha.
     *
     * @return array{pct: float, basis: string, measured: bool}
     */
    public function measuredCmv(string $month, int $months = 3): array
    {
        return $this->cmv[$month.'|'.$months] ??= $this->measureCmv($month, $months);
    }

    /** @return array{pct: float, basis: string, measured: bool} */
    private function measureCmv(string $month, int $months): array
    {
        $pcts = [];

        for ($i = 1; $i <= 12 && count($pcts) < $months; $i++) {
            $r = $this->month(Month::shift($month, -$i));

            if ($r['orders'] >= 20 && $r['revenue'] > 0 && $r['no_cost_orders'] / $r['orders'] <= 0.10) {
                $pcts[] = $r['costs'] / $r['revenue'];
            }
        }

        if ($pcts === []) {
            return ['pct' => self::DEFAULT_CMV, 'basis' => '33% da planilha (ainda sem 3 meses confiáveis no sistema)', 'measured' => false];
        }

        return ['pct' => array_sum($pcts) / count($pcts), 'basis' => 'média de '.count($pcts).' mês(es) fechado(s)', 'measured' => true];
    }
}
