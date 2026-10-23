<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * deploy/publicar.sh roda no servidor depois do envio, que não leva storage/ nem bootstrap/cache/.
 * Na primeira publicação essas pastas não existem: qualquer `php artisan` antes de criá-las quebra.
 */
class DeployScriptTest extends TestCase
{
    private function script(): string
    {
        return (string) file_get_contents(base_path('deploy/publicar.sh'));
    }

    public function test_pastas_de_trabalho_nascem_antes_do_primeiro_comando_do_laravel(): void
    {
        $script = $this->script();
        $mkdir = strpos($script, 'mkdir -p storage/logs');
        $artisan = strpos($script, 'php artisan');

        $this->assertNotFalse($mkdir);
        $this->assertStringContainsString('bootstrap/cache', substr($script, $mkdir, 200));
        $this->assertLessThan($artisan, $mkdir, 'Criar storage/ e bootstrap/cache/ antes de qualquer php artisan.');
    }

    public function test_chave_do_app_e_gerada_sempre_que_estiver_vazia(): void
    {
        // Uma primeira publicação que parou no meio deixa o .env sem APP_KEY: a próxima tem que gerar.
        $this->assertMatchesRegularExpression("/if ! grep -qE '\\^APP_KEY=\\.\\+' \\.env; then\\s+php artisan key:generate/", $this->script());
    }
}
