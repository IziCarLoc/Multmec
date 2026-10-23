<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\LossCause;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prejuízo. Quando nasce da baixa de um "A receber" vencido, `receivable_id` aponta para o lançamento (e ele
 * para cá, em `loss_id`): esse prejuízo não se exclui nem muda de valor. Regras em App\Services\Finance\Losses.
 */
class Loss extends Model
{
    protected $fillable = [
        'legacy_id',
        'occurred_on',
        'amount_cents',
        'item',
        'cause',
        'notes',
        'receivable_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'occurred_on' => DateOnly::class,
            'amount_cents' => 'integer',
            'cause' => LossCause::class,
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

    /** Baixa de um "A receber": o valor é o saldo que ficou em aberto. */
    public function isWriteOff(): bool
    {
        return $this->receivable_id !== null;
    }
}
