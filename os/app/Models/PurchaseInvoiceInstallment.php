<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Parcela (duplicata) da nota de compra. */
class PurchaseInvoiceInstallment extends Model
{
    public $timestamps = false;

    protected $fillable = ['purchase_invoice_id', 'number', 'due_date', 'amount_cents'];

    protected function casts(): array
    {
        return ['due_date' => DateOnly::class, 'amount_cents' => 'integer'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }
}
