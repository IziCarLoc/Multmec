<?php

namespace App\Models;

use App\Support\Document;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Empresa do mesmo grupo (locadora, oficina do sócio...). Nota ou boleto no CNPJ dela não é golpe, mas também não é
 * despesa da oficina. Regras em App\Services\Purchases\GroupCompanies.
 */
class GroupCompany extends Model
{
    public const ROLES = ['locadora' => 'Locadora', 'socio' => 'Oficina do sócio', 'outra' => 'Outra empresa'];

    protected $fillable = ['name', 'cnpj', 'role', 'active', 'created_by'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }

    public function cnpjFormatted(): string
    {
        return Document::format($this->cnpj);
    }
}
