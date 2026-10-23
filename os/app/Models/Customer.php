<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\CreditKind;
use App\Support\Document;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cliente da oficina. A Izi Car é um cliente como os outros, marcado `is_izi_fleet`: os carros da frota
 * entram nele sozinhos (App\Services\Registry\IziFleet).
 * Crédito a prazo (tipo, prazo, limite e a liberação do gestor) só muda por App\Services\Finance\Credit.
 */
class Customer extends Model
{
    protected $fillable = [
        'name',
        'whatsapp',
        'phone2',
        'document',
        'email',
        'address',
        'is_izi_fleet',
        'notes',
        'credit_kind',
        'credit_days',
        'credit_limit_cents',
        'credit_release_until',
        'credit_release_reason',
        'credit_released_by',
    ];

    protected $attributes = [
        'credit_kind' => 'avulso',
        'credit_days' => 0,
        'credit_limit_cents' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_izi_fleet' => 'boolean',
            'credit_kind' => CreditKind::class,
            'credit_days' => 'integer',
            'credit_limit_cents' => 'integer',
            'credit_release_until' => DateOnly::class,
        ];
    }

    /** Tem crédito a prazo: limite maior que zero (limite zero = sem crédito, como no financeiro em Node). */
    public function hasCredit(): bool
    {
        return $this->credit_limit_cents > 0;
    }

    /** Liberação do gestor valendo hoje (vender a prazo mesmo travado ou sem crédito). */
    public function releaseActive(?CarbonImmutable $today = null): bool
    {
        return $this->credit_release_until !== null && $this->credit_release_until->gte(($today ?? CarbonImmutable::today())->startOfDay());
    }

    /** Entra na carteira de crédito: tem prazo, limite ou é frota, locadora ou revenda. */
    public function scopeOnCredit(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('credit_days', '>', 0)->orWhere('credit_limit_cents', '>', 0)->orWhere('credit_kind', '!=', CreditKind::Avulso->value));
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function serviceOrders(): HasMany
    {
        return $this->hasMany(ServiceOrder::class);
    }

    public function receivables(): HasMany
    {
        return $this->hasMany(Receivable::class);
    }

    public function whatsappFormatted(): string
    {
        return Phone::format($this->whatsapp);
    }

    public function documentFormatted(): string
    {
        return $this->document ? Document::format($this->document) : '';
    }

    /** Busca por nome, telefone (só dígitos) ou CPF/CNPJ. */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);
        $digits = (string) preg_replace('/\D/', '', $term);

        return $query->where(function (Builder $q) use ($term, $digits) {
            $q->where('name', 'like', '%'.$term.'%');

            if (strlen($digits) >= 4) {
                $q->orWhere('whatsapp', 'like', '%'.$digits.'%')
                    ->orWhere('phone2', 'like', '%'.$digits.'%')
                    ->orWhere('document', 'like', '%'.$digits.'%');
            }
        });
    }
}
