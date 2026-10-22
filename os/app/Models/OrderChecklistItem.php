<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item de checklist dentro da OS (cópia da configuração do dia), com a resposta, quem respondeu e quando.
 *
 * Respostas: itens comuns `ok` (em funcionamento), `troca` (necessário troca) ou `na` (não se aplica);
 * fluidos `ideal`, `media`, `baixo` ou `na`. Troca e nível baixo são não conformidade: pedem foto.
 */
class OrderChecklistItem extends Model
{
    protected $table = 'os_checklist_items';

    public const STATES = ['ok' => 'Em funcionamento', 'troca' => 'Necessário troca', 'na' => 'Não se aplica'];

    public const FLUID_STATES = ['ideal' => 'Ideal', 'media' => 'Na média', 'baixo' => 'Baixo', 'na' => 'Não se aplica'];

    protected $fillable = [
        'service_order_id',
        'checklist_item_id',
        'kind',
        'round',
        'section',
        'label',
        'is_fluid',
        'requires_detail',
        'position',
        'state',
        'detail',
        'media_id',
        'answered_by',
        'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'is_fluid' => 'boolean',
            'requires_detail' => 'boolean',
            'position' => 'integer',
            'answered_at' => 'datetime',
        ];
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function answerer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    /** @return array<string, string> */
    public function options(): array
    {
        return $this->is_fluid ? self::FLUID_STATES : self::STATES;
    }

    public function stateLabel(): ?string
    {
        return $this->state === null ? null : ($this->options()[$this->state] ?? $this->state);
    }

    /** Troca ou nível baixo: precisa de foto. */
    public function isNonConformity(): bool
    {
        return in_array($this->state, ['troca', 'baixo'], true);
    }
}
