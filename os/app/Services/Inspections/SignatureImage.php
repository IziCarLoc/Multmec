<?php

namespace App\Services\Inspections;

use DomainException;
use GdImage;

/**
 * Assinatura desenhada com o dedo (PNG em data URL do canvas). Recusa arquivo que não é PNG, grande demais
 * ou em branco (sem traço).
 */
final class SignatureImage
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    public const MAX_PIXELS = 4_000_000;

    public static function decode(string $dataUrl): string
    {
        if (preg_match('#^data:image/png;base64,(.+)$#s', $dataUrl, $match) !== 1) {
            throw new DomainException('Assinatura inválida. Assine de novo.');
        }

        $binary = base64_decode($match[1], true);

        if ($binary === false || strlen($binary) > self::MAX_BYTES) {
            throw new DomainException('Assinatura inválida. Assine de novo.');
        }

        $info = @getimagesizefromstring($binary);

        if ($info === false || $info[2] !== IMAGETYPE_PNG || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new DomainException('Assinatura inválida. Assine de novo.');
        }

        $image = @imagecreatefromstring($binary);

        if (! $image instanceof GdImage || ! self::hasInk($image)) {
            throw new DomainException('Assine no quadro antes de confirmar.');
        }

        imagedestroy($image);

        return $binary;
    }

    /** Tem traço: algum pixel visível e escuro (amostra em grade, rápido mesmo em tela grande). */
    private static function hasInk(GdImage $image): bool
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $step = max(1, (int) floor(min($width, $height) / 150));
        $dark = 0;

        for ($y = 0; $y < $height; $y += $step) {
            for ($x = 0; $x < $width; $x += $step) {
                $rgba = imagecolorat($image, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;
                $luma = ((($rgba >> 16) & 0xFF) + (($rgba >> 8) & 0xFF) + ($rgba & 0xFF)) / 3;

                if ($alpha < 100 && $luma < 160 && ++$dark >= 8) {
                    return true;
                }
            }
        }

        return false;
    }
}
