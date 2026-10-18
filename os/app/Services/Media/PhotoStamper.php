<?php

namespace App\Services\Media;

use GdImage;

/**
 * Carimbo das fotos, com o desenho do v12: faixa escura embaixo da foto, uma marca na cor do tipo à
 * esquerda, linha 1 "OS 1250  ABC1D23  PEÇA VELHA" e linha 2 "dd/mm/aaaa hh:mm:ss · legenda".
 * A hora é a do servidor (a do celular pode estar errada). Trabalha numa cópia: o arquivo recebido
 * fica intacto.
 */
final class PhotoStamper
{
    public const BAND = [0x10, 0x16, 0x1B];

    public const LINE1 = [0xFF, 0xFF, 0xFF];

    public const LINE2 = [0xC9, 0xD2, 0xD9];

    /** Cor da marca por tipo de foto (como no v12: avaria vermelha, peça velha ferrugem, peça nova verde). */
    public const KIND_COLORS = [
        'AVARIA' => [0xD7, 0x19, 0x20],
        'PEÇA VELHA' => [0xD9, 0x67, 0x3B],
        'PEÇA NOVA' => [0x3F, 0xAE, 0x6B],
        'RODA' => [0xFE, 0xB9, 0x51],
        'LACRE' => [0xFE, 0xB9, 0x51],
        'NÃO CONFORMIDADE' => [0xD7, 0x19, 0x20],
    ];

    public const DEFAULT_COLOR = [0x08, 0x74, 0xBD];

    /** Acrescenta a faixa embaixo (a foto fica mais alta; nada da foto é coberto). */
    public function stamp(GdImage $image, string $line1, string $line2, string $kind): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $band = max(54, (int) round($width * 0.06));

        $canvas = imagecreatetruecolor($width, $height + $band);
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);
        imagedestroy($image);

        imagefilledrectangle($canvas, 0, $height, $width, $height + $band, imagecolorallocate($canvas, ...self::BAND));
        $marker = self::KIND_COLORS[$kind] ?? self::DEFAULT_COLOR;
        imagefilledrectangle($canvas, 0, $height, max(4, (int) round($band * 0.18)), $height + $band, imagecolorallocate($canvas, ...$marker));

        $padding = (int) round($band * 0.32);
        $size1 = (int) round($band * 0.30);
        $maxWidth = $width - 2 * $padding;

        $this->text($canvas, $line1, $padding, $height + (int) round($band * 0.32), $size1, imagecolorallocate($canvas, ...self::LINE1), $maxWidth, true);
        $this->text($canvas, $line2, $padding, $height + (int) round($band * 0.72), (int) round($size1 * 0.85), imagecolorallocate($canvas, ...self::LINE2), $maxWidth, false);

        return $canvas;
    }

    /** Texto alinhado à esquerda e centrado na altura $cy, diminuindo até caber; sem TTF usa a fonte do GD. */
    private function text(GdImage $image, string $text, int $x, int $cy, int $size, int $color, int $maxWidth, bool $bold): void
    {
        $font = ImageTools::font($bold);

        if ($font && function_exists('imagettftext')) {
            $size = max(8, $size);

            while ($size > 8 && $this->width($size, $font, $text) > $maxWidth) {
                $size--;
            }

            while ($this->width($size, $font, $text) > $maxWidth && mb_strlen($text) > 4) {
                $text = mb_substr($text, 0, -2).'…';
            }

            $box = imagettfbbox($size, 0, $font, $text);
            $textHeight = $box[1] - $box[7];
            imagettftext($image, $size, 0, $x, $cy + (int) round($textHeight / 2) - 1, $color, $font, $text);

            return;
        }

        // Sem FreeType: fonte interna do GD (sem acento), ampliada.
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
        $charHeight = imagefontheight(5);
        $scale = max(1, (int) round($size / $charHeight));
        $tmpWidth = imagefontwidth(5) * strlen($ascii);
        $tmp = imagecreatetruecolor(max(1, $tmpWidth), $charHeight);
        imagefill($tmp, 0, 0, imagecolorallocate($tmp, ...self::BAND));
        $rgb = imagecolorsforindex($image, $color);
        imagestring($tmp, 5, 0, 0, $ascii, imagecolorallocate($tmp, $rgb['red'], $rgb['green'], $rgb['blue']));
        $destWidth = min($maxWidth, $tmpWidth * $scale);
        imagecopyresized($image, $tmp, $x, $cy - (int) round($charHeight * $scale / 2), 0, 0, $destWidth, $charHeight * $scale, (int) round($destWidth / $scale), $charHeight);
        imagedestroy($tmp);
    }

    private function width(int $size, string $font, string $text): int
    {
        $box = imagettfbbox($size, 0, $font, $text);

        return $box[2] - $box[0];
    }
}
