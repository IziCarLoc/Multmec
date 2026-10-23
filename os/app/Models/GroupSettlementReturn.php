<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Devolução (parcial ou total) de um acerto entre empresas, no dia em que o dinheiro voltou. */
class GroupSettlementReturn extends Model
{
    protected $fillable = ['group_settlement_id', 'returned_on', 'amount_cents', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['returned_on' => DateOnly::class, 'amount_cents' => 'integer'];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(GroupSettlement::class, 'group_settlement_id');
    }
}
