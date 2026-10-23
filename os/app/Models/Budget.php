<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Orçamento congelado (nº 1) ou adendo (2, 3...). O `snapshot` é exatamente o que o cliente recebeu (itens,
 * preços, condições, textos), com o SHA-256; o PDF sai dele. Só muda por App\Services\Budgets\Budgets.
 */
class Budget extends Model
{
    public const KIND_BUDGET = 'orcamento';

    public const KIND_ADDENDUM = 'adendo';

    public const STATUS_SENT = 'enviado';

    public const STATUS_APPROVED = 'aprovado';

    public const STATUS_REFUSED = 'recusado';

    public const STATUS_CANCELLED = 'cancelado';

    /** Como a resposta chegou. */
    public const METHODS = [
        'link' => 'Pelo link (cliente respondeu)',
        'mensagem' => 'Mensagem do cliente (WhatsApp)',
        'presencial' => 'Presencial, com documento conferido',
        'assinatura' => 'Assinatura na tela',
        'alcada' => 'Alçada da Izi Car',
    ];

    protected $fillable = [
        'service_order_id',
        'number',
        'kind',
        'status',
        'snapshot',
        'snapshot_sha256',
        'parts_cents',
        'labor_cents',
        'total_cents',
        'approved_total_cents',
        'payment_terms',
        'expected_date',
        'valid_until',
        'frozen_at',
        'frozen_by',
        'sent_at',
        'sent_by',
        'sent_via',
        'pdf_path',
        'pdf_sha256',
        'decided_at',
        'decided_by',
        'decision_method',
        'consent_id',
        'non_original_authorized',
        'refusal_reason',
        'diagnosis_fee_cents',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'snapshot' => 'array',
            'parts_cents' => 'integer',
            'labor_cents' => 'integer',
            'total_cents' => 'integer',
            'approved_total_cents' => 'integer',
            'expected_date' => DateOnly::class,
            'valid_until' => DateOnly::class,
            'frozen_at' => 'datetime',
            'sent_at' => 'datetime',
            'decided_at' => 'datetime',
            'non_original_authorized' => 'boolean',
            'diagnosis_fee_cents' => 'integer',
        ];
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('position')->orderBy('id');
    }

    public function consent(): BelongsTo
    {
        return $this->belongsTo(Consent::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function links(): HasMany
    {
        return $this->hasMany(PublicLink::class);
    }

    public function isAddendum(): bool
    {
        return $this->kind === self::KIND_ADDENDUM;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    /** "Orçamento" ou "Adendo 2". */
    public function title(): string
    {
        return $this->isAddendum() ? 'Adendo '.$this->number : 'Orçamento';
    }

    public function isExpired(): bool
    {
        return $this->isPending() && $this->valid_until !== null && $this->valid_until->lt(today());
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_SENT => $this->sent_at ? 'Aguardando resposta' : 'Pronto para enviar',
            self::STATUS_APPROVED => $this->approved_total_cents !== null && $this->approved_total_cents < $this->total_cents ? 'Aprovado em parte' : 'Aprovado',
            self::STATUS_REFUSED => 'Recusado',
            default => 'Cancelado',
        };
    }
}
