<?php

namespace Tests\Unit;

use App\Support\BusinessDays;
use PHPUnit\Framework\TestCase;

/**
 * Dias úteis: os mesmos vetores do `diasUteis` do financeiro em Node (app/test/util.test.js) e os feriados da Páscoa.
 */
class BusinessDaysTest extends TestCase
{
    public function test_vetores_do_node_fim_de_semana_feriado_e_sabado(): void
    {
        // outubro/2026 tem 22 dias úteis; menos o feriado de 12/10.
        $days = BusinessDays::month('2026-10', ['2026-10-12']);
        $this->assertCount(21, $days);
        $this->assertNotContains('2026-10-12', array_column($days, 'date'));

        // Sábado valendo meio dia: os 22 dias úteis mais os 5 sábados.
        $withSaturday = BusinessDays::month('2026-10', [], 0.5);
        $this->assertCount(22 + 5, $withSaturday);
        $this->assertSame(22 + 2.5, BusinessDays::total($withSaturday));
    }

    public function test_total_conta_o_peso_ate_e_depois_de_um_dia(): void
    {
        $days = BusinessDays::month('2026-10');

        $this->assertSame(22.0, BusinessDays::total($days));
        $this->assertSame(2.0, BusinessDays::total($days, null, '2026-10-02')); // quinta 1º e sexta 2
        $this->assertSame(20.0, BusinessDays::total($days, '2026-10-03'));
    }

    public function test_pascoa_e_feriados_moveis(): void
    {
        $this->assertSame('2025-04-20', BusinessDays::easter(2025)->toDateString());
        $this->assertSame('2026-04-05', BusinessDays::easter(2026)->toDateString());
        $this->assertSame('2027-03-28', BusinessDays::easter(2027)->toDateString());

        $holidays = BusinessDays::holidays(2026, ['carnaval', 'sexta_santa', 'corpus_christi']);
        $this->assertSame('Carnaval', $holidays['2026-02-16']);
        $this->assertSame('Carnaval', $holidays['2026-02-17']);
        $this->assertSame('Sexta-feira Santa', $holidays['2026-04-03']);
        $this->assertSame('Corpus Christi', $holidays['2026-06-04']);
    }

    public function test_nacionais_de_data_fixa_e_consciencia_negra_desde_2024(): void
    {
        $holidays = BusinessDays::holidays(2026);

        $this->assertSame(['2026-01-01', '2026-04-21', '2026-05-01', '2026-09-07', '2026-10-12', '2026-11-02', '2026-11-15', '2026-11-20', '2026-12-25'], array_keys($holidays));
        $this->assertArrayNotHasKey('2023-11-20', BusinessDays::holidays(2023));
        $this->assertArrayNotHasKey('2026-04-03', $holidays, 'Sexta-feira Santa só quando marcada nos Ajustes');

        // Novembro/2026: 21 dias de semana, menos Finados (segunda) e Consciência Negra (sexta); 15/11 é domingo.
        $this->assertCount(19, BusinessDays::month('2026-11', array_keys($holidays)));
    }

    public function test_dias_extras_entram_so_no_ano_deles(): void
    {
        $holidays = BusinessDays::holidays(2026, [], ['2026-09-20', '2027-01-25', 'lixo']);

        $this->assertArrayHasKey('2026-09-20', $holidays);
        $this->assertArrayNotHasKey('2027-01-25', $holidays);
    }
}
