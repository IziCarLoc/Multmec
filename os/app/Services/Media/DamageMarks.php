<?php

namespace App\Services\Media;

use App\Models\Media;
use GdImage;
use Illuminate\Support\Facades\Storage;

/**
 * Desenha as marcas de avaria (bolinhas vermelhas numeradas, como no v12) numa CÓPIA reduzida da foto
 * carimbada, para o PDF. A foto guardada nunca recebe as marcas.
 */
final class DamageMarks
{
    public const RED = [0xD7, 0x19, 0x20];

    public const WHITE = [0xFF, 0xFF, 0xFF];

    /** Foto (com as marcas, se houver) em data URI JPEG, com o lado maior em $maxSide. */
    public function dataUri(Media $media, array $marks = [], int $maxSide = 800, int $startNumber = 1): ?string
    {
        $path = $media->pathFor('foto');

        if (! $path || ! Storage::disk(Media::DISK)->exists($path)) {
            return null;
        }

        $image = ImageTools::resize(ImageTools::fromString((string) Storage::disk(Media::DISK)->get($path)), $maxSide);
        $this->draw($image, $marks, $startNumber);

        return 'data:image/jpeg;base64,'.base64_encode(ImageTools::jpeg($image, 72));
    }

    private function draw(GdImage $image, array $marks, int $startNumber): void
    {
        if ($marks === []) {
            return;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $radius = max(10, (int) round($width * 0.022));
        $red = imagecolorallocate($image, ...self::RED);
        $white = imagecolorallocate($image, ...self::WHITE);
        $font = ImageTools::font(true);
        $number = $startNumber;

        foreach ($marks as $mark) {
            $cx = (int) round((float) $mark['x'] * $width);
            $cy = (int) round((float) $mark['y'] * $height);
            imagefilledellipse($image, $cx, $cy, $radius * 2 + 6, $radius * 2 + 6, $white);
            imagefilledellipse($image, $cx, $cy, $radius * 2, $radius * 2, $red);

            $text = (string) $number++;

            if ($font && function_exists('imagettftext')) {
                $size = (int) round($radius * 0.95);
                $box = imagettfbbox($size, 0, $font, $text);
                imagettftext($image, $size, 0, $cx - (int) round(($box[2] - $box[0]) / 2), $cy + (int) round(($box[1] - $box[7]) / 2), $white, $font, $text);
            } else {
                imagestring($image, 5, $cx - (int) round(imagefontwidth(5) * strlen($text) / 2), $cy - (int) round(imagefontheight(5) / 2), $text, $white);
            }
        }
    }
}
