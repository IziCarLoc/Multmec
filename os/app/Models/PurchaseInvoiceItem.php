<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Item da nota de compra. `cost_cents` é a parte do item no total da nota (frete, impostos e desconto rateados): o
 * custo real da peça. O destino de cada parte do item fica em PurchaseAllocation.
 */
class PurchaseInvoiceItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'purchase_invoice_id', 'item_number', 'code', 'description', 'ncm', 'cfop', 'unit', 'quantity', 'unit_price_cents',
        'total_cents', 'discount_cents', 'cost_cents', 'customer_order', 'additional_info',
    ];

    protected function casts(): array
    {
        return [
            'item_number' => 'integer',
            'quantity' => 'float',
            'unit_price_cents' => 'integer',
            'total_cents' => 'integer',
            'discount_cents' => 'integer',
            'cost_cents' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PurchaseAllocation::class)->orderBy('id');
    }

    /** "2 un" / "1,5 JG": quantidade sem casas desnecessárias. */
    public function quantityLabel(?float $quantity = null): string
    {
        $value = rtrim(rtrim(number_format($quantity ?? $this->quantity, 4, ',', '.'), '0'), ',');

        return $value.' '.mb_strtolower((string) ($this->unit ?: 'un'));
    }
}
