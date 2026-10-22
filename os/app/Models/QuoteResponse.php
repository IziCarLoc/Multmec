<?php

namespace App\Models;

use App\Enums\PartOrigin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Resposta de um fornecedor para um item da cotação; uma por item é a vencedora. */
class QuoteResponse extends Model
{
    public const STATUSES = ['disponivel' => 'Tem', 'encomenda' => 'Sob encomenda', 'nao_tem' => 'Não tem'];

    protected $fillable = [
        'quote_request_id',
        'supplier_id',
        'os_item_id',
        'status',
        'brand',
        'origin',
        'cost_cents',
        'lead_time',
        'warranty',
        'is_winner',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'origin' => PartOrigin::class,
            'cost_cents' => 'integer',
            'is_winner' => 'boolean',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'os_item_id');
    }
}
