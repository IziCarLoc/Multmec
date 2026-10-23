<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Nota fiscal de compra (NF-e do fornecedor), lida do XML, do texto da DANFE ou digitada. Regras em
 * App\Services\Purchases\Invoices. Dinheiro em centavos.
 */
class PurchaseInvoice extends Model
{
    public const ACTIVE = 'ativa';

    public const CANCELED = 'cancelada';

    public const SOURCE_XML = 'xml';

    public const SOURCE_MANUAL = 'manual';

    /** Devolução/crédito abate o que se deve; ajuste é fiscal: nenhuma das duas gera boleto. */
    public const NO_CHARGE = ['devolucao', 'ajuste'];

    public const PURPOSES = ['normal' => 'Normal', 'complementar' => 'Complementar', 'ajuste' => 'Ajuste', 'devolucao' => 'Devolução/crédito'];

    public const PORTAL_STATUSES = ['autorizada' => 'Autorizada', 'cancelada' => 'Cancelada', 'denegada' => 'Denegada', 'nao_encontrada' => 'Não encontrada'];

    protected $fillable = [
        'supplier_id', 'access_key', 'number', 'series', 'issue_date', 'total_cents', 'total_with_taxes_cents', 'products_cents',
        'freight_cents', 'discount_cents', 'paid_upfront', 'payee_cnpj', 'group_company_id', 'issuer_cnpj', 'issuer_name',
        'recipient_cnpj', 'recipient_name', 'purpose', 'status', 'canceled_source', 'protocol_status', 'nature', 'additional_info',
        'source', 'xml_sha256', 'xml_gz', 'portal_checked_on', 'portal_status', 'portal_amount_cents', 'portal_issue_date',
        'portal_recipient', 'portal_checked_by', 'portal_checked_role', 'notes', 'created_by', 'created_role',
    ];

    protected $hidden = ['xml_gz'];

    protected function casts(): array
    {
        return [
            'issue_date' => DateOnly::class,
            'portal_checked_on' => DateOnly::class,
            'portal_issue_date' => DateOnly::class,
            'total_cents' => 'integer',
            'total_with_taxes_cents' => 'integer',
            'products_cents' => 'integer',
            'freight_cents' => 'integer',
            'discount_cents' => 'integer',
            'portal_amount_cents' => 'integer',
            'paid_upfront' => 'boolean',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function groupCompany(): BelongsTo
    {
        return $this->belongsTo(GroupCompany::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceInstallment::class)->orderBy('due_date')->orderBy('id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceItem::class)->orderBy('item_number');
    }

    public function links(): HasMany
    {
        return $this->hasMany(PurchaseLink::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function generatesCharge(): bool
    {
        return ! in_array($this->purpose, self::NO_CHARGE, true);
    }

    /** O XML original está guardado (o SHA-256 só é gravado junto com ele). */
    public function hasXml(): bool
    {
        return $this->xml_sha256 !== null;
    }

    /** O XML original, como entrou (está guardado comprimido). */
    public function originalXml(): ?string
    {
        $stored = self::query()->whereKey($this->getKey())->value('xml_gz');

        if ($stored === null) {
            return null;
        }

        $decoded = gzdecode((string) base64_decode((string) $stored, true));

        return $decoded === false ? null : $decoded;
    }

    /** "Nota 4321" ou "Nota 4321 série 2". */
    public function label(): string
    {
        return 'Nota '.$this->number.($this->series !== '' && $this->series !== '1' ? ' série '.$this->series : '');
    }

    /** Ainda pode receber boleto: ativa e de compra (não devolução nem ajuste). */
    public function scopeChargeable(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE)->whereNotIn('purpose', self::NO_CHARGE);
    }
}
