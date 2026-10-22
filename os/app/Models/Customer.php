<?php

namespace App\Models;

use App\Support\Document;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cliente da oficina. A Izi Car é um cliente como os outros, marcado `is_izi_fleet`: os carros da frota
 * entram nele sozinhos (App\Services\Registry\IziFleet).
 */
class Customer extends Model
{
    protected $fillable = [
        'name',
        'whatsapp',
        'phone2',
        'document',
        'email',
        'is_izi_fleet',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_izi_fleet' => 'boolean',
        ];
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function serviceOrders(): HasMany
    {
        return $this->hasMany(ServiceOrder::class);
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
