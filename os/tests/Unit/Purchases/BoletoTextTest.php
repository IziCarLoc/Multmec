<?php

namespace Tests\Unit\Purchases;

use App\Support\Purchases\BoletoCode;
use App\Support\Purchases\BoletoText;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\BuildsPurchases;

/** Porte de `app/test/boleto_texto.test.js`. */
class BoletoTextTest extends TestCase
{
    use BuildsPurchases;

    private const TODAY = '2026-10-08';

    private const SUPPLIER_MASKED = '11.222.333/0001-81';

    public function test_texto_de_boleto_no_layout_comum_acha_linha_beneficiario_pagador_e_documento(): void
    {
        $line = $this->slipLine(1234.56, '2026-10-30');
        $text = "Local de pagamento: Pagável em qualquer banco\nBeneficiário\nDISTRIBUIDORA TESTE LTDA - CNPJ ".self::SUPPLIER_MASKED."\n"
            ."Agência/Código do beneficiário 0001 / 12345-6\nNº do documento: 004321/01\nVencimento 30/10/2026\nValor do documento 1.234,56\n"
            ."Pagador\nOFICINA TESTE LTDA CPF/CNPJ ".self::WORKSHOP_CNPJ."\nSacador/Avalista\n".BoletoCode::formatLine($line);

        $r = BoletoText::read($text, self::TODAY);

        $this->assertTrue($r['slip']['ok']);
        $this->assertSame(123456, $r['slip']['amount_cents']);
        $this->assertSame('2026-10-30', $r['slip']['due_date']);
        $this->assertSame(self::SUPPLIER_CNPJ, $r['payee_cnpj']);
        $this->assertSame(self::WORKSHOP_CNPJ, $r['payer_cnpj']);
        $this->assertSame('004321/01', $r['document_number']);
        $this->assertSame([], $r['warnings']);
    }

    public function test_numero_solto_antes_da_linha_e_recibo_repetido_nao_confundem(): void
    {
        $formatted = BoletoCode::formatLine($this->slipLine(80, '2026-11-05'));
        $r = BoletoText::findLine("Agência 0001 123456 {$formatted}\nRecibo do pagador\n{$formatted}", self::TODAY);

        $this->assertTrue($r['ok']);
        $this->assertSame(8000, $r['amount_cents']);
    }

    public function test_texto_sem_linha_valida_nao_inventa_boleto(): void
    {
        $r = BoletoText::read('Boleto 12345 67890 11111 22222 33333 44444 5 66666666666666 valor 10,00', self::TODAY);

        $this->assertNull($r['slip']);
        $this->assertStringContainsString('linha digitável válida', $r['warnings'][0]);
    }

    public function test_cnpj_sem_rotulo_nao_vira_beneficiario_e_cnpj_invalido_e_ignorado(): void
    {
        $text = 'Empresa '.self::SUPPLIER_MASKED."\nOutro 11.222.333/0001-80\nPagador ".self::WORKSHOP_CNPJ;

        $this->assertCount(2, BoletoText::cnpjs($text));          // o inválido (DV errado) foi descartado

        $r = BoletoText::read($text, self::TODAY);
        $this->assertNull($r['payee_cnpj']);
        $this->assertSame(self::WORKSHOP_CNPJ, $r['payer_cnpj']);
        $this->assertNotEmpty(preg_grep('/beneficiário/', $r['warnings']));
    }

    public function test_dois_beneficiarios_diferentes_nao_escolhe_nenhum(): void
    {
        $r = BoletoText::read('Beneficiário A '.self::SUPPLIER_MASKED."\nBeneficiário B ".self::WORKSHOP_CNPJ, self::TODAY);

        $this->assertNull($r['payee_cnpj']);
        $this->assertNotEmpty(preg_grep('/mais de um CNPJ/', $r['warnings']));
    }

    public function test_cnpj_do_avalista_nao_e_beneficiario_nem_pagador(): void
    {
        $r = BoletoText::read('Pagador '.self::WORKSHOP_CNPJ."\nSacador/Avalista BANCO X ".self::SUPPLIER_MASKED, self::TODAY);

        $this->assertNull($r['payee_cnpj']);
        $this->assertSame(self::WORKSHOP_CNPJ, $r['payer_cnpj']);
    }

    public function test_numero_do_documento_ignora_data_e_valor_sem_digito(): void
    {
        $this->assertNull(BoletoText::read('Nº do documento 12/09/2026', self::TODAY)['document_number']);
        $this->assertSame('NF-8890', BoletoText::read('Número do documento: NF-8890', self::TODAY)['document_number']);
    }

    public function test_cnpj_alfanumerico_com_mascara_e_reconhecido_no_texto(): void
    {
        $r = BoletoText::read('Beneficiário PECAS NOVAS 12.ABC.345/01DE-35', self::TODAY);

        $this->assertSame('12ABC34501DE35', $r['payee_cnpj']);
    }
}
