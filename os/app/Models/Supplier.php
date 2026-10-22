<?php

namespace App\Models;

use App\Enums\SupplierKind;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
    ];

    protected function casts(): array
    {
        return [
            'kind' => SupplierKind::class,
            'active' => 'boolean',
        ];
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
