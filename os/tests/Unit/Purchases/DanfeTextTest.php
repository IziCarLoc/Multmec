<?php

namespace Tests\Unit\Purchases;

use App\Support\Document;
use App\Support\Purchases\DanfeText;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\BuildsPurchases;

/** Porte da parte de leitura de DANFE de `app/test/nota_sem_xml.test.js`. */
class DanfeTextTest extends TestCase
{
    use BuildsPurchases;

    private function danfe(string $key): string
    {
        return "DANFE\nDocumento Auxiliar da Nota Fiscal Eletrônica\nNº 000.004.321\nSÉRIE 1\nCHAVE DE ACESSO\n".trim(chunk_split($key, 4, ' '))."\n"
            ."Consulta de autenticidade no portal nacional da NF-e www.nfe.fazenda.gov.br/portal\nVENDA DE MERCADORIA 143260000000001 - 01/10/2026 09:12:30\n"
            ."EMITENTE\nDISTRIBUIDORA TESTE LTDA 11.222.333/0001-81\nDESTINATÁRIO / REMETENTE\nNOME / RAZÃO SOCIAL CNPJ / CPF DATA DA EMISSÃO\n"
            .'OFICINA TESTE LTDA '.Document::format(self::WORKSHOP_CNPJ)." 01/10/2026\nCÁLCULO DO IMPOSTO\nBASE DE CÁLC. DO ICMS VALOR DO ICMS V. TOTAL PRODUTOS\n0,00 0,00 1.000,00\n"
            ."VALOR DO FRETE VALOR DO SEGURO DESCONTO OUTRAS DESP. VALOR TOTAL DO IPI VALOR TOTAL DA NOTA\n50,00 0,00 0,00 0,00 0,00 1.050,00\nTRANSPORTADOR / VOLUMES TRANSPORTADOS\n";
    }

    public function test_chave_em_blocos_ou_solta_numero_serie_emitente_destinatario_data_e_valor(): void
    {
        $key = $this->nfeKey(['number' => 4321]);
        $r = DanfeText::read($this->danfe($key));

        $this->assertTrue($r['key']['valid']);
        $this->assertSame($key, $r['key']['key']);
        $this->assertSame('4321', $r['key']['number']);
        $this->assertSame('1', $r['key']['series']);
        $this->assertSame(self::SUPPLIER_CNPJ, $r['key']['issuer_cnpj']);
        $this->assertSame(self::WORKSHOP_CNPJ, $r['recipient_cnpj']);
        $this->assertSame('2026-10-01', $r['issue_date']);
        $this->assertSame(105000, $r['total_cents']);
        $this->assertSame([105000, 100000, 5000, 0], $r['possible_totals']);
        // Só uma fonte para o total (o maior número do bloco): pede para conferir; com o canhoto confirmando, não pede.
        $this->assertFalse($r['total_confirmed']);
        $this->assertSame(['Confira o "VALOR TOT'], array_map(fn ($w) => mb_substr($w, 0, 20), $r['warnings']));

        $withStub = DanfeText::read("RECEBEMOS DE DISTRIBUIDORA TESTE OS PRODUTOS. EMISSÃO: 01/10/2026 VALOR TOTAL: R$ 1.050,00\n".$this->danfe($key));
        $this->assertTrue($withStub['total_confirmed']);
        $this->assertSame([], $withStub['warnings']);

        // Destinatário pessoa física: não pega o CNPJ do transportador como se fosse de quem comprou.
        $person = DanfeText::read(str_replace(
            ['OFICINA TESTE LTDA '.Document::format(self::WORKSHOP_CNPJ), 'TRANSPORTADOR / VOLUMES TRANSPORTADOS'],
            ['JOAO DA SILVA 123.456.789-09', "TRANSPORTADOR / VOLUMES TRANSPORTADOS\nTRANSPORTES X ".Document::format(self::OTHER_CNPJ)],
            $this->danfe($key),
        ));
        $this->assertNull($person['recipient_cnpj']);
        $this->assertNotEmpty(preg_grep('/pessoa física/', $person['warnings']));

        // Chave solta, com pontos ou traços, no meio de uma frase.
        $this->assertSame($key, DanfeText::findKey("chave: {$key}.")['key']);
        $this->assertSame($key, DanfeText::findKey(implode('-', str_split($key, 4)))['key']);
    }

    public function test_nao_inventa_nada_sem_chave_com_chave_adulterada_ou_data_fora_do_mes(): void
    {
        $nothing = DanfeText::read('nota qualquer 1.234,56 sem nada que pareça chave');
        $this->assertNull($nothing['key']);
        $this->assertNotEmpty(preg_grep('/chave de acesso/', $nothing['warnings']));

        $key = $this->nfeKey(['number' => 4321]);
        $wrong = substr($key, 0, 43).(((int) $key[43] + 1) % 10);
        $r = DanfeText::read($wrong);
        $this->assertFalse($r['key']['valid']);
        $this->assertStringContainsString('dígito verificador', $r['warnings'][0]);

        $otherDate = DanfeText::read(str_replace('01/10/2026', '01/09/2026', $this->danfe($key)));
        $this->assertNull($otherDate['issue_date']);
        $this->assertNotEmpty(preg_grep('/data de emissão/', $otherDate['warnings']));

        $this->assertNotEmpty(preg_grep('/modelo 65/', DanfeText::read($this->nfeKey(['number' => 9, 'model' => '65']))['warnings']));
    }
}
