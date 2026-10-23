<?php

namespace App\Models;

use App\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Conta a pagar (boleto de fornecedor). Regras em App\Services\Finance\Payables.
 */
class Payable extends Model
{
    public const STATUS_OPEN = 'aberto';

    public const STATUS_PAID = 'pago';

    protected $fillable = [
        'legacy_id',
        'supplier_id',
        'document_number',
        'description',
        'issue_date',
        'due_date',
        'amount_cents',
        'notes',
        'installment_group',
        'installment_number',
        'installment_total',
        'status',
        'paid_on',
        'paid_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => DateOnly::class,
            'due_date' => DateOnly::class,
            'paid_on' => DateOnly::class,
            'amount_cents' => 'integer',
            'installment_number' => 'integer',
            'installment_total' => 'integer',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** O boleto da conferência de compras que é esta conta (quando o boleto passou por Compras). */
    public function bankSlip(): HasOne
    {
        return $this->hasOne(BankSlip::class);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /** Vencido: venceu antes de hoje e não foi pago. */
    public function isOverdue(?CarbonImmutable $today = null): bool
    {
        return ! $this->isPaid() && $this->due_date->lt($today ?? CarbonImmutable::today());
    }

    /** "2/3" quando é parcela. */
    public function installmentLabel(): ?string
    {
        return $this->installment_total ? $this->installment_number.'/'.$this->installment_total : null;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PAID);
    }
}
