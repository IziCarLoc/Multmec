<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Avaria marcada na vistoria de entrada: a foto, o local do carro e as marcas tocadas sobre a foto
 * (`marks`: lista de {x, y, type, desc}, x e y de 0 a 1 em relação à foto carimbada).
 */
class Damage extends Model
{
    /** Os 26 locais do v12. */
    public const LOCATIONS = [
        'Para-choque dianteiro', 'Para-choque traseiro', 'Capô', 'Teto', 'Tampa traseira / porta-malas', 'Para-brisa',
        'Vidro traseiro', 'Farol esquerdo', 'Farol direito', 'Lanterna esquerda', 'Lanterna direita',
        'Para-lama dianteiro esquerdo', 'Para-lama dianteiro direito', 'Porta dianteira esquerda', 'Porta dianteira direita',
        'Porta traseira esquerda', 'Porta traseira direita', 'Lateral traseira esquerda', 'Lateral traseira direita',
        'Retrovisor esquerdo', 'Retrovisor direito', 'Soleira esquerda', 'Soleira direita', 'Rodas e pneus',
        'Interior / bancos', 'Painel',
    ];

    /** Os 8 tipos do v12. */
    public const TYPES = ['Risco', 'Amassado', 'Trinca', 'Quebrado', 'Desgaste', 'Mancha', 'Faltando', 'Outro'];

    protected $fillable = [
        'service_order_id',
        'media_id',
        'location',
        'marks',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'marks' => 'array',
        ];
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    /** @return list<array{x: float, y: float, type: string, desc: ?string}> */
    public function markList(): array
    {
        return array_values($this->marks ?? []);
    }
}
