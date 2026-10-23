<?php

namespace Tests\Unit\Purchases;

use App\Support\Document;
use App\Support\Purchases\AccessKey;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\BuildsPurchases;

/**
 * Porte de `app/test/documentos.test.js` e da parte de chave de `nfe_pesquisa.test.js` (exemplos do MOC e da NT
 * Conjunta 2025.001 do CNPJ alfanumérico).
 */
class AccessKeyTest extends TestCase
{
    use BuildsPurchases;

    public function test_cnpj_numerico_e_alfanumerico_pelo_digito_verificador(): void
    {
        $this->assertTrue(Document::isValidCnpj('11.222.333/0001-81'));
        $this->assertFalse(Document::isValidCnpj('11222333000182'));
        $this->assertFalse(Document::isValidCnpj('00000000000000'));
        $this->assertTrue(Document::isValidCnpj('00.000.000/E08G-12'));       // primeiro CNPJ alfanumérico divulgado pela Receita
        $this->assertTrue(Document::isValidCnpj('12ABC34501DE35'));           // exemplo da nota técnica conjunta 2025.001
        $this->assertFalse(Document::isValidCnpj('12ABC34501DE36'));
        $this->assertFalse(Document::isValidCnpj('abc'));
        $this->assertTrue(Document::isValidCnpj('33009911002506'));
        $this->assertTrue(Document::isValidCnpj('78393592000146'));
        $this->assertSame('11222333000181', Document::normalizeCnpj('11.222.333/0001-81'));
        $this->assertSame('11.222.333/0001-81', Document::format('11222333000181'));
    }

    public function test_cnpj_alfanumerico_no_cadastro_sem_quebrar_cpf_e_cnpj_numerico(): void
    {
        $this->assertSame('12ABC34501DE35', Document::clean('12.abc.345/01de-35'));
        $this->assertSame('12.ABC.345/01DE-35', Document::format('12ABC34501DE35'));
        $this->assertTrue(Document::isValid('12.ABC.345/01DE-35'));
        $this->assertSame('CNPJ', Document::typeLabel('12ABC34501DE35'));
        // o que já existia continua igual
        $this->assertSame('11222333000181', Document::clean('11.222.333/0001-81'));
        $this->assertSame('52998224725', Document::clean('529.982.247-25'));
        $this->assertSame('529.982.247-25', Document::format('52998224725'));
        $this->assertTrue(Document::isValid('529.982.247-25'));
        $this->assertSame('CPF', Document::typeLabel('52998224725'));
        $this->assertSame('11222333', Document::cnpjRoot('11.222.333/0002-62'));
    }

    public function test_chave_de_acesso_composicao_digito_e_adulteracao(): void
    {
        $key = $this->nfeKey(['number' => 4321, 'series' => 2]);
        $r = AccessKey::read($key);

        $this->assertTrue($r['valid']);
        $this->assertSame(self::SUPPLIER_CNPJ, $r['issuer_cnpj']);
        $this->assertSame('4321', $r['number']);
        $this->assertSame('2', $r['series']);
        $this->assertSame('55', $r['model']);
        $this->assertSame('2610', $r['yymm']);

        $tampered = $key;
        $tampered[30] = (string) (((int) $key[30] + 1) % 10);
        $this->assertFalse(AccessKey::read($tampered)['valid']);
        $this->assertFalse(AccessKey::read(substr($key, 1))['valid']);                        // 43 dígitos
        $this->assertTrue(AccessKey::read(substr($key, 0, 4).' '.substr($key, 4))['valid']);    // aceita espaços
    }

    public function test_dv_da_chave_exemplos_do_moc_bordas_e_chaves_que_nao_fecham(): void
    {
        $this->assertSame(5, AccessKey::checkDigit('5206043300991100250655012000000780026730161'));
        $this->assertSame(7, AccessKey::checkDigit('4118067839359200014655890000000604102819069'));
        $this->assertSame(8, AccessKey::checkDigit('3120101058820100010555001003842117183842217'));
        $this->assertSame(0, AccessKey::checkDigit('4326101234567800019555001000048213107654329'));      // resto 0
        $this->assertSame(0, AccessKey::checkDigit('4326101234567800019555001000048213107654324'));      // resto 1
        $this->assertSame(9, AccessKey::checkDigit('4326101234567800019555001000048213107654333'));      // resto 2
        $this->assertSame(1, AccessKey::checkDigit('4326101234567800019555001000048213107654323'));      // resto 10
        $this->assertFalse(AccessKey::read('43261012345678000195550010000482131076543214')['valid']);

        foreach (['35220499999999999999550010020000001240556603', '43260812345678000199550010000123451123456789', '35200501442791000129550010000059172174173681'] as $bad) {
            $this->assertFalse(AccessKey::read($bad)['valid'], $bad);
        }
    }

    public function test_chave_com_cnpj_alfanumerico_e_aceita(): void
    {
        $key = AccessKey::read('43261012ABC34501DE35550010000482131076543215');

        $this->assertTrue($key['valid']);
        $this->assertSame('12ABC34501DE35', $key['issuer_cnpj']);
        $this->assertStringContainsString('44 caracteres', AccessKey::read('123')['reason']);
    }
}
