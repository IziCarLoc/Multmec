<?php

namespace App\Console\Commands;

use App\Models\SystemHeartbeat;
use App\Support\DeployCheck;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Throwable;

/**
 * Último passo do deploy: confere se o servidor está como o código pede (pacotes do composer.lock,
 * migrations, caches, APP_DEBUG, agendador). Só lê; sai com código 1 se algo estiver atrasado.
 * Os limites do PHP do site (upload, memória) são conferidos em Ajustes > Saúde do sistema, porque o
 * PHP do terminal tem outra configuração.
 */
class CheckDeploy extends Command
{
    protected $signature = 'sistema:conferir-deploy';

    protected $description = 'Confere pacotes do vendor, migrations pendentes, caches, APP_DEBUG e o agendador depois do deploy';

    public function handle(Migrator $migrator): int
    {
        $problems = 0;

        $outdated = DeployCheck::outdatedPackages();

        if ($outdated === []) {
            $this->info('Pacotes: o vendor bate com o composer.lock.');
        } else {
            $problems++;
            $this->error(count($outdated).' pacote(s) diferente(s) do composer.lock: rode composer install --no-dev --optimize-autoloader e depois php artisan optimize.');
            $this->table(['Pacote', 'Instalado', 'O sistema pede'], collect($outdated)->map(fn (array $v, string $name) => [$name, $v['installed'] ?? 'não instalado', $v['required']])->values()->all());
        }

        $pending = $this->pendingMigrations($migrator);

        if ($pending === null) {
            $problems++;
            $this->error('Migrations: não deu para ler a tabela de migrations (banco fora do ar ou sem migrate).');
        } elseif ($pending === []) {
            $this->info('Migrations: nenhuma pendente.');
        } else {
            $problems++;
            $this->error(count($pending).' migration(s) pendente(s): rode php artisan migrate --force.');
            foreach ($pending as $name) {
                $this->line('  - '.$name);
            }
        }

        $this->line('Caches: config '.$this->yesNo(app()->configurationIsCached()).' · rotas '.$this->yesNo(app()->routesAreCached()).' · eventos '.$this->yesNo(app()->eventsAreCached()).' (php artisan optimize liga os três).');

        if (config('app.debug') && app()->isProduction()) {
            $problems++;
            $this->error('APP_DEBUG está ligado em produção: mostra detalhes do erro para quem usa o sistema. Ponha APP_DEBUG=false.');
        } else {
            $this->line('APP_DEBUG: '.(config('app.debug') ? 'ligado (fora de produção)' : 'desligado').'.');
        }

        if (app()->environment('local') && ! config('app.debug')) {
            $problems++;
            $this->error('APP_ENV=local com APP_DEBUG=false parece um servidor de verdade: ponha APP_ENV=production.');
        }

        $last = SystemHeartbeat::lastRun(SystemHeartbeat::SCHEDULER);

        if (SystemHeartbeat::schedulerIsAlive()) {
            $this->info('Agendador: rodou às '.$last->format('d/m/Y H:i').'.');
        } else {
            $this->warn('Agendador: '.($last ? 'não roda desde '.$last->format('d/m/Y H:i') : 'nunca rodou').'. Confira o cron "* * * * * php artisan schedule:run" no hPanel (logo depois do primeiro deploy é normal).');
        }

        if ($problems > 0) {
            $this->error('Deploy incompleto: corrija o que está acima e rode este comando de novo.');

            return self::FAILURE;
        }

        $this->info('Deploy conferido.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>|null
     */
    private function pendingMigrations(Migrator $migrator): ?array
    {
        try {
            $files = $migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')]));

            if (! $migrator->repositoryExists()) {
                return array_keys($files);
            }

            return array_values(array_diff(array_keys($files), $migrator->getRepository()->getRan()));
        } catch (Throwable) {
            return null;
        }
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'sim' : 'não';
    }
}
