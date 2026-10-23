<?php

namespace App\Models;

use App\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pagamento a prestador externo por OS realizada (o "Douglas" do artefato). Pago = tem `paid_on`.
 * Regras em App\Services\Finance\ProviderPayments.
 */
class ProviderPayment extends Model
{
    protected $fillable = [
        'legacy_id',
        'supplier_id',
        'service_order_id',
        'order_number',
        'description',
        'plate',
        'amount_cents',
        'due_date',
        'paid_on',
        'paid_by',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => DateOnly::class,
            'paid_on' => DateOnly::class,
            'amount_cents' => 'integer',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function isPaid(): bool
    {
        return $this->paid_on !== null;
    }

    public function isOverdue(?CarbonImmutable $today = null): bool
    {
        return ! $this->isPaid() && $this->due_date->lt($today ?? CarbonImmutable::today());
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('paid_on');
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->whereNotNull('paid_on');
    }
}
