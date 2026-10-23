<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\PaymentMethod;
use App\Enums\ReceivableStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lançamento do "A receber" (uma linha por parcela). A situação não é gravada: sai dos pagamentos
 * (`paid_cents`, o resumo deles) e da baixa como prejuízo. Pagamentos, edição e baixa só por
 * App\Services\Finance\Receivables.
 */
class Receivable extends Model
{
    protected $fillable = [
        'legacy_id',
        'service_order_id',
        'customer_id',
        'vehicle_id',
        'order_number',
        'service_date',
        'plate',
        'vehicle_model',
        'parts_cost_cents',
        'labor_cents',
        'freight_cents',
        'total_cents',
        'customer_brought_part',
        'received_by_matrix',
        'payment_method',
        'due_date',
        'installment_group',
        'installment_number',
        'installment_total',
        'notes',
        'paid_cents',
        'received_on',
        'written_off_at',
        'loss_id',
        'written_off_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'service_date' => DateOnly::class,
            'due_date' => DateOnly::class,
            'received_on' => DateOnly::class,
            'written_off_at' => 'datetime',
            'parts_cost_cents' => 'integer',
            'labor_cents' => 'integer',
            'freight_cents' => 'integer',
            'total_cents' => 'integer',
            'paid_cents' => 'integer',
            'installment_number' => 'integer',
            'installment_total' => 'integer',
            'customer_brought_part' => 'boolean',
            'received_by_matrix' => 'boolean',
            'payment_method' => PaymentMethod::class,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function loss(): BelongsTo
    {
        return $this->belongsTo(Loss::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ReceivablePayment::class)->orderBy('paid_on')->orderBy('id');
    }

    public function isWrittenOff(): bool
    {
        return $this->written_off_at !== null;
    }

    public function status(): ReceivableStatus
    {
        return match (true) {
            $this->isWrittenOff() => ReceivableStatus::Prejuizo,
            $this->paid_cents >= $this->total_cents => ReceivableStatus::Recebido,
            $this->paid_cents > 0 => ReceivableStatus::Parcial,
            default => ReceivableStatus::Aberto,
        };
    }

    /** Quanto falta receber (zero depois da baixa: o que faltava virou prejuízo). */
    public function balanceCents(): int
    {
        return $this->isWrittenOff() ? 0 : max(0, $this->total_cents - $this->paid_cents);
    }

    /** Vencido: venceu antes de hoje e não foi recebido inteiro (parcial também conta). */
    public function isOverdue(?CarbonImmutable $today = null): bool
    {
        return $this->status()->isOpen() && $this->due_date->lt($today ?? CarbonImmutable::today());
    }

    public function daysLate(?CarbonImmutable $today = null): int
    {
        return $this->isOverdue($today) ? (int) $this->due_date->diffInDays($today ?? CarbonImmutable::today()) : 0;
    }

    /** "2/3" quando é parcela. */
    public function installmentLabel(): ?string
    {
        return $this->installment_total ? $this->installment_number.'/'.$this->installment_total : null;
    }

    /** "OS 4521", "OS 4521 · 2/3"... para listas e para o item do prejuízo. */
    public function reference(): string
    {
        return 'OS '.($this->order_number ?: '—').($this->installmentLabel() ? ' · parcela '.$this->installmentLabel() : '');
    }

    /** Falta receber (aberto ou parcial), sem baixa. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('written_off_at')->whereColumn('paid_cents', '<', 'total_cents');
    }

    public function scopeReceived(Builder $query): Builder
    {
        return $query->whereNull('written_off_at')->whereColumn('paid_cents', '>=', 'total_cents');
    }

    public function scopeWrittenOff(Builder $query): Builder
    {
        return $query->whereNotNull('written_off_at');
    }

    /** Em aberto e vencido antes de `$today` (parcial também). */
    public function scopeOverdue(Builder $query, ?CarbonImmutable $today = null): Builder
    {
        return $query->open()->where('due_date', '<', ($today ?? CarbonImmutable::today())->toDateString());
    }

    /** O que conta no saldo, no lucro e no "a receber": tudo menos o recebimento pela matriz. */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->where('received_by_matrix', false);
    }
}
