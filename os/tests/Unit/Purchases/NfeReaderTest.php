<?php

namespace Tests\Unit\Purchases;

use App\Support\Purchases\NfeReader;
use DomainException;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\BuildsPurchases;

/**
 * Porte de `app/test/nfe.test.js`, `nfe_pesquisa.test.js` (XML do fixture conferido contra o XSD oficial, dados
 * fictícios) e da parte de XML de `revisao_compras.test.js`.
 */
class NfeReaderTest extends TestCase
{
    use BuildsPurchases;

    private const ITEMS = [
        ['code' => 'P1', 'name' => 'PASTILHA DE FREIO DIANTEIRA', 'qty' => 1, 'total' => 120.0, 'order' => '1148'],
        ['code' => 'D2', 'name' => 'DISCO DE FREIO', 'qty' => 2, 'total' => 180.0],
    ];

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__.'/../../fixtures/purchases/nfe_pesquisa.xml');
    }

    private function assertRefused(string $xml, string $pattern): void
    {
        try {
            NfeReader::read($xml);
            $this->fail('O XML devia ser recusado: '.$pattern);
        } catch (DomainException $e) {
            $this->assertMatchesRegularExpression($pattern, $e->getMessage());
        }
    }

    public function test_le_itens_parcelas_emitente_destinatario_e_protocolo(): void
    {
        $r = NfeReader::read($this->nfeXml(['number' => 4321, 'items' => self::ITEMS, 'installments' => [['due' => '2026-10-31', 'amount' => 150], ['due' => '2026-11-30', 'amount' => 150]], 'info' => 'PLACA ABC1D23']));

        $this->assertSame('nota', $r['type']);
        $this->assertSame('4321', $r['number']);
        $this->assertSame('1', $r['series']);
        $this->assertSame('2026-10-01', $r['issue_date']);
        $this->assertSame(30000, $r['total_cents']);
        $this->assertSame(self::SUPPLIER_CNPJ, $r['issuer_cnpj']);
        $this->assertSame(self::WORKSHOP_CNPJ, $r['recipient_cnpj']);
        $this->assertSame('100', $r['protocol_status']);
        $this->assertSame([['2026-10-31', 15000], ['2026-11-30', 15000]], array_map(fn ($d) => [$d['due_date'], $d['amount_cents']], $r['installments']));
        $this->assertCount(2, $r['items']);
        $this->assertSame('1148', $r['items'][0]['customer_order']);
        $this->assertSame(2.0, $r['items'][1]['quantity']);
        $this->assertSame('PLACA ABC1D23', $r['additional_info']);
        $this->assertSame([], $r['warnings']);
    }

    public function test_sem_duplicatas_e_sem_protocolo_e_lida_com_aviso_de_autenticidade(): void
    {
        $r = NfeReader::read($this->nfeXml(['protocol' => false]));

        $this->assertSame([], $r['installments']);
        $this->assertStringContainsString('protocolo', $r['warnings'][0]);
    }

    public function test_recusa_xml_adulterado_de_outro_modelo_com_doctype_vazio_ou_que_nao_e_nfe(): void
    {
        $ok = $this->nfeXml(['number' => 4321]);

        $this->assertRefused(str_replace('<nNF>4321</nNF>', '<nNF>4322</nNF>', $ok), '/não batem com a chave/');
        $this->assertRefused(str_replace('<CNPJ>'.self::SUPPLIER_CNPJ.'</CNPJ><xNome>DIST', '<CNPJ>'.self::OTHER_CNPJ.'</CNPJ><xNome>DIST', $ok), '/emitente não bate/');
        $this->assertRefused($this->nfeXml(['model' => '65']), '/modelo 55/');
        $this->assertRefused('<!DOCTYPE a [<!ENTITY x "y">]><a/>', '/declarações/');
        $this->assertRefused('', '/vazio/');
        $this->assertRefused('<a><b>1</b></a>', '/Não encontrei uma NF-e/');
        $this->assertRefused('isto não é xml <<<', '/Não encontrei|Não consegui/');
    }

    public function test_fixture_da_pesquisa_chave_emitente_itens_parcelas_e_pedido(): void
    {
        $n = NfeReader::read($this->fixture());

        $this->assertSame('nota', $n['type']);
        $this->assertSame('43261012345678000195550010000482131076543215', $n['key']);
        $this->assertSame('48213', $n['number']);
        $this->assertSame('1', $n['series']);
        $this->assertSame('12345678000195', $n['issuer_cnpj']);
        $this->assertSame('11222333000181', $n['recipient_cnpj']);
        $this->assertSame('2026-10-05', $n['issue_date']);
        $this->assertSame(27730, $n['total_cents']);
        $this->assertSame('ativa', $n['status']);
        $this->assertSame('100', $n['protocol_status']);
        $this->assertSame([['PF-0312', 2.0, 17980, 'OS1043'], ['FO-0087', 3.0, 9750, 'OS1051']], array_map(fn ($i) => [$i['code'], $i['quantity'], $i['total_cents'], $i['customer_order']], $n['items']));
        $this->assertSame([['001', '2026-11-04', 13865], ['002', '2026-12-04', 13865]], array_map(fn ($d) => [$d['number'], $d['due_date'], $d['amount_cents']], $n['installments']));
        $this->assertSame([], $n['warnings']);
    }

    public function test_protocolo_cancelada_e_denegada_entram_como_canceladas_homologacao_e_protocolo_trocado_sao_recusados(): void
    {
        $xml = $this->fixture();

        $canceled = NfeReader::read(str_replace('<cStat>100</cStat>', '<cStat>101</cStat>', $xml));
        $this->assertSame('cancelada', $canceled['status']);
        $this->assertStringContainsString('CANCELADA', implode(' ', $canceled['warnings']));

        $denied = NfeReader::read(str_replace('<cStat>100</cStat>', '<cStat>110</cStat>', $xml));
        $this->assertSame('cancelada', $denied['status']);
        $this->assertStringContainsString('DENEGADO', implode(' ', $denied['warnings']));

        $this->assertRefused(preg_replace('/<tpAmb>1<\/tpAmb>/', '<tpAmb>2</tpAmb>', $xml, 1), '/HOMOLOGA/');
        $this->assertRefused(preg_replace('/<chNFe>\d{44}<\/chNFe>/', '<chNFe>43261012345678000195550010000482131076543290</chNFe>', $xml), '/protocolo/');
    }

    public function test_tributos_por_fora_parcelas_que_nao_somam_e_nota_de_ajuste_geram_avisos(): void
    {
        $xml = $this->fixture();

        $withTaxes = NfeReader::read(str_replace('</ICMSTot>', '</ICMSTot><vNFTot>280.00</vNFTot>', $xml));
        $this->assertSame(28000, $withTaxes['total_with_taxes_cents']);
        $this->assertStringContainsString('por fora', implode(' ', $withTaxes['warnings']));
        $this->assertStringNotContainsString('parcelas da nota somam', implode(' ', $withTaxes['warnings']));

        $mismatch = NfeReader::read(preg_replace('/<vDup>138\.65<\/vDup><\/dup><dup>/', '<vDup>100.00</vDup></dup><dup>', $xml, 1));
        $this->assertStringContainsString('parcelas da nota somam', implode(' ', $mismatch['warnings']));

        $adjustment = NfeReader::read(str_replace('<finNFe>1</finNFe>', '<finNFe>3</finNFe>', $xml));
        $this->assertSame('ajuste', $adjustment['purpose']);
        $this->assertStringContainsString('AJUSTE', implode(' ', $adjustment['warnings']));
    }

    public function test_entidades_decodificadas_e_custo_por_item_com_ipi_e_icms_st(): void
    {
        $base = $this->nfeXml(['number' => 700, 'issuer_name' => 'AUTO PECAS L&amp;M LTDA', 'total' => 1200.0, 'items' => [
            ['code' => 'A', 'name' => 'BUCHA &amp; ARRUELA 3&quot;', 'qty' => 1, 'total' => 800.0, 'tax' => '<imposto><IPI><IPITrib><vIPI>40.00</vIPI></IPITrib></IPI></imposto>'],
            ['code' => 'B', 'name' => 'JUNTA', 'qty' => 1, 'total' => 300.0, 'tax' => '<imposto><IPI><IPITrib><vIPI>15.00</vIPI></IPITrib></IPI><ICMS><ICMS10><vICMSST>45.00</vICMSST></ICMS10></ICMS></imposto>'],
        ]]);
        $n = NfeReader::read($base);

        $this->assertSame('AUTO PECAS L&M LTDA', $n['issuer_name']);
        $this->assertSame('BUCHA & ARRUELA 3"', $n['items'][0]['description']);
        $this->assertSame([84000, 36000], array_column($n['items'], 'base_cost_cents'));     // 800+40 e 300+15+45
    }

    public function test_data_invalida_item_sem_numero_e_chave_contraditoria_sao_recusados(): void
    {
        $ok = $this->nfeXml(['number' => 710]);

        $this->assertRefused(preg_replace('/<dhEmi>[^<]*<\/dhEmi>/', '<dhEmi>2026-13-45T10:00:00-03:00</dhEmi>', $ok), '/data de emissão válida/');
        $this->assertRefused(str_replace('<det nItem="1">', '<det nItem="abc">', $ok), '/sem número válido/');
        $this->assertRefused(str_replace('<cUF>43</cUF>', '<cUF>31</cUF>', $ok), '/contradiz/');
        $this->assertRefused(str_replace('<mod>55</mod>', '<mod>65</mod>', $ok), '/modelo/');
    }

    public function test_mesmo_arquivo_com_bom_e_quebras_de_linha_tem_o_mesmo_hash(): void
    {
        $xml = $this->nfeXml(['number' => 720]);

        $this->assertSame(NfeReader::read($xml)['hash'], NfeReader::read("\xEF\xBB\xBF".str_replace('><', ">\r\n  <", $xml))['hash']);
    }

    public function test_pagamento_no_ato_consignacao_e_cancelamento(): void
    {
        $pix = NfeReader::read($this->nfeXml(['number' => 5200, 'payment' => '<detPag><indPag>0</indPag><tPag>17</tPag><vPag>100.00</vPag></detPag>']));
        $this->assertTrue($pix['paid_upfront']);
        $this->assertStringContainsString('pagamento imediato', implode(' ', $pix['warnings']));

        $consignment = NfeReader::read($this->nfeXml(['number' => 710, 'cfop' => '5917']));
        $this->assertSame('ajuste', $consignment['purpose']);
        $this->assertStringContainsString('CONSIGNAÇÃO', implode(' ', $consignment['warnings']));

        $key = $this->nfeKey(['number' => 88]);
        $event = NfeReader::read($this->cancellationXml($key));
        $this->assertSame(['type' => 'cancelamento', 'key' => $key], array_intersect_key($event, ['type' => 1, 'key' => 1]));
        $this->assertRefused($this->cancellationXml($key, false), '/não traz a resposta da SEFAZ/');
    }

    public function test_emitente_pessoa_fisica_cpf_ocupa_a_chave_com_zeros(): void
    {
        $cpf = '52998224725';
        $key = $this->nfeKey(['number' => 15, 'cnpj' => '000'.$cpf]);
        $xml = str_replace('<CNPJ>'.self::SUPPLIER_CNPJ.'</CNPJ>', '<CPF>'.$cpf.'</CPF>', $this->nfeXml(['number' => 15, 'key' => $key]));

        $this->assertSame($cpf, NfeReader::read($xml)['issuer_cnpj']);
    }
}
