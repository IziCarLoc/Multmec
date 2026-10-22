<?php

namespace App\Models;

use App\Enums\PartOrigin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Peça ou serviço da OS. Só se cria e muda por App\Services\Items\OrderItems (perfil, etapa e auditoria).
 * Custo e preço nunca aparecem para o mecânico.
 */
class OrderItem extends Model
{
    protected $table = 'os_items';

    public const KIND_PART = 'peca';

    public const KIND_SERVICE = 'servico';

    public const PRIORITIES = ['necessario' => 'Necessário', 'recomendado' => 'Recomendado'];

    protected $fillable = [
        'service_order_id',
        'kind',
        'description',
        'quantity',
        'priority',
        'hours',
        'part_code',
        'preferred_brand',
        'accepted_brands',
        'quote_detail',
        'needs_quote',
        'origin',
        'supplier_id',
        'cost_cents',
        'price_cents',
        'brand',
        'lead_time',
        'requires_photos',
        'mechanic_id',
        'created_by',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'hours' => 'decimal:2',
            'needs_quote' => 'boolean',
            'origin' => PartOrigin::class,
            'cost_cents' => 'integer',
            'price_cents' => 'integer',
            'requires_photos' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function mechanic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mechanic_id');
    }

    public function quoteResponses(): HasMany
    {
        return $this->hasMany(QuoteResponse::class, 'os_item_id');
    }

    public function isPart(): bool
    {
        return $this->kind === self::KIND_PART;
    }

    public function isService(): bool
    {
        return $this->kind === self::KIND_SERVICE;
    }

    /** Peça que vai para a cotação (não é do cliente nem do estoque). */
    public function toQuote(): bool
    {
        return $this->isPart() && $this->needs_quote && ! ($this->origin?->skipsQuote() ?? false);
    }

    /** "2 un" / "1,5 h": quantidade sem casas desnecessárias. */
    public function quantityLabel(): string
    {
        $value = rtrim(rtrim(number_format((float) $this->quantity, 2, ',', ''), '0'), ',');

        return $value.($this->isPart() ? ' un' : 'x');
    }

    public function hoursLabel(): ?string
    {
        return $this->hours === null ? null : rtrim(rtrim(number_format((float) $this->hours, 2, ',', ''), '0'), ',').' h';
    }
}
