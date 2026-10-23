<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Está certo, conferi": o aceite de uma ocorrência da conferência de compras, com o motivo. Vale só enquanto o
 * fato for o mesmo (`state`). O histórico de cada aceite (e de quem desfez) fica em AuditLog, só de inclusão.
 */
class AuditAcceptance extends Model
{
    protected $fillable = ['occurrence_key', 'reason', 'state', 'accepted_by'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }
}
