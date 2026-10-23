<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Link do orçamento para o cliente (sem login). O token fica cifrado (para copiar de novo) e a busca é pelo
 * SHA-256 dele. Abrir o link (inclusive a prévia do WhatsApp) não muda nada: só o botão grava a resposta.
 */
class PublicLink extends Model
{
    protected $fillable = [
        'budget_id',
        'token_hash',
        'token',
        'expires_at',
        'used_at',
        'revoked_at',
        'last_viewed_at',
        'views',
        'created_by',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_viewed_at' => 'datetime',
            'views' => 'integer',
        ];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function url(): string
    {
        return route('budget.public', $this->token);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
