<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conta fixa: o modelo que gera a conta do mês no "A pagar" (salário, aluguel, energia...). Regras em
 * App\Services\Finance\RecurringBills. Mudar o modelo vale para as próximas contas; as já geradas não mudam.
 */
class RecurringBill extends Model
{
    protected $fillable = [
        'supplier_id',
        'description',
        'category',
        'due_day',
        'amount_cents',
        'active',
        'first_month',
        'generated_through',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class,
            'due_day' => 'integer',
            'amount_cents' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function payables(): HasMany
    {
        return $this->hasMany(Payable::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
