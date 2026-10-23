<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ligação boleto × nota (quanto deste boleto paga esta nota; a parcela quando foi escolhida). */
class PurchaseLink extends Model
{
    protected $fillable = ['bank_slip_id', 'purchase_invoice_id', 'installment_id', 'amount_cents', 'source', 'created_by', 'created_role'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer'];
    }

    public function slip(): BelongsTo
    {
        return $this->belongsTo(BankSlip::class, 'bank_slip_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoiceInstallment::class, 'installment_id');
    }
}
