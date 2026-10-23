<?php

namespace Tests\Unit\Purchases;

use App\Support\Purchases\BoletoCode;
use PHPUnit\Framework\TestCase;

/**
 * Porte de `app/test/boleto.test.js` e `boleto_pesquisa.test.js`: vetores gerados por uma implementação independente
 * dos algoritmos FEBRABAN e exemplos dos manuais do Banco do Brasil, Santander, Banpará e Inter.
 */
class BoletoCodeTest extends TestCase
{
    private const TODAY = '2026-10-08';

    private const BANK_VECTORS = [
        ['line' => '75690181589083016613918609139094934710052992313', 'barcode' => '75699347100529923130181590830166131860913909', 'cents' => 52992313, 'factor' => '3471'],
        ['line' => '07790824602819482199835181909371218120029673101', 'barcode' => '07792181200296731010824628194821993518190937', 'cents' => 29673101, 'factor' => '1812'],
        ['line' => '07799754322319487574891186252762961460062492025', 'barcode' => '07799614600624920259754323194875749118625276', 'cents' => 62492025, 'factor' => '6146'],
        ['line' => '00199555907971147104997465075295122710074903660', 'barcode' => '00191227100749036609555979711471049746507529', 'cents' => 74903660, 'factor' => '2271'],
    ];

    private const CONVENIO_VECTORS = [
        ['line' => '896300000453399887789325879217421806967929081008', 'barcode' => '89630000045399887789328792174218096792908100', 'cents' => 453998],
        ['line' => '898400000093325483783263616630494031269910237409', 'barcode' => '89840000009325483783266166304940326991023740', 'cents' => 93254],
    ];

    private function read(string $text, string $today = self::TODAY): array
    {
        return BoletoCode::read($text, $today);
    }

    public function test_linha_e_codigo_de_barras_de_banco_batem_com_vetores_independentes(): void
    {
        foreach (self::BANK_VECTORS as $v) {
            $a = $this->read($v['line']);
            $b = $this->read($v['barcode']);
            $this->assertTrue($a['ok'], implode(' ', $a['errors']));
            $this->assertTrue($b['ok'], implode(' ', $b['errors']));
            $this->assertSame($v['barcode'], $a['barcode']);
            $this->assertSame($v['line'], $b['line']);
            $this->assertSame($v['cents'], $a['amount_cents']);
            $this->assertSame($v['factor'], $a['factor']);
        }
    }

    public function test_aceita_a_linha_com_pontos_e_espacos_como_vem_impressa(): void
    {
        $this->assertTrue($this->read(BoletoCode::formatLine(self::BANK_VECTORS[0]['line']))['ok']);
    }

    public function test_um_digito_errado_campos_1_a_3_sempre_detectam_e_o_resto_quase_sempre(): void
    {
        $tested = 0;
        $detected = 0;

        foreach (self::BANK_VECTORS as $v) {
            for ($i = 0; $i < 47; $i++) {
                $line = $v['line'];
                $line[$i] = (string) (((int) $line[$i] + 1) % 10);
                $ok = $this->read($line)['ok'];

                if ($i < 32) {
                    $this->assertFalse($ok, "posição {$i} (campos 1 a 3) passou sem erro");
                }

                $tested++;
                $detected += $ok ? 0 : 1;
            }
        }

        $this->assertGreaterThanOrEqual(0.97, $detected / $tested);
    }

    public function test_tamanho_invalido_e_recusado_com_mensagem_clara(): void
    {
        $r = $this->read('1234');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('dígitos', $r['errors'][0]);
        $this->assertStringContainsString('43', $this->read('0019337370000000100050094014481606068093503')['errors'][0]);
    }

    public function test_boleto_de_convenio_digitos_verificadores_e_valor(): void
    {
        foreach (self::CONVENIO_VECTORS as $v) {
            $a = $this->read($v['line']);
            $b = $this->read($v['barcode']);
            $this->assertSame('convenio', $a['type']);
            $this->assertTrue($a['ok'], implode(' ', $a['errors']));
            $this->assertTrue($b['ok'], implode(' ', $b['errors']));
            $this->assertSame($v['barcode'], $a['barcode']);
            $this->assertSame($v['cents'], $a['amount_cents']);
        }

        $line = self::CONVENIO_VECTORS[0]['line'];
        $line[5] = (string) (((int) $line[5] + 1) % 10);
        $this->assertFalse($this->read($line)['ok']);
    }

    public function test_fator_de_vencimento_ciclo_antigo_e_novo(): void
    {
        $this->assertSame('2025-02-22', BoletoCode::factorToDate('1000', self::TODAY));
        $this->assertSame('2025-02-21', BoletoCode::factorToDate('9999', '2025-02-10'));
        $this->assertSame('2000-07-03', BoletoCode::factorToDate('1000', '2000-07-01'));
        $this->assertSame('1000', BoletoCode::dateToFactor('2025-02-22'));
        $this->assertSame('9999', BoletoCode::dateToFactor('2025-02-21'));
        $this->assertSame('2026-10-30', BoletoCode::factorToDate(BoletoCode::dateToFactor('2026-10-30'), self::TODAY));
        $this->assertSame('2027-03-15', BoletoCode::factorToDate(BoletoCode::dateToFactor('2027-03-15'), self::TODAY));
    }

    public function test_fator_pela_janela_de_3000_dias_para_tras_e_5500_para_frente(): void
    {
        $cases = ['1593' => '2026-10-08', '1000' => '2025-02-22', '3737' => '2032-08-21', '7093' => '2041-10-29', '7593' => '2018-07-22', '7592' => '2018-07-21', '9999' => '2025-02-21'];

        foreach ($cases as $factor => $date) {
            $this->assertSame($date, BoletoCode::factorToDate((string) $factor, self::TODAY), "fator {$factor}");
        }

        foreach (['7094', '7552', '7591', '0999', '0000', '0001', '10000'] as $factor) {
            $this->assertNull(BoletoCode::factorToDate($factor, self::TODAY), "fator {$factor}");
        }
    }

    public function test_janela_do_exemplo_do_manual_do_inter(): void
    {
        $this->assertSame('2014-03-23', BoletoCode::factorToDate('6011', '2014-03-23'));
        $this->assertSame('2006-01-04', BoletoCode::factorToDate('3011', '2014-03-23'));
        $this->assertSame('2006-01-03', BoletoCode::factorToDate('3010', '2014-03-23'));
        $this->assertSame('2029-04-13', BoletoCode::factorToDate('2511', '2014-03-23'));
        $this->assertNull(BoletoCode::factorToDate('2512', '2014-03-23'));
    }

    public function test_data_para_fator_base_antiga_e_reinicio_de_2025(): void
    {
        $old = ['2000-07-03' => '1000', '2000-07-04' => '1001', '2002-05-01' => '1667', '2007-12-31' => '3737', '2010-11-17' => '4789', '2012-04-27' => '5316', '2025-02-20' => '9998', '2025-02-21' => '9999'];
        $new = ['2025-02-22' => '1000', '2025-02-23' => '1001', '2026-10-08' => '1593', '2026-10-15' => '1600', '2026-12-21' => '1667', '2026-12-31' => '1677', '2035-07-09' => '4789', '2049-10-13' => '9999', '2049-10-14' => '1000'];

        foreach ([...$old, ...$new] as $date => $factor) {
            $this->assertSame((string) $factor, BoletoCode::dateToFactor($date), $date);
        }
    }

    public function test_exemplo_oficial_do_banco_do_brasil(): void
    {
        $barcode = $this->read('00193373700000001000500940144816060680935031');
        $line = $this->read('00190.50095 40144.816069 06809.350314 3 37370000000100');

        foreach ([$barcode, $line] as $r) {
            $this->assertTrue($r['ok']);
            $this->assertSame('001', $r['bank']);
            $this->assertSame(100, $r['amount_cents']);
            $this->assertSame('2032-08-21', $r['due_date']);       // 3737 lido hoje (não 31/12/2007)
            $this->assertSame('00193373700000001000500940144816060680935031', $r['barcode']);
        }

        $this->assertSame('00190500954014481606906809350314337370000000100', $barcode['line']);
    }

    public function test_bordas_do_dv_geral(): void
    {
        foreach (['00191373700000001000500940144816060680935038', '00191373700000001000500940144816060680935033', '00191373700000001000500940144816060680935032'] as $code) {
            $this->assertTrue($this->read($code)['ok'], $code);
        }

        $this->assertTrue($this->read('00199373700000001000500940144816060680935039')['ok']);
        $this->assertSame('00190500954014481606906809350389137370000000100', $this->read('00191373700000001000500940144816060680935038')['line']);
    }

    public function test_linhas_de_bancos_de_manuais_valor_e_vencimento_pela_janela(): void
    {
        $cases = [
            ['23790.44809 56168.623793 36011.058009 7 40430000124020', 124020, '2033-06-23'],
            ['03399.02827 03356.661243 57800.201014 8 20460000027371', 27371, '2028-01-04'],
            ['03790.00094 99100.650003 00000.004028 4 81900000019990', 19990, '2020-03-10'],
            ['23790.00009 00012.345674 89012.345677 5 16000000015075', 15075, '2026-10-15'],
            ['00190.50095 40144.816069 06809.350314 8 16000000123456', 123456, '2026-10-15'],
            ['34196790600001000002220000005566385101214000', 100000, '2019-05-31'],
        ];

        foreach ($cases as [$text, $cents, $due]) {
            $r = $this->read($text);
            $this->assertTrue($r['ok'], $text);
            $this->assertSame($cents, $r['amount_cents'], $text);
            $this->assertSame($due, $r['due_date'], $text);
        }
    }

    public function test_fator_na_faixa_de_seguranca_e_recusado_com_explicacao(): void
    {
        $r = $this->read('23793.38128 60007.827136 95000.063305 9 75520000370000');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('fora da faixa', implode(' ', $r['errors']));
    }

    public function test_fator_0000_e_valor_zero_so_avisam(): void
    {
        $without = $this->read('00190.50095 40144.816069 06809.350314 8 00000000000100');
        $this->assertTrue($without['ok']);
        $this->assertNull($without['due_date']);
        $this->assertStringContainsString('fator 0000', implode(' ', $without['warnings']));

        $zero = $this->read('00190.50095 40144.816069 06809.350314 8 37370000000000');
        $this->assertTrue($zero['ok']);
        $this->assertSame(0, $zero['amount_cents']);
        $this->assertStringContainsString('sem valor definido', implode(' ', $zero['warnings']));
    }

    public function test_adulteracoes_do_exemplo_do_bb_sao_pegas_e_explicadas(): void
    {
        $field1 = $this->read('00190.10095 40144.816069 06809.350314 3 37370000000100');
        $this->assertFalse($field1['ok']);
        $this->assertNotEmpty(preg_grep('/1º campo/', $field1['errors']));
        $this->assertNotEmpty(preg_grep('/geral/', $field1['errors']));

        $field2 = $this->read('00190.50095 40144.816068 06809.350314 3 37370000000100');
        $this->assertCount(1, $field2['errors']);
        $this->assertStringContainsString('2º campo', $field2['errors'][0]);

        $amount = $this->read('00190.50095 40144.816069 06809.350314 3 37370000000101');
        $this->assertCount(1, $amount['errors']);
        $this->assertStringContainsString('geral', $amount['errors'][0]);

        $this->assertFalse($this->read('00190.50095 40144.816069 06809.350314 4 37370000000100')['ok']);
        $this->assertFalse($this->read('00193373700000001000500940144896060680935031')['ok']);
    }

    public function test_arrecadacao_vetores_febraban_v8(): void
    {
        $this->assertSame(3, BoletoCode::mod10('01230067896'));
        $this->assertSame(0, BoletoCode::mod11Convenio('01230067896'));

        $power = $this->read('836200000005 667800481000 180975657313 001589636081');
        $this->assertTrue($power['ok']);
        $this->assertSame('convenio', $power['type']);
        $this->assertSame(6678, $power['amount_cents']);
        $this->assertSame('83620000000667800481001809756573100158963608', $power['barcode']);

        $gov = $this->read('85890000460-9 52460179160-5 60759305086-5 83148300001-0');
        $this->assertTrue($gov['ok']);
        $this->assertSame(4605246, $gov['amount_cents']);
        $this->assertSame(1230616, $this->read('858000001239061603852620610716262474385997310225')['amount_cents']);

        $this->assertFalse($this->read('836200000005667800481000180975657313001589636082')['ok']);    // DV do bloco 4 alterado
        $this->assertFalse($this->read('836200000005677800481000180975657313001589636081')['ok']);    // dado do bloco 2 alterado
        $this->assertFalse($this->read('536200000005667800481000180975657313001589636081')['ok']);    // 48 dígitos que não começam com 8
    }

    public function test_linha_montada_pelos_testes_e_lida_de_volta(): void
    {
        $line = BoletoCode::buildLine('756', 123456, '2026-10-30', 7919);
        $r = $this->read($line);

        $this->assertTrue($r['ok'], implode(' ', $r['errors']));
        $this->assertSame(123456, $r['amount_cents']);
        $this->assertSame('2026-10-30', $r['due_date']);
        $this->assertSame('756', $r['bank']);
        $this->assertSame('Sicoob', $r['bank_name']);
        $this->assertSame('Sicredi', $this->read(BoletoCode::buildLine('748', 8850, '2026-11-10'))['bank_name']);
    }

    public function test_chave_do_titulo_ignora_valor_e_vencimento(): void
    {
        $real = BoletoCode::lineToBarcode(BoletoCode::buildLine('341', 30000, '2026-10-25', 1234567));
        $tampered = BoletoCode::lineToBarcode(BoletoCode::buildLine('341', 300000, '2026-10-25', 1234567));

        $this->assertNotSame($real, $tampered);
        $this->assertSame(BoletoCode::titleKey($real), BoletoCode::titleKey($tampered));
        $this->assertNull(BoletoCode::titleKey(null));
    }
}
