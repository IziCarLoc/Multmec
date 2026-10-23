<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apontamento de tempo na OS (Iniciar → Pausar). Base da comissão e da regra da conferência (quem apontou
 * tempo não confere). Esquecido aberto é fechado à noite e fica marcado (`auto_closed`) para conferir.
 */
class TimeEntry extends Model
{
    protected $fillable = [
        'service_order_id',
        'user_id',
        'started_at',
        'ended_at',
        'auto_closed',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'auto_closed' => 'boolean',
        ];
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    public function minutes(): int
    {
        return (int) $this->started_at->diffInMinutes($this->ended_at ?? now());
    }
}
