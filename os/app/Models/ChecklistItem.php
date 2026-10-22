<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Item de checklist configurado em Ajustes: o técnico da entrada (parte B) e, depois, os da conferência
 * (modelos Expresso e Completo). A OS guarda uma cópia (OrderChecklistItem) quando entra na etapa.
 */
class ChecklistItem extends Model
{
    public const KIND_TECHNICAL = 'tecnico';

    public const KIND_FINAL_CHECK = 'conferencia';

    protected $fillable = [
        'kind',
        'template',
        'section',
        'label',
        'is_fluid',
        'requires_detail',
        'position',
        'active',
        'needs_review',
    ];

    protected function casts(): array
    {
        return [
            'is_fluid' => 'boolean',
            'requires_detail' => 'boolean',
            'position' => 'integer',
            'active' => 'boolean',
            'needs_review' => 'boolean',
        ];
    }

    public function scopeTechnical(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_TECHNICAL)->where('active', true)->orderBy('position')->orderBy('id');
    }
}
