<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Support\Purchases\BoletoCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Boleto de fornecedor cadastrado na conferência de compras. O da oficina é a conta do "A pagar" (`payable`);
 * o de outra empresa do grupo fica fora do "A pagar". Regras em App\Services\Purchases\BankSlips.
 */
class BankSlip extends Model
{
    public const OPEN = 'aberto';

    public const PAID = 'pago';

    public const CONTESTED = 'contestado';

    public const CANCELED = 'cancelado';

    public const STATUSES = [self::OPEN => 'Em aberto', self::PAID => 'Pago', self::CONTESTED => 'Contestado', self::CANCELED => 'Cancelado'];

    protected $fillable = [
        'supplier_id', 'payable_id', 'barcode', 'digitable_line', 'bank_code', 'amount_cents', 'due_date', 'document_number',
        'payee_name', 'payee_cnpj', 'payer_cnpj', 'status', 'bank_checked_on', 'group_company_id', 'paid_on', 'paid_cents',
        'paid_by_party', 'paid_by', 'released_reason', 'released_on', 'adopted_payable', 'created_by', 'created_role',
        'canceled_role', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => DateOnly::class,
            'bank_checked_on' => DateOnly::class,
            'paid_on' => DateOnly::class,
            'released_on' => DateOnly::class,
            'amount_cents' => 'integer',
            'paid_cents' => 'integer',
            'adopted_payable' => 'array',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function payable(): BelongsTo
    {
        return $this->belongsTo(Payable::class);
    }

    public function groupCompany(): BelongsTo
    {
        return $this->belongsTo(GroupCompany::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(PurchaseLink::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function bankName(): ?string
    {
        return BoletoCode::bankName($this->bank_code);
    }

    /** Boleto de arrecadação/convênio (começa com 8): não passa pelo DDA. */
    public function isConvenio(): bool
    {
        return str_starts_with((string) $this->barcode, '8') || str_starts_with((string) $this->digitable_line, '8');
    }

    /** Ainda vale (em aberto ou pago): contestado e cancelado ficam fora da conferência. */
    public function scopeAlive(Builder $query): Builder
    {
        return $query->whereNotIn('status', [self::CANCELED, self::CONTESTED]);
    }
}
