<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Produção é MySQL, que recusa nomes de índice/unique/FK com mais de 64 caracteres; o SQLite dos
 * testes aceita qualquer tamanho. Este teste barra o nome longo antes do deploy.
 */
class MigrationIdentifierLengthTest extends TestCase
{
    use RefreshDatabase;

    private const MYSQL_LIMIT = 64;

    public function test_nomes_de_indice_e_chave_estrangeira_cabem_no_mysql(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('No MySQL/MariaDB a própria migration já recusa o nome longo.');
        }

        $tooLong = [];
        $tables = DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'");

        foreach ($tables as $table) {
            if (mb_strlen($table->name) > self::MYSQL_LIMIT) {
                $tooLong[] = 'tabela '.$table->name;
            }

            // Nome padrão do Laravel para a FK (o SQLite não guarda o nome dado à constraint).
            foreach (DB::select("pragma foreign_key_list('{$table->name}')") as $foreign) {
                $name = $table->name.'_'.$foreign->from.'_foreign';
                if (mb_strlen($name) > self::MYSQL_LIMIT) {
                    $tooLong[] = $name;
                }
            }
        }

        foreach (DB::select("select name from sqlite_master where type = 'index' and name not like 'sqlite_autoindex_%'") as $index) {
            if (mb_strlen($index->name) > self::MYSQL_LIMIT) {
                $tooLong[] = $index->name;
            }
        }

        $this->assertSame([], $tooLong, 'Nome com mais de 64 caracteres (o MySQL recusa): dê um nome curto explícito.');
    }
}
