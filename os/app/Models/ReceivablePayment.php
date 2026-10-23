<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pagamento (parcial ou integral) de um lançamento do "A receber". Entra e sai só por
 * App\Services\Finance\Receivables (que refaz o resumo no lançamento).
 */
class ReceivablePayment extends Model
{
    protected $fillable = [
        'legacy_id',
        'receivable_id',
        'paid_on',
        'amount_cents',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'paid_on' => DateOnly::class,
            'amount_cents' => 'integer',
        ];
    }

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
