<?php

namespace App\Services\Finance;

use App\Models\Customer;
use App\Models\Receivable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Inadimplentes: cada cliente com algum lançamento vencido no "A receber" (parcial também conta), com o valor
 * integral que ele tem em aberto (todas as parcelas e todos os meses, vencidas ou não). Recebimento pela matriz
 * não entra (a cobrança é da matriz).
 */
final class Delinquency
{
    /**
     * @return Collection<int, array{key: string, customer: ?Customer, name: string, open_cents: int, overdue_cents: int, overdue_count: int, days_late: int, overdue: Collection<int, Receivable>}>
     */
    public function customers(?CarbonImmutable $today = null): Collection
    {
        $today ??= CarbonImmutable::today();

        return Receivable::query()->open()->counted()->with('customer')->orderBy('due_date')->orderBy('id')->get()
            ->groupBy(fn (Receivable $r) => (string) ($r->customer_id ?? 0))
            ->map(function (Collection $items, string $key) use ($today) {
                $overdue = $items->filter(fn (Receivable $r) => $r->isOverdue($today))->values();

                if ($overdue->isEmpty()) {
                    return null;
                }

                $customer = $items->first()->customer;

                return [
                    'key' => $key,
                    'customer' => $customer,
                    'name' => $customer?->name ?? '(sem cliente)',
                    'open_cents' => $items->sum(fn (Receivable $r) => $r->balanceCents()),
                    'overdue_cents' => $overdue->sum(fn (Receivable $r) => $r->balanceCents()),
                    'overdue_count' => $overdue->count(),
                    'days_late' => $overdue->max(fn (Receivable $r) => $r->daysLate($today)),
                    'overdue' => $overdue,
                ];
            })
            ->filter()
            ->sortByDesc('open_cents')
            ->values();
    }

    /** Total inadimplente: o valor integral em aberto dos clientes inadimplentes. */
    public function totalCents(?CarbonImmutable $today = null): int
    {
        return (int) $this->customers($today)->sum('open_cents');
    }
}
