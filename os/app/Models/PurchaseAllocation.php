<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Destino de (parte de) um item da nota: OS (com a peça do orçamento, quando escolhida), estoque, uso interno,
 * devolvido ao fornecedor ou outra empresa do grupo. Regras em App\Services\Purchases\Allocations.
 */
class PurchaseAllocation extends Model
{
    public const DESTINATIONS = [
        'os' => 'OS',
        'estoque' => 'Estoque',
        'uso_interno' => 'Uso interno',
        'devolvido' => 'Devolvido ao fornecedor',
        'outra_empresa' => 'Outra empresa do grupo',
    ];

    protected $fillable = ['purchase_invoice_item_id', 'destination', 'service_order_id', 'os_item_id', 'group_company_id', 'quantity', 'cost_cents', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'cost_cents' => 'integer'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoiceItem::class, 'purchase_invoice_item_id');
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'os_item_id');
    }

    public function groupCompany(): BelongsTo
    {
        return $this->belongsTo(GroupCompany::class);
    }

    public function destinationLabel(): string
    {
        return self::DESTINATIONS[$this->destination] ?? $this->destination;
    }

    /**
     * Destino que ainda vale: estoque, uso interno, devolução e outra empresa sempre; OS só enquanto a OS não foi
     * cancelada (OS cancelada devolve a peça para "sem destino").
     */
    public function scopeValid(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->where('destination', '!=', 'os')
            ->orWhereHas('serviceOrder', fn ($o) => $o->where('stage', '!=', 'cancelada')));
    }
}
