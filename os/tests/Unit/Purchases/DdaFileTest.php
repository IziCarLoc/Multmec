<?php

namespace Tests\Unit\Purchases;

use App\Support\Document;
use App\Support\Purchases\DdaFile;
use DomainException;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\BuildsPurchases;

/** Porte da parte de leitura de `app/test/dda.test.js` e `revisao_rodada2.test.js` (CNAB 240, CSV e texto). */
class DdaFileTest extends TestCase
{
    use BuildsPurchases;

    private const TODAY = '2026-10-08';

    public function test_cnab_240_le_codigo_quem_recebe_valor_vencimento_documento_e_quem_paga_e_ignora_baixa(): void
    {
        $l1 = $this->slipLine(1234.56, '2026-10-30');
        $l2 = $this->slipLine(80, '2026-11-05');
        $l3 = $this->slipLine(99.9, '2026-10-20');
        $file = $this->cnabDda(self::WORKSHOP_CNPJ, [
            ['barcode' => $this->barcodeOf($l1), 'payee' => self::SUPPLIER_CNPJ, 'payee_name' => self::SUPPLIER_NAME, 'due' => '30102026', 'amount' => 1234.56, 'document' => '004321/01'],
            ['barcode' => $this->barcodeOf($l2), 'payee' => self::OTHER_CNPJ, 'payee_name' => 'OUTRA PECAS SA', 'due' => '05112026', 'amount' => 80, 'drawer' => self::SUPPLIER_CNPJ, 'drawer_name' => self::SUPPLIER_NAME],
            ['barcode' => $this->barcodeOf($l3), 'payee' => self::OTHER_CNPJ, 'payee_name' => 'BAIXADO', 'due' => '20102026', 'amount' => 99.9, 'movement' => '02'],
        ]);

        $r = DdaFile::read($file, self::TODAY);

        $this->assertSame('cnab240', $r['format']);
        $this->assertSame(self::WORKSHOP_CNPJ, $r['file_cnpj']);
        $this->assertSame('2026-10-08', $r['generated_on']);
        $this->assertCount(2, $r['titles']);
        [$a, $b] = $r['titles'];
        $this->assertSame($this->barcodeOf($l1), $a['barcode']);
        $this->assertSame(self::SUPPLIER_CNPJ, $a['payee_cnpj']);
        $this->assertSame(self::SUPPLIER_NAME, $a['payee_name']);
        $this->assertSame(123456, $a['amount_cents']);
        $this->assertSame('2026-10-30', $a['due_date']);
        $this->assertSame('004321/01', $a['document_number']);
        $this->assertSame(self::WORKSHOP_CNPJ, $a['payer_cnpj']);
        $this->assertSame('341', $a['bank']);
        $this->assertSame(self::SUPPLIER_CNPJ, $b['drawer_cnpj']);
        $this->assertSame(self::OTHER_CNPJ, $b['payee_cnpj']);
    }

    public function test_cnab_com_codigo_adulterado_ou_valor_diferente_do_codigo_avisa_e_usa_o_do_codigo(): void
    {
        $barcode = $this->barcodeOf($this->slipLine(500, '2026-10-30'));
        $bad = substr($barcode, 0, 20).($barcode[20] === '9' ? '8' : '9').substr($barcode, 21);
        $r = DdaFile::read($this->cnabDda(self::WORKSHOP_CNPJ, [
            ['barcode' => $bad, 'payee' => self::SUPPLIER_CNPJ, 'due' => '30102026', 'amount' => 500],
            ['barcode' => $barcode, 'payee' => self::SUPPLIER_CNPJ, 'due' => '30102026', 'amount' => 450],
        ]), self::TODAY);

        $this->assertNotEmpty(preg_grep('/não passou na validação/', $r['warnings']));
        $this->assertNotEmpty(preg_grep('/difere do valor do código de barras/', $r['warnings']));
        $withCode = array_values(array_filter($r['titles'], fn ($t) => $t['barcode'] === $barcode));
        $this->assertSame(50000, $withCode[0]['amount_cents']);
    }

    public function test_csv_acha_as_colunas_pelo_nome_e_a_linha_digitavel_em_qualquer_celula(): void
    {
        $l1 = $this->slipLine(300, '2026-10-25');
        $csv = "Beneficiário;CNPJ do beneficiário;Data de vencimento;Valor (R$);Linha digitável\n\"DISTRIBUIDORA TESTE LTDA\";11.222.333/0001-81;25/10/2026;\"300,00\";{$l1}\n"
            .'"SEM CODIGO LTDA";'.Document::format(self::OTHER_CNPJ).";12/11/2026;\"1.250,50\";\nTotal;;;1.550,50;\n";

        $r = DdaFile::read($csv, self::TODAY);

        $this->assertSame('csv', $r['format']);
        $this->assertCount(2, $r['titles']);
        $this->assertSame($this->barcodeOf($l1), $r['titles'][0]['barcode']);
        $this->assertSame(self::SUPPLIER_CNPJ, $r['titles'][0]['payee_cnpj']);
        $this->assertSame(self::SUPPLIER_NAME, $r['titles'][0]['payee_name']);
        $this->assertNull($r['titles'][1]['barcode']);
        $this->assertSame(125050, $r['titles'][1]['amount_cents']);
        $this->assertSame('2026-11-12', $r['titles'][1]['due_date']);
        $this->assertSame(self::OTHER_CNPJ, $r['titles'][1]['payee_cnpj']);
    }

    public function test_texto_colado_acha_as_linhas_e_o_cnpj_perto_de_cada_uma_repetidas_valem_uma_vez(): void
    {
        $l1 = $this->slipLine(300, '2026-10-25');
        $l2 = $this->slipLine(75.5, '2026-11-02');
        $text = "Boletos DDA\nDISTRIBUIDORA TESTE LTDA 11.222.333/0001-81\n{$l1}\nvence 25/10/2026\n\nOUTRA PECAS SA ".Document::format(self::OTHER_CNPJ)."\n{$l2}\n{$l1}\n";

        $r = DdaFile::read($text, self::TODAY);

        $this->assertSame('texto', $r['format']);
        $this->assertCount(2, $r['titles']);
        $this->assertSame(self::SUPPLIER_CNPJ, $r['titles'][0]['payee_cnpj']);
        $this->assertSame(self::OTHER_CNPJ, $r['titles'][1]['payee_cnpj']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('vazio');
        DdaFile::read("   \n  ", self::TODAY);
    }

    public function test_texto_sem_boleto_nao_traz_titulos(): void
    {
        $r = DdaFile::read('nada de boleto aqui', self::TODAY);

        $this->assertSame([], $r['titles']);
        $this->assertStringContainsString('Não encontrei nenhum código', $r['warnings'][0]);
    }

    public function test_um_lote_por_cnpj_cada_titulo_vai_para_o_dono_do_lote(): void
    {
        $file = $this->cnabDda(self::WORKSHOP_CNPJ, lots: [
            ['cnpj' => self::WORKSHOP_CNPJ, 'titles' => [['barcode' => $this->barcodeOf($this->slipLine(100, '2026-10-30')), 'payee' => self::SUPPLIER_CNPJ, 'due' => '30102026', 'amount' => 100]]],
            ['cnpj' => self::RENTAL_CNPJ, 'titles' => [['barcode' => $this->barcodeOf($this->slipLine(200, '2026-10-31')), 'payee' => self::SUPPLIER_CNPJ, 'due' => '31102026', 'amount' => 200]]],
        ]);

        $this->assertSame([self::WORKSHOP_CNPJ, self::RENTAL_CNPJ], array_column(DdaFile::read($file, self::TODAY)['titles'], 'lot_cnpj'));
    }

    public function test_vencimento_a_vista_ou_contra_apresentacao_nao_vira_data_inventada(): void
    {
        $r = DdaFile::read($this->cnabDda(self::WORKSHOP_CNPJ, [
            ['barcode' => $this->barcodeOf($this->slipLine(100, '2026-10-30')), 'payee' => self::SUPPLIER_CNPJ, 'due' => '11111111', 'amount' => 100],
            ['barcode' => $this->barcodeOf($this->slipLine(50, '2026-11-10')), 'payee' => self::SUPPLIER_CNPJ, 'due' => '99999999', 'amount' => 50],
        ]), self::TODAY);

        $this->assertSame(['2026-10-30', '2026-11-10'], array_column($r['titles'], 'due_date'));      // vale o vencimento do código de barras
    }

    public function test_baixa_depois_da_entrada_remove_o_titulo_no_proprio_arquivo(): void
    {
        $barcode = $this->barcodeOf($this->slipLine(300, '2026-11-01'));
        $title = ['barcode' => $barcode, 'payee' => self::SUPPLIER_CNPJ, 'due' => '01112026', 'amount' => 300];
        $r = DdaFile::read($this->cnabDda(self::WORKSHOP_CNPJ, [
            ['barcode' => $this->barcodeOf($this->slipLine(100, '2026-10-30')), 'payee' => self::SUPPLIER_CNPJ, 'due' => '30102026', 'amount' => 100],
            $title, [...$title, 'movement' => '02'],
        ]), self::TODAY);

        $this->assertCount(1, $r['titles']);
    }

    public function test_csv_ponto_de_milhar_cpf_na_coluna_de_cnpj_e_data_inexistente(): void
    {
        $line = $this->slipLine(1250, '2026-10-30');
        $csv = "Beneficiário;CNPJ;Sacador;Vencimento;Valor\nJOAO PESSOA FISICA;123.456.789-09;".Document::format(self::OTHER_CNPJ).";30/10/2026;1.250\n"
            ."OUTRO;11.222.333/0001-81;;31/02/2026;100,00\n{$line};;;;\n";

        $r = DdaFile::read($csv, self::TODAY);
        $person = array_values(array_filter($r['titles'], fn ($t) => $t['amount_cents'] === 125000));

        $this->assertNotEmpty($person);
        $this->assertNull($person[0]['payee_cnpj']);              // CPF na coluna de CNPJ: nada de pegar o CNPJ do sacador
        $this->assertEmpty(array_filter($r['titles'], fn ($t) => $t['due_date'] === '2026-02-31'));
    }
}
