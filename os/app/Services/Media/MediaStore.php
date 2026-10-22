<?php

namespace App\Services\Media;

use App\Enums\MediaKind;
use App\Models\AuditLog;
use App\Models\Media;
use App\Models\ServiceOrder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Recebe a foto do celular:
 *  1. confere o SHA-256 que o celular calculou com o do arquivo que chegou (sem hash ou diferente: recusa);
 *  2. guarda o arquivo recebido intacto no disco privado;
 *  3. faz a cópia carimbada (desenho do v12, hora do SERVIDOR) e a miniatura;
 *  4. marca "foto antiga" quando a hora da foto no aparelho é bem anterior ao envio.
 * Foto refeita não apaga a anterior: ela fica guardada como substituída.
 */
final class MediaStore
{
    /** Foto tirada mais que isso antes do envio é marcada como antiga. */
    public const STALE_MINUTES = 30;

    public const STAMPED_MAX_SIDE = 1600;

    public const THUMB_MAX_SIDE = 400;

    public function __construct(private readonly PhotoStamper $stamper) {}

    /**
     * @param  array{position?: ?string, caption?: ?string, replaces?: ?Media}  $attributes
     */
    public function storePhoto(ServiceOrder $order, MediaKind $kind, UploadedFile $file, ?string $clientHash, ?int $takenAtMs, User $by, array $attributes = []): Media
    {
        $path = $file->getRealPath();
        $this->assertHash($path, $clientHash);

        $binary = (string) file_get_contents($path);

        try {
            $image = ImageTools::fromString($binary, $path);
        } catch (InvalidArgumentException $e) {
            throw new DomainException($e->getMessage());
        }

        $order->loadMissing(['vehicle', 'unit']);
        $receivedAt = now();
        $takenAt = $this->takenAt($takenAtMs, $receivedAt);
        $caption = trim((string) ($attributes['caption'] ?? '')) ?: null;
        $base = $order->storageDir().'/'.$kind->value.'-'.$receivedAt->format('Ymd-His').'-'.Str::lower(Str::random(6));
        $extension = match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        $image = ImageTools::resize($image, self::STAMPED_MAX_SIDE);
        $width = imagesx($image);
        $height = imagesy($image);
        $stamped = $this->stamper->stamp(
            $image,
            $order->label().'  '.($order->vehicle?->plate ?: 'SEM PLACA').'  '.$kind->stampLabel(),
            $receivedAt->format('d/m/Y H:i:s').($caption ? '  ·  '.$caption : ''),
            $kind->stampLabel(),
        );
        $stampedJpeg = ImageTools::jpeg($stamped, 82);
        $thumbJpeg = ImageTools::jpeg(ImageTools::resize(ImageTools::fromString($stampedJpeg), self::THUMB_MAX_SIDE), 70);

        $disk = Storage::disk(Media::DISK);

        return DB::transaction(function () use ($disk, $base, $extension, $binary, $stampedJpeg, $thumbJpeg, $order, $kind, $attributes, $caption, $file, $width, $height, $takenAt, $receivedAt, $by) {
            $disk->put($originalPath = $base.'.'.$extension, $binary);
            $disk->put($stampedPath = $base.'-carimbo.jpg', $stampedJpeg);
            $disk->put($thumbPath = $base.'-mini.jpg', $thumbJpeg);

            $media = Media::create([
                'service_order_id' => $order->id,
                'kind' => $kind,
                'position' => $attributes['position'] ?? null,
                'caption' => $caption,
                'original_path' => $originalPath,
                'original_sha256' => hash('sha256', $binary),
                'stamped_path' => $stampedPath,
                'stamped_sha256' => hash('sha256', $stampedJpeg),
                'thumb_path' => $thumbPath,
                'mime' => $file->getMimeType() ?: 'image/jpeg',
                'size' => strlen($binary),
                'width' => $width,
                'height' => $height,
                'taken_at' => $takenAt,
                'received_at' => $receivedAt,
                'is_stale' => $takenAt !== null && $takenAt->lt($receivedAt->copy()->subMinutes(self::STALE_MINUTES)),
                'user_id' => $by->id,
            ]);

            if (($previous = $attributes['replaces'] ?? null) instanceof Media && $previous->replaced_at === null) {
                $previous->update(['replaced_at' => $receivedAt, 'replaced_by' => $media->id]);
                AuditLog::record($previous, 'media.replaced', null, ['nova' => $media->id]);
            }

            return $media;
        });
    }

    /** Sem hash ou com hash diferente do arquivo que chegou: a foto não entra. */
    public function assertHash(string $path, ?string $clientHash): void
    {
        $clientHash = strtolower(trim((string) $clientHash));

        if (preg_match('/^[a-f0-9]{64}$/', $clientHash) !== 1) {
            throw new DomainException('A foto chegou sem a conferência do celular (hash). Abra o sistema pelo endereço https e tire a foto de novo.');
        }

        if (! hash_equals(hash_file('sha256', $path), $clientHash)) {
            throw new DomainException('A foto chegou diferente do que saiu do celular. Tire de novo.');
        }
    }

    /** Hora da foto no aparelho, só se fizer sentido (relógio do celular pode estar errado). */
    private function takenAt(?int $milliseconds, CarbonInterface $receivedAt): ?CarbonImmutable
    {
        if (! $milliseconds) {
            return null;
        }

        $taken = CarbonImmutable::createFromTimestampMs($milliseconds, config('app.timezone'));

        return $taken->between($receivedAt->copy()->subYears(1), $receivedAt->copy()->addMinutes(2)) ? $taken : null;
    }
}
