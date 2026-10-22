<?php

namespace App\Http\Controllers;

use App\Models\Media;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Entrega a foto ou o vídeo do disco privado, só para quem entrou no sistema (rota com `auth`).
 * Variantes: foto (carimbada), mini (miniatura), original (como chegou do celular, para baixar).
 */
class MediaController extends Controller
{
    public function show(Media $media, string $variant = 'foto'): BinaryFileResponse
    {
        abort_unless(in_array($variant, ['foto', 'mini', 'original'], true), 404);

        $path = $media->pathFor($variant);
        $disk = Storage::disk(Media::DISK);
        abort_unless($path && $disk->exists($path), 404);

        $response = response()->file($disk->path($path), [
            'Content-Type' => $variant === 'original' ? $media->mime : ($media->isVideo() ? $media->mime : 'image/jpeg'),
            // O arquivo não muda (a URL leva o começo do hash): o celular pode guardar por um dia.
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        if ($variant === 'original') {
            $response->setContentDisposition('attachment', basename($path));
        }

        return $response;
    }
}
