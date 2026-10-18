<?php

namespace App\Support;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Conferência do deploy: o `vendor/` instalado no servidor bate com o `composer.lock` do código?
 * Quando o deploy faz só `git pull` (sem `composer install`), o código novo roda com pacotes antigos
 * e quebra de jeitos difíceis de entender (já aconteceu três vezes). A barra avisa o administrador e
 * `php artisan sistema:conferir-deploy` mostra o detalhe.
 */
final class DeployCheck
{
    /** Pacotes que aparecem primeiro no aviso (os que mais quebram telas). */
    private const KEY_PACKAGES = ['laravel/framework', 'livewire/livewire'];

    /** Outro `composer.lock` (só nos testes). */
    public static ?string $lockPath = null;

    /**
     * Pacotes de produção cuja versão instalada difere da do `composer.lock` (ou que nem estão
     * instalados). Guardado em cache até o lock ou o vendor mudarem.
     *
     * @return array<string, array{installed: ?string, required: string}>
     */
    public static function outdatedPackages(): array
    {
        $lock = self::$lockPath ?? base_path('composer.lock');
        $installed = base_path('vendor/composer/installed.php');

        if (! is_file($lock)) {
            return [];
        }

        $key = 'deploy-check:'.md5($lock).':'.filemtime($lock).':'.(is_file($installed) ? filemtime($installed) : 0);

        try {
            return Cache::rememberForever($key, fn () => self::compare($lock));
        } catch (Throwable) {
            return self::compare($lock);
        }
    }

    /**
     * Linha curta para o aviso: "laravel/framework 12.48.1 (o sistema pede 12.69.3), livewire/livewire … e mais 3".
     */
    public static function summary(): ?string
    {
        $outdated = self::outdatedPackages();

        if ($outdated === []) {
            return null;
        }

        $ordered = collect($outdated)->sortKeysUsing(fn (string $a, string $b) => self::rank($a) <=> self::rank($b) ?: strcmp($a, $b));
        $shown = $ordered->take(2)->map(fn (array $v, string $name) => $name.' '.($v['installed'] ?? 'não instalado').' (o sistema pede '.$v['required'].')')->implode(', ');

        return $shown.($ordered->count() > 2 ? ' e mais '.($ordered->count() - 2) : '');
    }

    /**
     * @return array<string, array{installed: ?string, required: string}>
     */
    private static function compare(string $lock): array
    {
        $data = json_decode((string) file_get_contents($lock), true);
        $outdated = [];

        foreach ($data['packages'] ?? [] as $package) {
            $name = $package['name'] ?? null;
            $required = (string) ($package['version'] ?? '');

            if (! $name || $required === '') {
                continue;
            }

            $installed = InstalledVersions::isInstalled($name) ? InstalledVersions::getPrettyVersion($name) : null;

            if (self::normalize($installed) !== self::normalize($required)) {
                $outdated[$name] = ['installed' => $installed !== null ? self::normalize($installed) : null, 'required' => self::normalize($required)];
            }
        }

        return $outdated;
    }

    private static function normalize(?string $version): ?string
    {
        return $version === null ? null : ltrim($version, 'v');
    }

    private static function rank(string $name): int
    {
        $index = array_search($name, self::KEY_PACKAGES, true);

        return $index === false ? count(self::KEY_PACKAGES) : $index;
    }
}
