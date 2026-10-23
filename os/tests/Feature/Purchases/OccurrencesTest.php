<?php

namespace Tests\Feature\Purchases;

use App\Enums\Stage;
use App\Models\AuditAcceptance;
use App\Models\AuditLog;
use App\Models\OrderItem;
use App\Models\Payable;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseLink;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Vehicle;
use App\Services\Purchases\Allocations;
use App\Services\Purchases\BankSlips;
use App\Services\Purchases\Invoices;
use App\Services\Purchases\Occurrences;
use App\Services\Purchases\PurchaseSuppliers;
use App\Support\Document;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOrders;
use Tests\Concerns\BuildsPurchases;
use Tests\TestCase;

/**
 * Porte das regras de ocorrência e de destino das peças de `app/test/compras.test.js`, `revisao_compras.test.js`,
 * `revisao_rodada2.test.js` e `perfis_grupo.test.js` (aceite, prova da nota, preço, OS sem nota, peças sem destino).
 */
class OccurrencesTest extends TestCase
{
    use BuildsOrders;
    use BuildsPurchases;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00'));
        $this->purchaseScene();
    }

    private function occurrences(): Occurrences
    {
        return app(Occurrences::class);
    }

    private function find(string $type): ?array
    {
        return collect($this->occurrences()->all())->firstWhere('type', $type);
    }

    private function types(): array
    {
        return collect($this->occurrences()->all())->where('accepted', null)->pluck('type')->all();
    }

    public function test_pagador_diferente_da_oficina_e_nota_para_outro_cnpj_sao_altas(): void
    {
        $this->invoiceOf(500, 320, null, ['recipient' => self::OTHER_CNPJ]);
        $r = $this->registerSlip(320, '2026-10-20', ['payer_cnpj' => self::OTHER_CNPJ]);

        $types = array_column($this->pairs($r['occurrences']), 1, 0);
        $this->assertSame('alta', $types['boleto_pagador_diverge']);
        $this->assertSame('alta', $types['nota_destinatario_diverge']);
    }

    public function test_parcela_vencida_sem_boleto_e_media_e_o_aceite_com_motivo_tira_da_lista(): void
    {
        $this->invoiceOf(500, 320, [['due' => '2026-10-05', 'amount' => 320]]);
        $occurrence = $this->find('nota_sem_boleto');
        $this->assertSame('media', $occurrence['severity']);
        $before = $this->occurrences()->summary($this->occurrences()->all())['media'];

        $this->assertThrows(fn () => $this->occurrences()->accept($occurrence['key'], 'ok', $this->gestor()), DomainException::class, 'Explique');
        $this->assertThrows(fn () => $this->occurrences()->accept($occurrence['key'], 'Pago no Pix em 05/10', $this->desk()), DomainException::class, 'gestor');
        $this->occurrences()->accept($occurrence['key'], 'Pago no Pix em 05/10', $this->gestor());

        $this->assertNotNull($this->find('nota_sem_boleto')['accepted']);
        $this->assertSame($before - 1, $this->occurrences()->summary($this->occurrences()->all())['media']);
    }

    public function test_nota_cancelada_com_boleto_e_nota_cobrada_duas_vezes_sao_altas(): void
    {
        $invoice = $this->invoiceOf(500, 320);
        $first = $this->registerSlip(320, '2026-10-20')['slip'];
        $second = $this->registerSlip(320, '2026-10-27')['slip'];

        $this->assertThrows(fn () => app(BankSlips::class)->link($second, [['invoice_id' => $invoice->id, 'amount' => 32000]], $this->gestor()), DomainException::class, 'só tem R$ 0,00 sem boleto');
        PurchaseLink::create(['bank_slip_id' => $second->id, 'purchase_invoice_id' => $invoice->id, 'amount_cents' => 32000, 'source' => 'manual']);      // dado antigo assim ainda é apontado
        $this->assertSame('alta', $this->find('nota_cobrada_a_mais')['severity']);

        $invoice->update(['status' => PurchaseInvoice::CANCELED]);
        $this->assertContains('nota_cancelada_com_boleto', $this->openTypes($first));
    }

    public function test_aceite_vale_so_para_o_mesmo_fato(): void
    {
        $big = $this->invoiceOf(530, 1000);
        $small = $this->invoiceOf(531, 40);
        $slip = $this->registerSlip(100, '2026-10-20')['slip'];
        $slips = app(BankSlips::class);
        $slips->link($slip, [['invoice_id' => $big->id, 'amount' => 7000], ['invoice_id' => $small->id, 'amount' => 3000]], $this->gestor());
        $slips->unlink($slip->fresh(), $big->id, $this->gestor());                 // sobram R$ 70 do boleto sem explicação

        $occurrence = collect($this->occurrences()->forSlip($slip))->firstWhere('type', 'boleto_valor_diverge');
        $this->assertSame('alta', $occurrence['severity']);
        $this->occurrences()->accept($occurrence['key'], 'Juros combinados por telefone', $this->gestor());
        $this->assertNotNull(collect($this->occurrences()->all())->firstWhere('key', $occurrence['key'])['accepted']);

        $slips->link($slip->fresh(), [['invoice_id' => $small->id, 'amount' => 1000]], $this->gestor());       // agora sobram R$ 90: outro fato
        $now = collect($this->occurrences()->all())->firstWhere('key', $occurrence['key']);
        $this->assertNull($now['accepted']);
        $this->assertNotNull($now['acceptance_expired']);
    }

    public function test_aceitar_exige_ocorrencia_que_existe_motivo_de_verdade_e_deixa_trilha(): void
    {
        $this->assertThrows(fn () => $this->occurrences()->accept('boleto_sem_nota:boleto:1', 'vou aceitar de antemão', $this->gestor()), DomainException::class, 'não existe mais');
        $slip = $this->registerSlip(88, '2026-10-30')['slip'];
        $key = collect($this->occurrences()->forSlip($slip))->firstWhere('type', 'boleto_sem_nota')['key'];

        $this->assertThrows(fn () => $this->occurrences()->accept($key, 'aaaaaaaaaaaa', $this->gestor()), DomainException::class, 'pelo menos 10');
        $this->occurrences()->accept($key, 'Nota chega amanhã por e-mail', $this->gestor());
        $this->occurrences()->accept($key, 'Nota chegou, lançar segunda', $this->gestor());          // reaceitar troca o atual...
        $this->assertTrue($this->occurrences()->undoAcceptance($key, $this->gestor()));

        $this->assertSame(['purchase.occurrence_accepted', 'purchase.occurrence_accepted', 'purchase.occurrence_unaccepted'],
            AuditLog::query()->where('action', 'like', 'purchase.occurrence%')->orderBy('id')->pluck('action')->all());      // ...mas a trilha guarda os dois motivos
        $this->assertSame(0, AuditAcceptance::count());
    }

    public function test_nota_apagada_leva_o_aceite_e_a_nova_nao_herda(): void
    {
        $invoice = $this->invoiceOf(540, 5000, null, ['recipient' => self::OTHER_CNPJ]);
        $this->occurrences()->accept($this->find('nota_destinatario_diverge')['key'], 'é nota do sócio, conferido', $this->gestor());
        app(Invoices::class)->delete($invoice, $this->gestor());

        $this->invoiceOf(541, 9000, null, ['recipient' => self::OTHER_CNPJ]);
        $this->assertNull($this->find('nota_destinatario_diverge')['accepted']);
    }

    public function test_quem_recebe_sem_cnpj_e_lembrete_que_vira_media_perto_do_vencimento_e_nao_trava(): void
    {
        $this->invoiceOf(500, 320, [['due' => '2026-11-20', 'amount' => 320]]);
        $far = $this->registerSlip(320, '2026-11-20', ['payee_cnpj' => '']);
        $this->assertSame([['boleto_sem_beneficiario', 'baixa']], $this->pairs($far['occurrences']));

        $this->invoiceOf(501, 90, [['due' => '2026-10-10', 'amount' => 90]]);
        $near = $this->registerSlip(90, '2026-10-10', ['payee_cnpj' => '']);
        $this->assertSame([['boleto_sem_beneficiario', 'media']], $this->pairs($near['occurrences']));

        $this->assertNotContains('boleto_sem_beneficiario', array_column($this->registerSlip(777.77, '2026-11-25')['occurrences'], 'type'));
        $this->assertFalse(app(BankSlips::class)->pay($near['slip'], ['bank_checked' => true], $this->gestor())['released']);      // lembrete não trava
    }

    public function test_fornecedor_que_aparece_com_outro_banco_e_alerta_medio(): void
    {
        $slip = fn (float $amount, string $due, string $bank) => app(BankSlips::class)->create(['line' => $this->slipLine($amount, $due, $bank), 'payee_cnpj' => self::SUPPLIER_CNPJ, 'supplier_name' => self::SUPPLIER_NAME], $this->gestor());
        $this->invoiceOf(600, 100, [['due' => '2026-10-20', 'amount' => 100]]);
        $this->invoiceOf(601, 110, [['due' => '2026-10-21', 'amount' => 110]]);
        $this->invoiceOf(602, 120, [['due' => '2026-10-22', 'amount' => 120]]);

        $this->assertNotContains('boleto_banco_novo', array_column($slip(100, '2026-10-20', '341')['occurrences'], 'type'));
        $this->assertNotContains('boleto_banco_novo', array_column($slip(110, '2026-10-21', '341')['occurrences'], 'type'));       // só 1 anterior: sem histórico
        $this->assertContains(['boleto_banco_novo', 'media'], $this->pairs($slip(120, '2026-10-22', '237')['occurrences']));
        $this->assertNotContains('boleto_banco_novo', array_column($slip(55, '2026-10-23', '341')['occurrences'], 'type'));         // voltou a um banco já usado
    }

    public function test_nota_paga_no_ato_nao_cobra_boleto_e_boleto_dela_e_grave(): void
    {
        $this->invoiceOf(700, 200, [], ['issued_at' => '2026-09-20T10:00:00-03:00', 'payment' => '<detPag><tPag>17</tPag><vPag>200.00</vPag></detPag>']);
        $this->invoiceOf(701, 150, [], ['issued_at' => '2026-09-20T10:00:00-03:00']);
        $withoutSlip = collect($this->occurrences()->all())->where('type', 'nota_sem_boleto');

        $this->assertCount(1, $withoutSlip);
        $this->assertStringContainsString('701', $withoutSlip->first()['detail']);

        $pix = $this->invoiceOf(5200, 400, [], ['payment' => '<detPag><indPag>0</indPag><tPag>17</tPag><vPag>400.00</vPag></detPag>']);
        $slip = $this->registerSlip(400, '2026-10-25', [], $this->desk())['slip'];
        app(BankSlips::class)->link($slip, [['invoice_id' => $pix->id, 'amount' => 40000]], $this->desk());
        $this->assertContains(['boleto_nota_paga_no_ato', 'alta'], $this->pairs($this->occurrences()->forSlip($slip)));
        $this->assertStringStartsWith('NÃO PAGUE', $this->occurrences()->verdict($slip->fresh(), $this->occurrences()->forSlip($slip))['text']);
    }

    public function test_cnpj_autorizado_ou_informado_pela_nota_pode_receber_e_outro_continua_grave(): void
    {
        $this->invoiceOf(720, 400);
        $slip = $this->registerSlip(400, '2026-10-20', ['payee_cnpj' => self::OTHER_CNPJ])['slip'];
        $diverges = fn () => in_array('boleto_beneficiario_diverge', $this->openTypes($slip), true);
        $this->assertTrue($diverges());

        $supplier = Supplier::query()->where('document', self::SUPPLIER_CNPJ)->first();
        $this->assertThrows(fn () => app(PurchaseSuppliers::class)->setAuthorizedPayees($supplier, self::OTHER_CNPJ, $this->desk()), DomainException::class, 'gestor');
        app(PurchaseSuppliers::class)->setAuthorizedPayees($supplier, Document::format(self::OTHER_CNPJ), $this->gestor());
        $this->assertFalse($diverges());

        $supplier->update(['authorized_payees' => null]);
        PurchaseInvoice::query()->update(['payee_cnpj' => self::OTHER_CNPJ]);
        $this->assertFalse($diverges());
        PurchaseInvoice::query()->update(['payee_cnpj' => null]);
        $this->assertTrue($diverges());
    }

    public function test_primeiro_pagamento_a_fornecedor_nao_conferido_e_grave_depois_so_conferencia(): void
    {
        Supplier::query()->update(['confirmed_at' => null]);
        $this->invoiceOf(90, 100);
        $first = $this->registerSlip(100, '2026-10-20');
        $this->assertSame('alta', collect($first['occurrences'])->firstWhere('type', 'fornecedor_nao_confirmado')['severity']);
        $this->assertThrows(fn () => app(BankSlips::class)->pay($first['slip'], ['bank_checked' => true], $this->gestor()), DomainException::class);
        app(BankSlips::class)->pay($first['slip'], ['bank_checked' => true, 'release' => true, 'reason' => 'Liguei no fornecedor e confirmei o CNPJ'], $this->gestor());

        $this->invoiceOf(91, 110, [['due' => '2026-10-25', 'amount' => 110]]);
        $this->assertSame('media', collect($this->registerSlip(110, '2026-10-25')['occurrences'])->firstWhere('type', 'fornecedor_nao_confirmado')['severity']);

        // Mudar o CNPJ desfaz a conferência; conferir é do gestor.
        $supplier = Supplier::query()->where('document', self::SUPPLIER_CNPJ)->first();
        $this->assertThrows(fn () => app(PurchaseSuppliers::class)->confirm($supplier, true, $this->desk()), DomainException::class, 'gestor');
        app(PurchaseSuppliers::class)->confirm($supplier, true, $this->gestor());
        $this->assertTrue($supplier->fresh()->isConfirmed());
        $supplier->update(['document' => self::SUPPLIER_BRANCH_CNPJ]);
        $this->assertFalse($supplier->fresh()->isConfirmed());
    }

    public function test_segunda_via_documento_que_nao_e_da_nota_e_conta_de_pecas_direto_no_a_pagar(): void
    {
        $this->invoiceOf(570, 200);
        $first = $this->registerSlip(200, '2026-10-20', ['line' => $this->slipLine(200, '2026-10-20', '341', 31415), 'document_number' => '000999'])['slip'];
        $this->assertContains('boleto_doc_diverge', $this->openTypes($first));

        $second = $this->registerSlip(205, '2026-10-27', ['line' => $this->slipLine(205, '2026-10-27', '341', 31415)])['slip'];
        $this->assertContains(['boleto_mesmo_titulo', 'alta'], $this->pairs($this->occurrences()->forSlip($second)));

        Payable::create(['supplier_id' => Supplier::query()->value('id'), 'description' => 'Peça do balcão', 'due_date' => '2026-10-03', 'amount_cents' => 38000, 'status' => 'pago', 'paid_on' => '2026-10-03']);
        $direct = $this->find('pagar_pecas_sem_boleto');
        $this->assertSame(38000, $direct['amount_cents']);
    }

    public function test_dinheiro_em_risco_conta_cada_boleto_uma_vez(): void
    {
        $slip = $this->registerSlip(500, '2026-10-20', ['payee_cnpj' => self::OTHER_CNPJ])['slip'];

        $this->assertGreaterThanOrEqual(2, count(array_filter($this->occurrences()->forSlip($slip), fn ($o) => $o['severity'] === 'alta')));
        $this->assertSame(50000, $this->occurrences()->summary($this->occurrences()->all())['at_risk_cents']);
    }

    public function test_ligacao_manual_do_atendimento_que_o_sistema_nao_sugeriria_vira_aviso(): void
    {
        $invoice = app(Invoices::class)->createManual(['supplier_name' => self::SUPPLIER_NAME, 'number' => '555', 'issue_date' => '2026-10-01', 'total' => '5000', 'recipient_cnpj' => self::WORKSHOP_CNPJ], $this->gestor());
        $slip = $this->registerSlip(1200, '2026-10-25', [], $this->desk())['slip'];
        app(BankSlips::class)->link($slip, [['invoice_id' => $invoice->id, 'amount' => '1200']], $this->desk());

        $this->assertContains('ligacao_manual_sem_aderencia', $this->openTypes($slip));
    }

    public function test_preco_bem_acima_do_historico_e_apontado(): void
    {
        $make = fn (int $n, float $price, string $date) => $this->importInvoice(['number' => $n, 'issued_at' => $date.'T10:00:00-03:00', 'items' => [['code' => 'FILTRO', 'name' => 'FILTRO DE OLEO', 'qty' => 1, 'total' => $price]]]);
        $make(900, 30, '2026-08-10');
        $make(901, 31, '2026-08-25');
        $make(902, 30, '2026-09-10');
        $expensive = $make(903, 45, '2026-10-01');

        $occurrence = $this->find('preco_acima');
        $this->assertSame([$expensive->id], $occurrence['invoices']);
        $this->assertMatchesRegularExpression('/\+5\d%/', $occurrence['detail']);
    }

    public function test_pecas_sem_destino_depois_do_prazo_e_estoque_da_destino(): void
    {
        $invoice = $this->invoiceOf(702, 300, [], ['issued_at' => '2026-09-20T10:00:00-03:00']);
        $this->assertContains('nota_sem_destino', $this->types());

        app(Allocations::class)->allocate($invoice->items->first(), ['destination' => 'estoque'], $this->desk());
        $this->assertNotContains('nota_sem_destino', $this->types());
        $this->assertThrows(fn () => app(Allocations::class)->allocate($invoice->items->first()->fresh(), ['destination' => 'estoque'], $this->desk()), DomainException::class, 'já tem destino');
    }

    public function test_alocar_pecas_na_os_parcial_resto_e_custo_da_peca_vem_da_nota(): void
    {
        $invoice = $this->invoiceOf(700, 301);                                   // 2 unidades por R$ 301
        $item = $invoice->items->first();
        $orderA = $this->order(Stage::EmExecucao);
        $partA = $this->item($orderA);
        $orderB = $this->order(Stage::EmExecucao);
        $partB = $this->item($orderB);
        $allocations = app(Allocations::class);

        $first = $allocations->allocate($item, ['destination' => 'os', 'service_order_id' => $orderA->id, 'os_item_id' => $partA->id, 'quantity' => '1'], $this->desk());
        $this->assertSame(1.0, $allocations->remaining($item)['quantity']);
        $this->assertSame([15050, true], [$partA->fresh()->cost_cents, $partA->fresh()->cost_from_invoice]);

        $allocations->allocate($item, ['destination' => 'os', 'service_order_id' => $orderB->id, 'os_item_id' => $partB->id], $this->desk());
        $this->assertSame(30100, (int) $item->allocations()->sum('cost_cents'));
        $this->assertSame(0.0, $allocations->remaining($item)['quantity']);
        $this->assertThrows(fn () => $allocations->allocate($item, ['destination' => 'os', 'service_order_id' => $orderA->id], $this->desk()), DomainException::class, 'já tem destino');

        $allocations->remove($first, $this->desk());
        $this->assertSame([null, false], [$partA->fresh()->cost_cents, $partA->fresh()->cost_from_invoice]);       // voltou a "não informado"
    }

    public function test_custo_digitado_nao_e_sobrescrito_e_a_divergencia_aparece(): void
    {
        $invoice = $this->invoiceOf(701, 300, []);
        $order = $this->order(Stage::EmExecucao);
        $part = $this->item($order, OrderItem::KIND_PART, ['cost_cents' => 12000]);         // alguém digitou 120, mas a nota diz 300

        $this->assertSame(1, app(Allocations::class)->allocateInvoiceToOrder($invoice, $order, $this->desk()));
        $this->assertSame(12000, $part->fresh()->cost_cents);
        $this->assertSame(-18000, $this->find('custo_os_diverge')['amount_cents']);
    }

    public function test_cancelar_a_nota_refaz_o_custo_e_os_cancelada_devolve_a_peca(): void
    {
        $invoice = $this->invoiceOf(580, 300, []);
        $item = $invoice->items->first();
        $order = $this->order(Stage::EmExecucao);
        $part = $this->item($order);
        app(Allocations::class)->allocate($item, ['destination' => 'os', 'service_order_id' => $order->id, 'os_item_id' => $part->id], $this->desk());
        $this->assertSame(30000, $part->fresh()->cost_cents);

        app(Invoices::class)->setStatus($invoice, PurchaseInvoice::CANCELED, 'fornecedor cancelou a nota', $this->gestor());
        $this->assertNull($part->fresh()->cost_cents);
        $this->assertThrows(fn () => app(Allocations::class)->allocate($item->fresh(), ['destination' => 'estoque'], $this->desk()), DomainException::class, 'cancelada');

        app(Invoices::class)->setStatus($invoice->fresh(), PurchaseInvoice::ACTIVE, 'engano, a nota vale', $this->gestor());
        $this->assertSame(30000, $part->fresh()->cost_cents);

        $order->update(['stage' => Stage::Cancelada]);
        app(Allocations::class)->allocate($item->fresh(), ['destination' => 'estoque'], $this->desk());           // a peça da OS cancelada voltou a ter saldo
        $this->assertSame(2, $item->allocations()->count());
    }

    public function test_sugestao_de_os_pelo_pedido_e_pela_placa_ignorando_o_ambiguo(): void
    {
        $orderA = $this->order(Stage::EmExecucao, ['number' => 1148]);
        $orderB = $this->order(Stage::EmExecucao, ['number' => 1149]);
        $orderB->vehicle->update(['plate' => 'QWE4R56']);
        $part = $this->item($orderA, OrderItem::KIND_PART, ['part_code' => 'A']);
        $invoice = $this->importInvoice(['number' => 800, 'info' => 'Veiculo placa QWE-4R56', 'items' => [
            ['code' => 'A', 'name' => 'AMORTECEDOR', 'qty' => 1, 'total' => 200.0, 'order' => '1148'], ['code' => 'B', 'name' => 'COXIM', 'qty' => 1, 'total' => 80.0],
        ]]);
        $suggestions = collect(app(Allocations::class)->suggestions($invoice))->keyBy('description');

        $this->assertSame([$orderA->id, $part->id], [$suggestions['AMORTECEDOR']['order_id'], $suggestions['AMORTECEDOR']['os_item_id']]);
        $this->assertStringContainsString('pedido', $suggestions['AMORTECEDOR']['reason']);
        $this->assertSame($orderB->id, $suggestions['COXIM']['order_id']);
        $this->assertStringContainsString('placa', $suggestions['COXIM']['reason']);
        $this->assertSame(2, app(Allocations::class)->applySuggestions($invoice, $this->desk()));

        // Duas OS com a mesma placa: a sugestão fica para a escolha à mão.
        $vehicle = Vehicle::query()->where('plate', 'QWE4R56')->first();
        $this->order(Stage::EmExecucao, ['number' => 1150, 'vehicle_id' => $vehicle->id]);
        $other = $this->importInvoice(['number' => 801, 'info' => 'PLACA QWE4R56', 'items' => [['code' => 'C', 'name' => 'BUCHA', 'qty' => 1, 'total' => 50.0]]]);
        $this->assertTrue(app(Allocations::class)->suggestions($other)[0]['ambiguous']);
        $this->assertSame(0, app(Allocations::class)->applySuggestions($other, $this->desk()));
    }

    public function test_os_com_custo_de_peca_sem_nota_vira_uma_ocorrencia_resumo(): void
    {
        $withPart = fn (int $number, int $cost, string $date) => tap($this->order(Stage::EmExecucao, ['number' => $number, 'entry_date' => $date]), fn ($o) => $this->item($o, OrderItem::KIND_PART, ['cost_cents' => $cost]));
        $a = $withPart(2001, 30000, '2026-10-02');
        $withPart(2002, 12000, '2026-10-03');
        $withPart(2003, 1000, '2026-10-03');                 // abaixo de R$ 30: fica fora
        $withPart(1999, 50000, '2026-09-15');                // antes do início da conferência (1º dia do mês)

        $summary = $this->find('os_sem_nota');
        $this->assertSame(42000, $summary['amount_cents']);
        $this->assertStringStartsWith('2 OS', $summary['title']);

        app(Allocations::class)->allocateInvoiceToOrder($this->invoiceOf(703, 300, []), $a, $this->desk());
        $this->assertSame(12000, $this->find('os_sem_nota')['amount_cents']);
    }

    public function test_cnpj_da_oficina_nao_informado_vira_aviso(): void
    {
        Unit::query()->update(['cnpj' => null]);

        $this->assertSame('media', $this->find('config_cnpj_oficina')['severity']);
    }
}
