<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Acerto entre a oficina e uma empresa do grupo. a_receber: a oficina pagou ou forneceu algo que é da outra empresa;
 * a_pagar: a outra empresa pagou algo que é da oficina. Origem: boleto pago pela oficina, peça entregue ou lançado
 * à mão. Regras em App\Services\Purchases\GroupCompanies.
 */
class GroupSettlement extends Model
{
    public const RECEIVE = 'a_receber';

    public const PAY = 'a_pagar';

    protected $fillable = ['group_company_id', 'direction', 'source', 'bank_slip_id', 'purchase_allocation_id', 'description', 'amount_cents', 'occurred_on', 'cash', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['occurred_on' => DateOnly::class, 'amount_cents' => 'integer', 'cash' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(GroupCompany::class, 'group_company_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(GroupSettlementReturn::class)->orderBy('returned_on')->orderBy('id');
    }

    /** O que ainda falta acertar (o valor menos as devoluções já registradas). */
    public function balanceCents(): int
    {
        $returned = $this->relationLoaded('returns') ? $this->returns->sum('amount_cents') : $this->returns()->sum('amount_cents');

        return max(0, $this->amount_cents - (int) $returned);
    }
}
