<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pedido de cotação COT-AAAA-NNNN: itens, fornecedores (com a hora do envio) e respostas. */
class QuoteRequest extends Model
{
    protected $fillable = [
        'service_order_id',
        'code',
        'max_delivery',
        'payment_terms',
        'answer_until',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'answer_until' => 'datetime',
        ];
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(OrderItem::class, 'quote_request_items', 'quote_request_id', 'os_item_id')->orderBy('os_items.position')->orderBy('os_items.id');
    }

    public function sends(): HasMany
    {
        return $this->hasMany(QuoteSend::class)->orderBy('id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(QuoteResponse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
