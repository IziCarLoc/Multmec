<?php

namespace Tests\Feature\Purchases;

use App\Models\BankSlip;
use App\Models\Payable;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseLink;
use App\Models\Supplier;
use App\Services\Purchases\BankSlips;
use App\Services\Purchases\Invoices;
use App\Services\Purchases\Occurrences;
use App\Services\Purchases\PaymentBlocked;
use App\Support\Purchases\BoletoCode;
use App\Support\Purchases\BoletoText;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPurchases;
use Tests\TestCase;

/**
 * Porte de `app/test/compras.test.js` e `revisao_compras.test.js` (boletos: ligação com a nota, duplicidade,
 * pagamento com trava, cancelar/contestar/reabrir, conta adotada no "A pagar").
 */
class BankSlipsTest extends TestCase
{
    use BuildsPurchases;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00'));
        $this->purchaseScene();
    }

    private function slips(): BankSlips
    {
        return app(BankSlips::class);
    }

    private function pay(BankSlip $slip, array $data = []): array
    {
        return $this->slips()->pay($slip->fresh(), ['bank_checked' => true, ...$data], $this->gestor());
    }

    public function test_boleto_com_o_mesmo_valor_e_vencimento_de_uma_parcela_e_ligado_sozinho(): void
    {
        $invoice = $this->invoiceOf(500, 320);
        $r = $this->registerSlip(320, '2026-10-20');

        $this->assertTrue($r['auto']['linked']);
        $this->assertSame(100, $r['auto']['suggestion']['score']);
        $link = PurchaseLink::query()->where('bank_slip_id', $r['slip']->id)->first();
        $this->assertSame($invoice->id, $link->purchase_invoice_id);
        $this->assertNotNull($link->installment_id);
        $this->assertSame([], $this->openTypes($r['slip']));
        // o boleto da oficina é a conta do "A pagar"
        $this->assertSame(32000, $r['slip']->payable->amount_cents);
        $this->assertSame('2026-10-20', $r['slip']->payable->due_date->toDateString());
    }

    public function test_boleto_sem_nota_e_grave_e_o_pagamento_trava_ate_liberar_com_motivo(): void
    {
        $this->invoiceOf(500, 320);
        $r = $this->registerSlip(777.77, '2026-10-25');

        $this->assertFalse($r['auto']['linked']);
        $this->assertSame([['boleto_sem_nota', 'alta']], $this->pairs($r['occurrences']));

        try {
            $this->pay($r['slip']);
            $this->fail('Pagou boleto sem nota.');
        } catch (PaymentBlocked $e) {
            $this->assertSame(['boleto_sem_nota'], array_column($e->occurrences, 'type'));
        }

        try {
            $this->pay($r['slip'], ['release' => true, 'reason' => 'ok']);
            $this->fail('Motivo curto liberou o pagamento.');
        } catch (PaymentBlocked $e) {
            $this->assertStringContainsString('pelo menos 10', $e->getMessage());
        }

        $paid = $this->pay($r['slip'], ['release' => true, 'reason' => 'Fornecedor confirmou por telefone']);
        $this->assertTrue($paid['released']);
        $slip = $r['slip']->fresh();
        $this->assertTrue($slip->isPaid());
        $this->assertSame('Fornecedor confirmou por telefone', $slip->released_reason);
        $this->assertSame('2026-10-08', $slip->payable->paid_on->toDateString());

        $this->expectExceptionMessage('já foi pago');
        $this->pay($slip);
    }

    public function test_boleto_ligado_e_sem_problema_paga_depois_de_conferir_o_recebedor_no_banco(): void
    {
        $this->invoiceOf(500, 320);
        $r = $this->registerSlip(320, '2026-10-20');

        try {
            $this->slips()->pay($r['slip'], [], $this->gestor());
            $this->fail('Pagou sem conferir o recebedor no banco.');
        } catch (PaymentBlocked $e) {
            $this->assertSame('confira_recebedor', $e->occurrences[0]['type']);
            $this->assertStringContainsString(self::SUPPLIER_NAME, $e->occurrences[0]['detail']);
        }

        $this->assertFalse($this->pay($r['slip'])['released']);
        $this->assertSame('2026-10-08', $r['slip']->fresh()->bank_checked_on->toDateString());
    }

    public function test_quem_recebe_diferente_e_grave_e_filial_do_fornecedor_e_media(): void
    {
        $this->invoiceOf(500, 320);
        $this->assertSame([['boleto_beneficiario_diverge', 'alta']], $this->pairs($this->registerSlip(320, '2026-10-20', ['payee_cnpj' => self::OTHER_CNPJ])['occurrences']));

        $this->invoiceOf(501, 410, [['due' => '2026-10-21', 'amount' => 410]]);
        $this->assertSame([['boleto_beneficiario_diverge', 'media']], $this->pairs($this->registerSlip(410, '2026-10-21', ['payee_cnpj' => self::SUPPLIER_BRANCH_CNPJ])['occurrences']));

        $this->invoiceOf(502, 415, [['due' => '2026-10-22', 'amount' => 415]]);
        $this->assertSame([], $this->registerSlip(415, '2026-10-22')['occurrences']);
    }

    public function test_cnpj_digitado_errado_e_recusado(): void
    {
        $this->expectExceptionMessage('CNPJ do beneficiário');
        $this->registerSlip(320, '2026-10-20', ['payee_cnpj' => '11222333000263']);
    }

    public function test_boleto_agrupado_e_sugerido_mas_nao_ligado_sozinho(): void
    {
        $this->invoiceOf(600, 200, []);
        $this->invoiceOf(601, 150, []);
        $this->invoiceOf(602, 999, []);
        $r = $this->registerSlip(350, '2026-10-20');

        $this->assertFalse($r['auto']['linked']);
        $suggestion = $this->slips()->suggestInvoices($r['slip'])[0];
        $this->assertTrue($suggestion['grouped']);
        $this->assertCount(2, $suggestion['items']);
        $this->assertSame(60, $suggestion['score']);
    }

    public function test_boleto_maior_que_a_nota_e_valor_divergente_alto(): void
    {
        $invoice = $this->invoiceOf(500, 320, []);
        $r = $this->registerSlip(350, '2026-10-20', ['document_number' => '000500']);

        $this->assertFalse($r['auto']['linked']);         // 75: o número bate mas o valor não
        $this->slips()->link($r['slip'], [['invoice_id' => $invoice->id, 'amount' => 32000]], $this->gestor());
        $divergence = collect(app(Occurrences::class)->forSlip($r['slip']))->firstWhere('type', 'boleto_valor_diverge');

        $this->assertSame('alta', $divergence['severity']);
        $this->assertSame(3000, $divergence['amount_cents']);
        $this->assertStringContainsString('sobram', $divergence['detail']);
    }

    public function test_duplicidade_e_cadastro_repetido_da_mesma_linha(): void
    {
        $this->invoiceOf(500, 320);
        $line = $this->slipLine(320, '2026-10-20');
        $a = $this->slips()->create(['line' => $line, 'supplier_name' => self::SUPPLIER_NAME], $this->gestor());

        try {
            $this->slips()->create(['line' => $line, 'supplier_name' => self::SUPPLIER_NAME], $this->gestor());
            $this->fail('A mesma linha entrou duas vezes.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('já está cadastrado', $e->getMessage());
        }

        $b = $this->registerSlip(320, '2026-10-20');
        $duplicate = collect(app(Occurrences::class)->all())->firstWhere('type', 'boleto_duplicado');
        $this->assertEqualsCanonicalizing([$a['slip']->id, $b['slip']->id], $duplicate['slips']);
        $this->assertSame('alta', $duplicate['severity']);
    }

    public function test_valor_ou_vencimento_digitados_diferentes_da_linha_sao_recusados(): void
    {
        $line = $this->slipLine(320, '2026-10-20');

        foreach ([['amount' => '32'], ['due_date' => '2026-10-21'], ['line' => substr($line, 0, 40).'9999999']] as $case) {
            try {
                $this->slips()->create(['line' => $line, 'supplier_name' => 'FORNECEDOR F', ...$case], $this->gestor());
                $this->fail('Aceitou '.json_encode($case));
            } catch (DomainException $e) {
                $this->assertMatchesRegularExpression('/digitado|confere|verificador/', $e->getMessage());
            }
        }
    }

    public function test_boleto_sem_linha_exige_valor_e_vencimento_e_fornecedor(): void
    {
        foreach ([[[], 'valor'], [['amount' => '100'], 'vencimento'], [['amount' => '100', 'due_date' => '2026-10-30', 'supplier_name' => ''], 'fornecedor'],
            [['amount' => '99999999999', 'due_date' => '2026-10-30'], 'grande demais'], [['amount' => '10', 'due_date' => '2026-10-30', 'supplier_id' => 999, 'supplier_name' => null], 'Fornecedor não encontrado']] as [$data, $message]) {
            try {
                $this->slips()->create($data + ['supplier_name' => 'FORNECEDOR F'], $this->gestor());
                $this->fail('Aceitou '.json_encode($data));
            } catch (DomainException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        $this->assertNotNull($this->slips()->create(['amount' => '100', 'due_date' => '2026-10-30', 'supplier_name' => 'FORNECEDOR F'], $this->gestor())['slip']->id);
    }

    public function test_cancelar_o_boleto_remove_a_conta_e_libera_a_nota_que_liga_ao_boleto_certo(): void
    {
        $invoice = $this->invoiceOf(500, 320, [['due' => '2026-10-09', 'amount' => 320]]);
        $first = $this->registerSlip(320, '2026-10-09');
        $payable = $first['slip']->payable_id;
        $this->assertTrue($first['auto']['linked']);

        $this->slips()->cancel($first['slip'], 'contestado', 'valor errado', $this->gestor());

        $this->assertNull(Payable::find($payable));
        $this->assertSame(0, PurchaseLink::query()->where('purchase_invoice_id', $invoice->id)->count());
        $this->assertTrue(collect(app(Occurrences::class)->all())->contains(fn ($o) => $o['type'] === 'nota_sem_boleto' && in_array($invoice->id, $o['invoices'], true)));
        $second = $this->registerSlip(320, '2026-10-09');
        $this->assertTrue($second['auto']['linked']);
        $this->assertSame([], array_filter($second['occurrences'], fn ($o) => $o['severity'] === 'alta'));
    }

    public function test_contestado_nao_paga_pode_ser_reaberto_e_cancelado_pode_ser_recadastrado(): void
    {
        $this->invoiceOf(501, 100);
        $line = $this->slipLine(100, '2026-10-20');
        $r = $this->slips()->create(['line' => $line, 'payee_cnpj' => self::SUPPLIER_CNPJ, 'payer_cnpj' => self::WORKSHOP_CNPJ, 'supplier_name' => self::SUPPLIER_NAME], $this->gestor());
        $this->slips()->cancel($r['slip'], 'contestado', 'conferindo com o fornecedor', $this->gestor());

        $this->assertThrows(fn () => $this->pay($r['slip']), DomainException::class, 'contestado');
        $this->assertThrows(fn () => $this->slips()->create(['line' => $line, 'supplier_name' => self::SUPPLIER_NAME], $this->gestor()), DomainException::class, 'contestado');

        $reopened = $this->slips()->reopen($r['slip']->fresh(), $this->gestor());
        $this->assertTrue($reopened['auto']['linked']);
        $this->assertNotNull($r['slip']->fresh()->payable_id);

        $this->slips()->cancel($r['slip']->fresh(), 'cancelado', null, $this->gestor());
        $again = $this->slips()->create(['line' => $line, 'payee_cnpj' => self::SUPPLIER_CNPJ, 'payer_cnpj' => self::WORKSHOP_CNPJ, 'supplier_name' => self::SUPPLIER_NAME], $this->gestor());
        $this->assertSame($r['slip']->id, $again['slip']->id);                // reaproveita o registro
        $this->assertSame(BankSlip::OPEN, $again['slip']->status);
    }

    public function test_ligacao_recusa_o_que_nao_pode_ser_verdade(): void
    {
        $invoice = $this->invoiceOf(510, 400);
        $slip = $this->registerSlip(150, '2026-10-21')['slip'];
        $link = fn (array $items) => $this->slips()->link($slip->fresh(), $items, $this->gestor());

        $this->assertThrows(fn () => $link([['invoice_id' => $invoice->id, 'amount' => 40000]]), DomainException::class, 'mais que o boleto');
        $this->assertThrows(fn () => $link([['invoice_id' => 9999, 'amount' => 100]]), DomainException::class, 'não encontrada');
        $this->assertThrows(fn () => $link([]), DomainException::class, 'ao menos uma nota');
        $this->assertThrows(fn () => $link([['invoice_id' => $invoice->id, 'amount' => 10000, 'installment_id' => 99999]]), DomainException::class, 'não pertence');

        $other = $this->importInvoice(['number' => 511, 'issuer' => self::OTHER_CNPJ, 'issuer_name' => 'OUTRA LTDA', 'items' => [['code' => 'Z', 'name' => 'Z', 'qty' => 1, 'total' => 150.0]]]);
        $this->assertThrows(fn () => $link([['invoice_id' => $other->id, 'amount' => 15000]]), DomainException::class, 'outro fornecedor');
        $return = $this->importInvoice(['number' => 512, 'purpose' => 4, 'items' => [['code' => 'D', 'name' => 'D', 'qty' => 1, 'total' => 150.0]]]);
        $this->assertThrows(fn () => $link([['invoice_id' => $return->id, 'amount' => 15000]]), DomainException::class, 'devolução');

        $link([['invoice_id' => $invoice->id, 'amount' => 15000]]);
        $bigger = $this->registerSlip(300, '2026-10-22')['slip'];
        $this->assertThrows(fn () => $this->slips()->link($bigger, [['invoice_id' => $invoice->id, 'amount' => 30000]], $this->gestor()), DomainException::class, 'só tem R$ 250,00');

        app(Invoices::class)->setStatus($invoice, PurchaseInvoice::CANCELED, 'fornecedor cancelou', $this->gestor());
        $third = $this->registerSlip(50, '2026-10-23')['slip'];
        $this->assertThrows(fn () => $this->slips()->link($third, [['invoice_id' => $invoice->id, 'amount' => 5000]], $this->gestor()), DomainException::class, 'cancelada');
    }

    public function test_boleto_pago_nao_desfaz_a_ligacao_mas_aceita_a_nota_que_chegou_depois(): void
    {
        $slip = $this->registerSlip(777, '2026-10-12')['slip'];
        $this->pay($slip, ['release' => true, 'reason' => 'Fornecedor confirmou por telefone, nota amanhã']);
        $invoice = $this->invoiceOf(520, 777, [['due' => '2026-10-12', 'amount' => 777]]);

        $this->assertThrows(fn () => $this->slips()->link($slip->fresh(), [['invoice_id' => $invoice->id, 'amount' => 77700]], $this->desk()), DomainException::class, 'só o gestor');
        $this->slips()->link($slip->fresh(), [['invoice_id' => $invoice->id, 'amount' => 77700]], $this->gestor());
        $this->assertThrows(fn () => $this->slips()->unlink($slip->fresh(), $invoice->id, $this->gestor()), DomainException::class, 'já foi pago');
    }

    public function test_pagamento_valor_diferente_explicado_e_ressalvas_registradas(): void
    {
        $this->invoiceOf(550, 320);
        $slip = $this->registerSlip(320, '2026-10-20')['slip'];

        $this->assertThrows(fn () => $this->pay($slip, ['paid_amount' => '340']), DomainException::class, 'diferente do boleto');
        $this->assertThrows(fn () => $this->pay($slip, ['paid_amount' => '340', 'reason' => 'multa']), DomainException::class, 'pelo menos 10');

        $this->assertFalse($this->pay($slip, ['paid_amount' => '340', 'reason' => 'multa e juros de 5 dias de atraso'])['released']);
        $slip->refresh();
        $this->assertStringContainsString('multa e juros', $slip->released_reason);
        $this->assertSame(34000, $slip->paid_cents);
        $this->assertTrue($slip->payable->isPaid());
    }

    public function test_pagar_com_grave_ja_aceita_registra_o_aceite_no_boleto(): void
    {
        $slip = $this->registerSlip(990, '2026-10-30')['slip'];
        $key = collect(app(Occurrences::class)->forSlip($slip))->firstWhere('type', 'boleto_sem_nota')['key'];
        app(Occurrences::class)->accept($key, 'Nota vem junto da mercadoria amanhã', $this->gestor());

        $this->assertTrue($this->pay($slip)['released']);
        $this->assertStringContainsString('Aceito antes: Boleto sem nota fiscal (Nota vem junto', $slip->fresh()->released_reason);
    }

    public function test_conta_ja_lancada_no_a_pagar_e_adotada_e_devolvida_como_era(): void
    {
        $supplier = Supplier::query()->where('document', self::SUPPLIER_CNPJ)->first();
        $payable = Payable::create(['supplier_id' => $supplier->id, 'description' => 'Boleto de outubro', 'due_date' => '2026-10-25', 'amount_cents' => 25000, 'status' => 'aberto']);
        $r = $this->registerSlip(250, '2026-10-25');

        $this->assertTrue($r['adopted']);
        $this->assertSame($payable->id, $r['slip']->payable_id);
        $this->assertSame(1, Payable::query()->where('amount_cents', 25000)->count());
        $this->assertStringContainsString('ligada ao boleto', $payable->fresh()->notes);

        $this->slips()->cancel($r['slip'], 'cancelado', null, $this->gestor());
        $this->assertSame('Boleto de outubro', $payable->fresh()->description);         // nunca é apagada
        $this->assertNull($r['slip']->fresh()->payable_id);
    }

    public function test_informar_quem_recebe_depois_aponta_divergencia_e_fica_no_historico(): void
    {
        $this->invoiceOf(560, 410);
        $r = $this->registerSlip(410, '2026-10-20', ['payee_cnpj' => '', 'payer_cnpj' => '']);
        $this->assertContains('boleto_sem_beneficiario', $this->openTypes($r['slip']));

        $this->slips()->update($r['slip'], ['payee_cnpj' => '44.555.666/0001-81', 'payer_cnpj' => self::WORKSHOP_CNPJ], $this->desk());

        $types = $this->pairs(app(Occurrences::class)->forSlip($r['slip']));
        $this->assertContains(['boleto_beneficiario_diverge', 'alta'], $types);
        $this->assertNotContains('boleto_sem_beneficiario', array_column($types, 0));
        $this->assertThrows(fn () => $this->slips()->update($r['slip']->fresh(), ['payee_cnpj' => '12345'], $this->desk()), DomainException::class, 'validação');
        $this->assertDatabaseHas('audit_logs', ['action' => 'purchase.slip_corrected', 'auditable_id' => $r['slip']->id]);
    }

    public function test_lote_cadastra_os_bons_e_aponta_repetido_e_invalido(): void
    {
        $this->invoiceOf(10, 150, [['due' => '2026-10-25', 'amount' => 150]]);
        $l1 = $this->slipLine(150, '2026-10-25');
        $l2 = $this->slipLine(99.9, '2026-10-26');

        $results = $this->slips()->createBatch("{$l1}\n{$l2}\n{$l1}\n123\n", null, self::SUPPLIER_NAME, $this->desk());

        $this->assertSame(['criado', 'criado', 'erro', 'erro'], array_column($results, 'status'));
        $this->assertSame([true, false], [$results[0]['linked'], $results[1]['linked']]);
        $this->assertStringContainsString('já está cadastrado', $results[2]['error']);
        $this->assertSame(1, $results[1]['serious']);       // sem nota
        $this->assertThrows(fn () => $this->slips()->createBatch($l1, null, null, $this->desk()), DomainException::class, 'Escolha o fornecedor');
    }

    public function test_ligacao_so_com_a_nota_abate_as_parcelas_na_ordem(): void
    {
        $invoice = $this->invoiceOf(600, 600, [['due' => '2026-10-10', 'amount' => 300], ['due' => '2026-11-10', 'amount' => 300]]);
        $r = $this->registerSlip(600, '2026-10-10');

        $this->assertFalse($r['auto']['linked']);                                // 70: sugere, não liga sozinho
        $this->assertSame(70, $r['auto']['suggestions'][0]['score']);
        $this->slips()->link($r['slip'], [['invoice_id' => $invoice->id, 'amount' => 60000]], $this->gestor());

        $this->assertSame([0, 0], array_column(app(Invoices::class)->installmentsWithBalance($invoice), 'balance_cents'));
        $this->assertFalse(collect(app(Occurrences::class)->all())->contains(fn ($o) => $o['type'] === 'nota_sem_boleto' && in_array($invoice->id, $o['invoices'], true)));
    }

    public function test_quem_lanca_so_desfaz_o_proprio_cadastro_e_nao_paga_nem_contesta(): void
    {
        $this->invoiceOf(501, 300, [['due' => '2026-10-20', 'amount' => 300]]);
        $mine = $this->registerSlip(300, '2026-10-20', [], $this->desk())['slip'];
        $fromGestor = $this->registerSlip(80, '2026-10-22')['slip'];

        $this->assertSame('atendimento', $mine->created_role);
        $this->assertThrows(fn () => $this->slips()->pay($mine, ['bank_checked' => true], $this->desk()), DomainException::class, 'gestor');
        $this->assertThrows(fn () => $this->slips()->cancel($mine, 'contestado', null, $this->desk()), DomainException::class, 'Só o gestor');
        $this->assertThrows(fn () => $this->slips()->cancel($fromGestor, 'cancelado', null, $this->desk()), DomainException::class, 'Só o gestor');
        $this->assertThrows(fn () => $this->slips()->reopen($mine, $this->desk()), DomainException::class, 'gestor');

        $this->slips()->cancel($mine, 'cancelado', 'lancei errado', $this->desk());
        $this->assertSame(BankSlip::CANCELED, $mine->fresh()->status);

        // O gestor cancela: cadastrar a mesma linha de novo, pelo atendimento, não reabre.
        $line = $this->slipLine(70, '2026-10-26');
        $slip = $this->slips()->create(['line' => $line, 'supplier_name' => self::SUPPLIER_NAME], $this->desk())['slip'];
        $this->slips()->cancel($slip, 'cancelado', null, $this->gestor());
        $this->assertThrows(fn () => $this->slips()->create(['line' => $line, 'supplier_name' => self::SUPPLIER_NAME], $this->desk()), DomainException::class, 'cancelado pelo gestor');
    }

    public function test_texto_do_boleto_cadastrado_pela_linha_achada_no_texto(): void
    {
        $line = $this->slipLine(99.9, '2026-10-25');
        $read = BoletoText::read("Beneficiário DISTRIBUIDORA TESTE LTDA CNPJ 11.222.333/0001-81\nPagador OFICINA ".self::WORKSHOP_CNPJ."\n".BoletoCode::formatLine($line), '2026-10-08');

        $this->assertSame($line, $read['slip']['line']);
        $this->assertSame(self::SUPPLIER_CNPJ, $read['payee_cnpj']);
        $this->assertSame(self::WORKSHOP_CNPJ, $read['payer_cnpj']);
    }
}
