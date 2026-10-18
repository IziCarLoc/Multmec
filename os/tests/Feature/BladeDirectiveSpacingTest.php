<?php

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use ParseError;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * O Blade só reconhece uma diretiva (`@if`, `@endif`, `@foreach`…) quando antes do `@` não há letra,
 * número ou `_`. Colada em texto ou em outra diretiva (`</li>@endforeach@if(...)`, `na frota@else`),
 * ela vira texto e sobra um `@endif` sem par: erro 500 ("unexpected token endif"). Versões novas do
 * Livewire escondem parte desses casos com um pré-compilador, as antigas não — por isso o teste
 * olha o fonte das views, não o resultado compilado.
 */
class BladeDirectiveSpacingTest extends TestCase
{
    private const DIRECTIVES = 'if|elseif|else|endif|foreach|endforeach|forelse|empty|endforelse|unless|endunless|isset|endisset'
        .'|can|endcan|cannot|endcannot|error|enderror|php|endphp|switch|case|endswitch|auth|endauth|guest|endguest'
        .'|for|endfor|while|endwhile|once|endonce|push|endpush|include|livewire';

    public function test_diretivas_blade_nao_ficam_coladas_em_letra_ou_numero(): void
    {
        $root = dirname(__DIR__, 2).'/resources/views';
        $pattern = '/\w{1,20}@('.self::DIRECTIVES.')\b/';
        $glued = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            foreach (file($file->getPathname()) as $number => $line) {
                if (preg_match_all($pattern, $line, $matches)) {
                    foreach ($matches[0] as $match) {
                        $glued[] = substr($file->getPathname(), strlen($root) + 1).':'.($number + 1).' → '.$match;
                    }
                }
            }
        }

        $this->assertSame([], $glued, "Diretiva Blade colada (ponha um espaço antes do @):\n".implode("\n", $glued));
    }

    /**
     * Tag de componente (`<x-…>`, `<livewire:…>`) dentro de um `<script>` inline é compilada pelo Blade, mesmo
     * numa linha de comentário JS: o script do layout quebra inteiro (`moneyInput is not defined`) e os campos
     * de dinheiro e de CPF/CNPJ deixam de funcionar em todas as telas. Aconteceu uma vez com um comentário.
     */
    public function test_script_inline_nao_tem_tag_de_componente_blade(): void
    {
        $root = dirname(__DIR__, 2).'/resources/views';
        $found = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (! preg_match_all('/<script(?![^>]*\ssrc=)[^>]*>(.*?)<\/script>/s', $source, $blocks)) {
                continue;
            }

            foreach ($blocks[1] as $block) {
                if (preg_match_all('/<(x-[a-z][\w.-]*|livewire:[\w.-]+)/', $block, $tags)) {
                    $found[] = substr($file->getPathname(), strlen($root) + 1).' → <'.implode('>, <', array_unique($tags[1])).'>';
                }
            }
        }

        $this->assertSame([], $found, "Tag de componente Blade dentro de <script> (reescreva o comentário sem a tag):\n".implode("\n", $found));
    }

    /**
     * Cada view compilada só pelo Blade, sem os pré-compiladores do Livewire, tem de ser PHP válido
     * (é o que o servidor faz quando o vendor está numa versão antiga).
     */
    public function test_views_compiladas_so_pelo_blade_sao_php_valido(): void
    {
        $root = dirname(__DIR__, 2).'/resources/views';
        // Um compilador novo, sem os pré-compiladores que o Livewire registra no da aplicação.
        $compiler = new BladeCompiler(new Filesystem, sys_get_temp_dir());
        $broken = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            try {
                token_get_all($compiler->compileString(file_get_contents($file->getPathname())), TOKEN_PARSE);
            } catch (ParseError $e) {
                $broken[] = substr($file->getPathname(), strlen($root) + 1).': '.$e->getMessage();
            }
        }

        $this->assertSame([], $broken, "View que não compila:\n".implode("\n", $broken));
    }
}
