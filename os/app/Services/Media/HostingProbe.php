<?php

namespace App\Services\Media;

use Barryvdh\DomPDF\Facade\Pdf;
use GdImage;

/**
 * Testes da hospedagem (tela Saúde do sistema): foto carimbada com a fonte do servidor e PDF com
 * 30 fotos geradas na hora, para medir memória e tempo do dompdf antes do piloto.
 */
class HostingProbe
{
    public function __construct(private PhotoStamper $stamper) {}

    /** Foto de teste 1600 × 1200 com o carimbo, em data URI (JPEG). */
    public function stampedSample(): string
    {
        $image = $this->syntheticPhoto(1600, 1200, 7);
        $image = $this->stamper->stamp($image, 'OS 1250  ABC1D23  PEÇA VELHA', now()->format('d/m/Y H:i:s').'  ·  Pastilha de freio dianteira', 'PEÇA VELHA');

        return 'data:image/jpeg;base64,'.base64_encode(ImageTools::jpeg($image, 80));
    }

    /**
     * PDF com N fotos em miniatura de 800 px (como no PDF da OS).
     *
     * @return array{pdf: string, seconds: float, peak_mb: float}
     */
    public function pdfWithPhotos(int $count = 30): array
    {
        $start = microtime(true);
        @set_time_limit(120);

        $photos = [];

        for ($i = 1; $i <= $count; $i++) {
            $image = $this->stamper->stamp($this->syntheticPhoto(1600, 1200, $i), 'OS 1250  ABC1D23  FOTO '.$i, now()->format('d/m/Y H:i:s'), 'GERAL');
            $photos[] = 'data:image/jpeg;base64,'.base64_encode(ImageTools::jpeg(ImageTools::resize($image, 800), 72));
        }

        $pdf = Pdf::loadView('pdf.health-test', ['photos' => $photos, 'generatedAt' => now()])->setPaper('a4')->output();

        return [
            'pdf' => $pdf,
            'seconds' => round(microtime(true) - $start, 2),
            'peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
        ];
    }

    /** "Foto" colorida com textura (comprime como uma foto de verdade, não como um bloco liso). */
    private function syntheticPhoto(int $width, int $height, int $seed): GdImage
    {
        mt_srand($seed);
        $image = imagecreatetruecolor($width, $height);
        $base = [mt_rand(40, 200), mt_rand(40, 200), mt_rand(40, 200)];

        for ($y = 0; $y < $height; $y += 8) {
            for ($x = 0; $x < $width; $x += 8) {
                $noise = mt_rand(-28, 28);
                $color = imagecolorallocate($image, max(0, min(255, $base[0] + $noise + (int) ($x / 20))), max(0, min(255, $base[1] + $noise)), max(0, min(255, $base[2] + $noise + (int) ($y / 20))));
                imagefilledrectangle($image, $x, $y, $x + 7, $y + 7, $color);
            }
        }

        mt_srand();

        return $image;
    }
}
