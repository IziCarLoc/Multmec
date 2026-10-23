<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * No Livewire 4, `wire:model.blur` (sem `.live`) só atualiza o valor no navegador ao sair do campo: nada vai
 * para o servidor até a próxima ação. Campo que grava sozinho ao sair (km, preço, custo da cotação) precisa
 * de `wire:model.live.blur`; senão o último campo digitado se perde quando a pessoa sai da tela.
 */
class WireModelSyncTest extends TestCase
{
    public function test_campo_que_grava_ao_sair_usa_live_blur(): void
    {
        $root = dirname(__DIR__, 2).'/resources/views';
        $found = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            foreach (file($file->getPathname()) as $number => $line) {
                if (preg_match('/wire:model\.blur\b/', $line)) {
                    $found[] = substr($file->getPathname(), strlen($root) + 1).':'.($number + 1);
                }
            }
        }

        $this->assertSame([], $found, 'Use wire:model.live.blur (o .blur sozinho não envia ao servidor): '.implode(', ', $found));
    }
}
