<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de quem mudou o quê (ajustes, usuários e, depois, as OS). Não se edita: só `created_at`.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'auditable_type',
        'auditable_id',
        'action',
        'reason',
        'changes',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }

    public static function record(Model $subject, string $action, ?string $reason = null, array $changes = []): self
    {
        return self::create([
            'auditable_type' => $subject->getMorphClass(),
            'auditable_id' => $subject->getKey(),
            'action' => $action,
            'reason' => $reason,
            'changes' => $changes === [] ? null : $changes,
            'user_id' => auth()->id(),
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            'user.created' => 'Usuário criado',
            'user.updated' => 'Usuário alterado',
            'user.password_reset' => 'Senha trocada pelo gestor',
            'unit.updated' => 'Dados da oficina alterados',
            'settings.updated' => 'Ajustes alterados',
            default => $this->action,
        };
    }
}
