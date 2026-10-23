<?php

namespace Tests\Feature\Purchases;

use App\Models\BankSlip;
use App\Models\Payable;
use App\Services\Finance\Payables;
use App\Services\Purchases\BankSlips;
use App\Services\Purchases\Occurrences;
use App\Services\Purchases\PaymentLock;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFinance;
use Tests\Concerns\BuildsPurchases;
use Tests\TestCase;

/**
 * A trava de pagamento no "A pagar" (Payables::markPaid e update para pago) para o boleto que passou pela conferência
 * de compras: só o gestor, sem ocorrência grave aberta e com o recebedor conferido no app do banco (ou no DDA).
 */
class PaymentLockTest extends TestCase
{
    use BuildsFinance;
    use BuildsPurchases;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00'));
        $this->purchaseScene();
    }

    private function payables(): Payables
    {
        return app(Payables::class);
    }

    public function test_boleto_sem_nota_trava_o_pagar_do_a_pagar_ate_o_aceite_e_a_conferencia_no_banco(): void
    {
        $slip = $this->registerSlip(515.9, '2026-10-12', [], $this->desk())['slip'];
        $payable = $slip->payable;

        $this->assertThrows(fn () => $this->payables()->markPaid($payable, '2026-10-08', $this->desk()), DomainException::class, 'só o gestor');
        $this->assertThrows(fn () => $this->payables()->markPaid($payable, '2026-10-08', $this->gestor()), DomainException::class, 'Pagamento travado pela conferência de compras: Boleto sem nota fiscal');

        $key = collect(app(Occurrences::class)->forSlip($slip))->firstWhere('type', 'boleto_sem_nota')['key'];
        app(Occurrences::class)->accept($key, 'Nota chegou por e-mail, lançar amanhã', $this->gestor());
        $this->assertThrows(fn () => $this->payables()->markPaid($payable->fresh(), '2026-10-08', $this->gestor()), DomainException::class, 'confira no app do banco');

        $slip->update(['bank_checked_on' => '2026-10-08']);
        $paid = $this->payables()->markPaid($payable->fresh(), '2026-10-08', $this->gestor());

        $this->assertTrue($paid->isPaid());
        $this->assertSame(BankSlip::PAID, $slip->fresh()->status);           // o boleto da conferência fica pago junto
        $this->assertSame('2026-10-08', $slip->fresh()->paid_on->toDateString());
    }

    public function test_editar_para_pago_tem_a_mesma_trava_e_valor_vencimento_e_fornecedor_nao_mudam(): void
    {
        $this->invoiceOf(500, 320);
        $slip = $this->registerSlip(320, '2026-10-20')['slip'];
        $payable = $slip->payable;
        $data = ['supplier_id' => $payable->supplier_id, 'amount_cents' => 32000, 'due_date' => '2026-10-20', 'description' => 'Peças (nota a confirmar)'];

        $this->assertThrows(fn () => $this->payables()->update($payable, [...$data, 'amount_cents' => '10'], $this->gestor()), DomainException::class, 'vêm da conferência de compras');
        $this->assertThrows(fn () => $this->payables()->update($payable, [...$data, 'due_date' => '2026-12-31'], $this->gestor()), DomainException::class, 'vêm da conferência de compras');
        $this->assertSame('Peças (nota a confirmar)', $this->payables()->update($payable, $data, $this->desk())->description);       // a descrição muda

        $this->assertThrows(fn () => $this->payables()->update($payable->fresh(), [...$data, 'status' => 'pago', 'paid_on' => '2026-10-08'], $this->gestor()), DomainException::class, 'confira no app do banco');
        $slip->update(['bank_checked_on' => '2026-10-08']);
        $this->payables()->update($payable->fresh(), [...$data, 'status' => 'pago', 'paid_on' => '2026-10-08'], $this->gestor());
        $this->assertTrue($slip->fresh()->isPaid());

        // Voltar para "a pagar" é do gestor, e o boleto volta junto.
        $this->assertThrows(fn () => $this->payables()->update($payable->fresh(), [...$data, 'status' => 'aberto'], $this->desk()), DomainException::class, 'só o gestor desfaz');
        $this->payables()->update($payable->fresh(), [...$data, 'status' => 'aberto'], $this->gestor());
        $this->assertSame(BankSlip::OPEN, $slip->fresh()->status);
    }

    public function test_conta_do_boleto_so_sai_cancelando_o_boleto_e_conta_comum_nao_muda(): void
    {
        $slip = $this->registerSlip(321.5, '2026-10-20')['slip'];
        $this->assertThrows(fn () => $this->payables()->delete($slip->payable, $this->gestor()), DomainException::class, 'cancele-o em Financeiro > Compras');

        app(BankSlips::class)->cancel($slip, 'cancelado', null, $this->gestor());
        $this->assertSame(0, Payable::count());

        // Conta lançada direto no A pagar (sem boleto da conferência): o atendimento continua marcando como pago.
        $plain = $this->payable(['amount_cents' => 5000]);
        $this->assertTrue($this->payables()->markPaid($plain, '2026-10-08', $this->desk())->isPaid());
    }

    public function test_pagamento_pela_conferencia_marca_a_conta_paga_e_o_selo_aparece_no_a_pagar(): void
    {
        $this->invoiceOf(500, 320);
        $ok = $this->registerSlip(320, '2026-10-20')['slip'];
        $bad = $this->registerSlip(99, '2026-10-21')['slip'];
        $verdicts = app(PaymentLock::class)->verdicts(Payable::all());

        $this->assertSame('Conferido', $verdicts[$ok->payable_id]['short']);
        $this->assertSame('NÃO PAGUE', $verdicts[$bad->payable_id]['short']);

        app(BankSlips::class)->pay($ok, ['bank_checked' => true], $this->gestor());
        $this->assertTrue($ok->payable->fresh()->isPaid());
        app(BankSlips::class)->undoPayment($ok->fresh(), $this->gestor());
        $this->assertFalse($ok->payable->fresh()->isPaid());
    }
}
