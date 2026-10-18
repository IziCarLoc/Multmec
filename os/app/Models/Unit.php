<?php

namespace App\Models;

use App\Support\Document;
use Illuminate\Database\Eloquent\Model;

/**
 * Unidade da oficina (Liberdade, Matriz). Os dados vão no cabeçalho dos PDFs; a numeração das OS
 * é por unidade: "manual" enquanto a OS ainda é aberta também no sistema atual (R65) e o número é
 * digitado; "auto" quando o sistema passa a numerar (a partir de `next_number`).
 */
class Unit extends Model
{
    public const NUMBERING_MANUAL = 'manual';

    public const NUMBERING_AUTO = 'auto';

    protected $fillable = [
        'code',
        'name',
        'trade_name',
        'legal_name',
        'cnpj',
        'address',
        'city',
        'phone',
        'numbering_mode',
        'next_number',
        'is_default',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'next_number' => 'integer',
            'is_default' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public static function default(): ?self
    {
        return self::query()->where('is_default', true)->first() ?? self::query()->orderBy('id')->first();
    }

    public function numbersAutomatically(): bool
    {
        return $this->numbering_mode === self::NUMBERING_AUTO;
    }

    public function cnpjFormatted(): string
    {
        return $this->cnpj ? Document::format($this->cnpj) : '';
    }
}
