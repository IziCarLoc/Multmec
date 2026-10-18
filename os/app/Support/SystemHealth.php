<?php

namespace App\Support;

use App\Models\SystemHeartbeat;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * O que o servidor precisa ter para o sistema funcionar (tela Saúde do sistema). Os limites do PHP que
 * valem são os do site (PHP-FPM), não os do terminal: por isso esta conferência roda dentro do navegador.
 */
final class SystemHealth
{
    /** Vídeo de até 20 MB: o envio precisa de folga acima disso. */
    public const MIN_UPLOAD_BYTES = 25 * 1024 * 1024;

    public const MIN_MEMORY_BYTES = 256 * 1024 * 1024;

    /**
     * @return list<array{label: string, value: string, ok: bool, hint: ?string}>
     */
    public static function checks(): array
    {
        $upload = self::bytes((string) ini_get('upload_max_filesize'));
        $post = self::bytes((string) ini_get('post_max_size'));
        $memory = self::bytes((string) ini_get('memory_limit'));
        $time = (int) ini_get('max_execution_time');
        $gd = function_exists('gd_info') ? gd_info() : [];
        $lastBeat = SystemHeartbeat::lastRun(SystemHeartbeat::SCHEDULER);

        return [
            self::row('Versão do PHP', PHP_VERSION, version_compare(PHP_VERSION, '8.2.0', '>='), 'O sistema precisa de PHP 8.2 ou mais novo (o recomendado é 8.3).'),
            self::row('Tamanho máximo de envio (upload_max_filesize)', (string) ini_get('upload_max_filesize'), $upload >= self::MIN_UPLOAD_BYTES, 'Ponha 25M no hPanel (PHP > Opções do PHP): os vídeos têm até 20 MB.'),
            self::row('Tamanho máximo do formulário (post_max_size)', (string) ini_get('post_max_size'), $post >= self::MIN_UPLOAD_BYTES && $post >= $upload, 'Ponha 30M no hPanel (precisa ser maior que o upload_max_filesize).'),
            self::row('Memória (memory_limit)', (string) ini_get('memory_limit'), $memory === -1 || $memory >= self::MIN_MEMORY_BYTES, 'Ponha 512M no hPanel: o PDF com fotos usa bastante memória.'),
            self::row('Tempo máximo por pedido (max_execution_time)', $time === 0 ? 'sem limite' : $time.' s', $time === 0 || $time >= 60, 'Ponha 120 no hPanel: gerar o PDF da OS pode levar alguns segundos.'),
            self::row('GD com FreeType (carimbo das fotos)', ($gd['FreeType Support'] ?? false) ? 'sim' : 'não', (bool) ($gd['FreeType Support'] ?? false), 'Sem FreeType o carimbo sai com letra simples (sem acento).'),
            self::row('GD com JPEG e WebP', (($gd['JPEG Support'] ?? false) ? 'JPEG ' : '').(($gd['WebP Support'] ?? false) ? 'WebP' : ''), (bool) ($gd['JPEG Support'] ?? false), 'Peça à hospedagem a extensão GD com JPEG.'),
            self::row('EXIF (foto deitada ou de pé)', function_exists('exif_read_data') ? 'sim' : 'não', function_exists('exif_read_data'), 'Ative a extensão exif no hPanel.'),
            self::row('Fileinfo (tipo do arquivo enviado)', extension_loaded('fileinfo') ? 'sim' : 'não', extension_loaded('fileinfo'), 'Ative a extensão fileinfo no hPanel.'),
            self::row('Intl e mbstring (português)', (extension_loaded('intl') ? 'intl ' : '').(extension_loaded('mbstring') ? 'mbstring' : ''), extension_loaded('mbstring'), 'Ative mbstring e intl no hPanel.'),
            self::row('Banco de dados', self::database(), self::database() !== 'sem conexão', 'Confira DB_HOST, DB_DATABASE, DB_USERNAME e DB_PASSWORD no .env.'),
            self::row('Agendador (cron a cada minuto)', $lastBeat ? 'último em '.$lastBeat->format('d/m H:i:s') : 'nunca rodou', SystemHeartbeat::schedulerIsAlive(), 'Crie no hPanel o cron "* * * * *" com: php .../os/artisan schedule:run'),
            self::row('Conexão segura (https)', request()->isSecure() ? 'sim' : 'não', request()->isSecure() || app()->environment('local', 'testing'), 'Ative o SSL do subdomínio no hPanel. Sem https a câmera e o hash das fotos não funcionam.'),
            self::row('Modo de produção', app()->environment().', debug '.(config('app.debug') ? 'ligado' : 'desligado'), ! app()->isProduction() || ! config('app.debug'), 'Em produção: APP_DEBUG=false no .env.'),
            self::row('Fuso horário', config('app.timezone').' (agora '.now()->format('d/m/Y H:i:s').')', config('app.timezone') === 'America/Sao_Paulo', null),
            self::row('Pasta storage gravável', is_writable(storage_path('app/private')) ? 'sim' : 'não', is_writable(storage_path('app/private')), 'Permissão de escrita em storage/ (chmod -R 775 storage bootstrap/cache).'),
        ];
    }

    /**
     * Espaço das fotos, vídeos e PDFs (storage/app/private) e quantidade de arquivos (a Hostinger
     * também limita o número de arquivos), mais o espaço livre do disco.
     *
     * @return array{bytes: int, files: int, free: ?int, total: ?int}
     */
    public static function storageUsage(): array
    {
        $root = storage_path('app/private');
        $bytes = 0;
        $files = 0;

        if (is_dir($root)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile()) {
                    $bytes += $file->getSize();
                    $files++;
                }
            }
        }

        $free = @disk_free_space($root);
        $total = @disk_total_space($root);

        return ['bytes' => $bytes, 'files' => $files, 'free' => $free === false ? null : (int) $free, 'total' => $total === false ? null : (int) $total];
    }

    public static function humanBytes(?int $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }

        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($bytes < 1024 || $unit === 'TB') {
                return number_format($bytes, $unit === 'B' ? 0 : 1, ',', '.').' '.$unit;
            }
            $bytes /= 1024;
        }

        return '';
    }

    /** "25M", "1G", "512K", "-1" → bytes (-1 = sem limite). */
    public static function bytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (float) $value;

        return (int) match (strtoupper(substr($value, -1))) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        };
    }

    private static function database(): string
    {
        try {
            DB::connection()->getPdo();

            return DB::connection()->getDriverName().' '.DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        } catch (Throwable) {
            return 'sem conexão';
        }
    }

    /** @return array{label: string, value: string, ok: bool, hint: ?string} */
    private static function row(string $label, string $value, bool $ok, ?string $hint): array
    {
        return ['label' => $label, 'value' => $value, 'ok' => $ok, 'hint' => $ok ? null : $hint];
    }
}
