<?php

namespace App\Services\Media;

use GdImage;
use InvalidArgumentException;

/**
 * Operações de imagem com GD (vem do processador de fotos da vistoria do Gestão): abrir com limite de
 * pixels antes de decodificar, girar pela orientação EXIF, reduzir, gerar JPEG.
 */
final class ImageTools
{
    /** Acima disso o GD esgota a memória da hospedagem compartilhada. */
    public const MAX_PIXELS = 40_000_000;

    public static function load(string $path): GdImage
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new InvalidArgumentException('Não foi possível ler a imagem.');
        }

        return self::fromString($contents, $path);
    }

    public static function fromString(string $binary, ?string $pathForExif = null): GdImage
    {
        $info = @getimagesizefromstring($binary);

        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new InvalidArgumentException('Arquivo não é uma foto válida (use JPEG, PNG ou WebP).');
        }

        if ((int) $info[0] * (int) $info[1] > self::MAX_PIXELS) {
            throw new InvalidArgumentException('Foto grande demais: use uma foto de até '.round(self::MAX_PIXELS / 1_000_000).' megapixels.');
        }

        $image = @imagecreatefromstring($binary);

        if (! $image instanceof GdImage) {
            throw new InvalidArgumentException('Arquivo não é uma foto válida.');
        }

        if ($pathForExif !== null) {
            $image = self::applyExifOrientation($image, $pathForExif);
        }

        imagepalettetotruecolor($image);

        return $image;
    }

    public static function resize(GdImage $image, int $maxSide): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($longest <= $maxSide) {
            return $image;
        }

        $scale = $maxSide / $longest;
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);

        return $resized;
    }

    /** JPEG em memória; libera a imagem. */
    public static function jpeg(GdImage $image, int $quality = 80): string
    {
        ob_start();
        imagejpeg($image, null, $quality);
        $binary = (string) ob_get_clean();
        imagedestroy($image);

        return $binary;
    }

    /** Fonte TTF que vem com o dompdf (a mesma no servidor e no computador de quem desenvolve). */
    public static function font(bool $bold = true): ?string
    {
        foreach ([
            base_path('vendor/dompdf/dompdf/lib/fonts/'.($bold ? 'DejaVuSansMono-Bold.ttf' : 'DejaVuSansMono.ttf')),
            base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf'),
            '/usr/share/fonts/truetype/dejavu/DejaVuSansMono-Bold.ttf',
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function applyExifOrientation(GdImage $image, string $path): GdImage
    {
        if (! function_exists('exif_read_data') || ! is_file($path)) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };

        if ($rotated instanceof GdImage) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }
}
