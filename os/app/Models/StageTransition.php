<?php

namespace App\Models;

use App\Enums\Stage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma mudança de etapa: de onde, para onde, quem, quando e por quê. Não se edita. */
class StageTransition extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'service_order_id',
        'from_stage',
        'to_stage',
        'user_id',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'from_stage' => Stage::class,
            'to_stage' => Stage::class,
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

    /** Voltou no caminho (só o gestor faz, sempre com motivo). */
    public function isBackward(): bool
    {
        return $this->from_stage !== null
            && in_array($this->from_stage, Stage::path(), true) && in_array($this->to_stage, Stage::path(), true)
            && $this->to_stage->position() < $this->from_stage->position();
    }
}
