<?php

namespace App\Models;

use App\Enums\SupplierKind;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fornecedor de peças e prestador externo de serviço (eletricista, ar-condicionado...). Mesmo cadastro
 * da cotação e, depois, do Financeiro (contas a pagar).
 */
class Supplier extends Model
{
    protected $fillable = [
        'name',
        'kind',
        'whatsapp',
        'email',
        'document',
        'lines',
        'lead_time',
        'payment_terms',
        'pix_key',
        'notes',
        'active',
        'confirmed_at',
        'confirmed_by',
        'authorized_payees',
    ];

    protected function casts(): array
    {
        return [
            'kind' => SupplierKind::class,
            'active' => 'boolean',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * Mudar o CNPJ desfaz a conferência: quem conferiu não viu este CNPJ (conferência de compras, doc 09).
     */
    protected static function booted(): void
    {
        static::saving(function (Supplier $supplier) {
            if ($supplier->exists && $supplier->isDirty('document') && ! $supplier->isDirty('confirmed_at')) {
                $supplier->confirmed_at = null;
                $supplier->confirmed_by = null;
            }
        });
    }

    /** Notas de compra deste fornecedor (conferência de compras). */
    public function purchaseInvoices(): HasMany
    {
        return $this->hasMany(PurchaseInvoice::class);
    }

    /** Boletos deste fornecedor cadastrados na conferência de compras. */
    public function bankSlips(): HasMany
    {
        return $this->hasMany(BankSlip::class);
    }

    /** Quem conferiu (gestor). */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** O gestor conferiu o CNPJ e o telefone (Compras > Fornecedores). */
    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /** @return list<string> outros CNPJs que podem receber os boletos deste fornecedor */
    public function authorizedPayees(): array
    {
        return array_values(array_filter(explode(',', (string) $this->authorized_payees)));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /** Quem vende peça (para a cotação). */
    public function scopeSellsParts(Builder $query): Builder
    {
        return $query->whereIn('kind', [SupplierKind::Pecas->value, SupplierKind::Ambos->value]);
    }

    /** Prestadores externos de serviço. */
    public function scopeProvidesService(Builder $query): Builder
    {
        return $query->whereIn('kind', [SupplierKind::Servico->value, SupplierKind::Ambos->value]);
    }

    public function whatsappFormatted(): string
    {
        return Phone::format($this->whatsapp);
    }
}
