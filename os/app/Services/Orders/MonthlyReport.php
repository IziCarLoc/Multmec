<?php

namespace App\Services\Orders;

use App\Models\Budget;
use App\Models\ServiceOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "Por mês": as OS do mês pela data de entrada ou de entrega, com o valor aprovado (orçamento + adendos) e a
 * situação da nota. Uma consulta só (o valor aprovado vem somado na própria consulta).
 */
final class MonthlyReport
{
    public const MODES = ['entrada' => 'Data de entrada', 'entrega' => 'Data de entrega'];

    /** @return Collection<int, ServiceOrder> */
    public function orders(CarbonImmutable $month, string $mode): Collection
    {
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();

        return ServiceOrder::query()
            ->with(['vehicle', 'customer'])
            ->withSum(['budgets as approved_cents' => fn ($q) => $q->where('status', Budget::STATUS_APPROVED)], 'approved_total_cents')
            ->when($mode === 'entrega',
                fn ($q) => $q->whereBetween('delivered_at', [$start->startOfDay(), $end->endOfDay()])->orderBy('delivered_at'),
                fn ($q) => $q->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()])->orderBy('entry_date'))
            ->orderBy('number')
            ->get();
    }

    public static function month(?string $value): CarbonImmutable
    {
        return preg_match('/^\d{4}-\d{2}$/', (string) $value) === 1 ? CarbonImmutable::createFromFormat('Y-m-d', $value.'-01')->startOfDay() : CarbonImmutable::now()->startOfMonth();
    }
}
