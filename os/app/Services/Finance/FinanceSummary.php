<?php

namespace App\Services\Finance;

use App\Enums\DueWindow;
use App\Models\Loss;
use App\Models\Payable;
use App\Models\ProviderPayment;
use App\Models\Receivable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Números do Painel do Financeiro.
 *  - Pago, recebido e prejuízo contam na data em que o boleto foi pago, o recebimento entrou ou o prejuízo foi
 *    lançado (não no vencimento).
 *  - Recebimento pela matriz fica à parte ("receita matriz"): não entra no saldo, no lucro nem no "a receber".
 *  - Lucro bruto do mês: a parte do lucro de cada serviço que já entrou (proporcional ao que foi pago).
 */
final class FinanceSummary
{
    public function __construct(private Profit $profit, private Delinquency $delinquency) {}

    /** @return array<string, array{count: int, cents: int}> por janela (DueWindow) */
    public function payableDeadlines(?CarbonImmutable $today = null): array
    {
        return $this->deadlines(fn () => Payable::query()->open(), 'amount_cents', $today);
    }

    /** @return array<string, array{count: int, cents: int}> */
    public function providerDeadlines(?CarbonImmutable $today = null): array
    {
        return $this->deadlines(fn () => ProviderPayment::query()->open(), 'amount_cents', $today);
    }

    /** Saldo em aberto (o que falta receber) por janela; sem o recebimento pela matriz. @return array<string, array{count: int, cents: int}> */
    public function receivableDeadlines(?CarbonImmutable $today = null): array
    {
        return $this->deadlines(fn () => Receivable::query()->open()->counted(), 'total_cents - paid_cents', $today);
    }

    /**
     * O que está em aberto agora.
     *
     * @return array{payables: int, providers: int, receivables: int, matrix: int, delinquency: int, projected: int}
     */
    public function openTotals(?CarbonImmutable $today = null): array
    {
        $payables = (int) Payable::query()->open()->sum('amount_cents');
        $providers = (int) ProviderPayment::query()->open()->sum('amount_cents');
        $receivables = (int) Receivable::query()->open()->counted()->sum(DB::raw('total_cents - paid_cents'));
        $matrix = (int) Receivable::query()->open()->where('received_by_matrix', true)->sum(DB::raw('total_cents - paid_cents'));

        return [
            'payables' => $payables,
            'providers' => $providers,
            'receivables' => $receivables,
            'matrix' => $matrix,
            'delinquency' => $this->delinquency->totalCents($today),
            'projected' => $receivables - $payables - $providers,
        ];
    }

    /**
     * Movimento por mês (o mais recente primeiro; o mês corrente sempre aparece).
     *
     * @return list<array{month: string, paid: int, received: int, loss: int, profit: int, matrix: int, balance: int}>
     */
    public function months(?CarbonImmutable $today = null): array
    {
        $rows = [];
        $add = function (mixed $date, string $field, int $cents) use (&$rows) {
            $month = Month::of($date);

            if ($month === null) {
                return;
            }

            $rows[$month] ??= ['month' => $month, 'paid' => 0, 'received' => 0, 'loss' => 0, 'profit' => 0, 'matrix' => 0, 'balance' => 0];
            $rows[$month][$field] += $cents;
        };

        foreach (Payable::query()->paid()->toBase()->get(['paid_on', 'amount_cents']) as $row) {
            $add($row->paid_on, 'paid', (int) $row->amount_cents);
        }

        foreach (ProviderPayment::query()->paid()->toBase()->get(['paid_on', 'amount_cents']) as $row) {
            $add($row->paid_on, 'paid', (int) $row->amount_cents);
        }

        foreach (Loss::query()->toBase()->get(['occurred_on', 'amount_cents']) as $row) {
            $add($row->occurred_on, 'loss', (int) $row->amount_cents);
        }

        $receivables = Receivable::query()->where('paid_cents', '>', 0)
            ->with('payments:id,receivable_id,paid_on,amount_cents')
            ->get(['id', 'total_cents', 'parts_cost_cents', 'labor_cents', 'freight_cents', 'customer_brought_part', 'received_by_matrix', 'service_order_id']);

        foreach ($receivables as $receivable) {
            foreach ($receivable->payments as $payment) {
                if ($receivable->received_by_matrix) {
                    $add($payment->paid_on, 'matrix', $payment->amount_cents);

                    continue;
                }

                $add($payment->paid_on, 'received', $payment->amount_cents);
                $add($payment->paid_on, 'profit', $this->profit->realizedBy($receivable, $payment->amount_cents));
            }
        }

        $add(Month::current($today).'-01', 'paid', 0);

        foreach ($rows as &$row) {
            $row['balance'] = $row['received'] - $row['paid'] - $row['loss'];
        }
        unset($row);

        krsort($rows);

        return array_values($rows);
    }

    /** @return array{month: string, paid: int, received: int, loss: int, profit: int, matrix: int, balance: int} */
    public function month(string $month, ?CarbonImmutable $today = null): array
    {
        foreach ($this->months($today) as $row) {
            if ($row['month'] === $month) {
                return $row;
            }
        }

        return ['month' => $month, 'paid' => 0, 'received' => 0, 'loss' => 0, 'profit' => 0, 'matrix' => 0, 'balance' => 0];
    }

    /**
     * @param  callable(): Builder  $base
     * @return array<string, array{count: int, cents: int}>
     */
    private function deadlines(callable $base, string $amount, ?CarbonImmutable $today): array
    {
        $out = [];

        foreach (DueWindow::cases() as $window) {
            $row = $window->constrain($base(), $today)->toBase()->selectRaw('count(*) as n, coalesce(sum('.$amount.'), 0) as cents')->first();
            $out[$window->value] = ['count' => (int) ($row->n ?? 0), 'cents' => (int) ($row->cents ?? 0)];
        }

        return $out;
    }
}
