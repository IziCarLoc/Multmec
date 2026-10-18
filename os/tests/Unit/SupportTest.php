<?php

namespace Tests\Unit;

use App\Support\Document;
use App\Support\LicensePlate;
use App\Support\Money;
use PHPUnit\Framework\TestCase;

class SupportTest extends TestCase
{
    public function test_dinheiro_em_centavos_vira_real_e_volta(): void
    {
        $this->assertSame('R$ 1.234,56', Money::brl(123456));
        $this->assertSame('-R$ 28,00', Money::brl(-2800));
        $this->assertSame(10000, Money::parseBr('100'));
        $this->assertSame(10050, Money::parseBr('100,5'));
        $this->assertSame(123456, Money::parseBr('R$ 1.234,56'));
        $this->assertSame(123400, Money::parseBr('1.234'));
        $this->assertSame(1250, Money::parseBr('12.5'));
        $this->assertNull(Money::parseBr(''));
    }

    public function test_cpf_e_cnpj_conferem_os_digitos(): void
    {
        $this->assertTrue(Document::isValidCnpj('63.796.929/0001-99'), 'CNPJ da Multmec Liberdade');
        $this->assertFalse(Document::isValidCnpj('11.111.111/1111-11'));
        $this->assertTrue(Document::isValidCpf('529.982.247-25'));
        $this->assertFalse(Document::isValidCpf('529.982.247-24'));
        $this->assertSame('63.796.929/0001-99', Document::format('63796929000199'));
    }

    public function test_placa_antiga_e_mercosul_sao_a_mesma(): void
    {
        $this->assertSame('ABC1234', LicensePlate::clean('abc-1234'));
        $this->assertSame('ABC1C34', LicensePlate::normalize('ABC-1234'));
        $this->assertTrue(LicensePlate::same('abc1234', 'ABC1C34'));
    }
}
