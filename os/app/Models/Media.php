<?php

namespace App\Models;

use App\Enums\MediaKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Foto ou vídeo da OS, no disco privado. Criar só por App\Services\Media\MediaStore (que confere o hash e
 * faz o carimbo); mostrar só pela rota `media.show`, que exige login.
 */
class Media extends Model
{
    public const DISK = 'local';

    protected $fillable = [
        'service_order_id',
        'os_item_id',
        'kind',
        'position',
        'caption',
        'original_path',
        'original_sha256',
        'stamped_path',
        'stamped_sha256',
        'thumb_path',
        'mime',
        'size',
        'width',
        'height',
        'taken_at',
        'received_at',
        'is_stale',
        'user_id',
        'replaced_at',
        'replaced_by',
    ];

    protected function casts(): array
    {
        return [
            'kind' => MediaKind::class,
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'taken_at' => 'datetime',
            'received_at' => 'datetime',
            'is_stale' => 'boolean',
            'replaced_at' => 'datetime',
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

    /** Fotos em uso (as refeitas ficam guardadas, mas fora das listas). */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('replaced_at');
    }

    public function url(string $variant = 'foto'): string
    {
        return route('media.show', ['media' => $this->id, 'variant' => $variant]).'?v='.substr((string) ($this->stamped_sha256 ?? $this->original_sha256), 0, 8);
    }

    /** Caminho no disco da versão pedida: foto (carimbada), mini (miniatura) ou original (como chegou). */
    public function pathFor(string $variant): ?string
    {
        return match ($variant) {
            'mini' => $this->thumb_path ?? $this->stamped_path ?? $this->original_path,
            'original' => $this->original_path,
            default => $this->stamped_path ?? $this->original_path,
        };
    }

    public function isVideo(): bool
    {
        return $this->kind === MediaKind::Video;
    }
}
