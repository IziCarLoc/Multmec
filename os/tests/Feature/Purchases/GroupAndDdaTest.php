<?php

namespace Tests\Feature\Purchases;

use App\Models\BankSlip;
use App\Models\DdaImport;
use App\Models\DdaTitle;
use App\Models\GroupCompany;
use App\Models\GroupSettlement;
use App\Models\Payable;
use App\Models\Supplier;
use App\Services\Purchases\Allocations;
use App\Services\Purchases\BankSlips;
use App\Services\Purchases\Dda;
use App\Services\Purchases\GroupCompanies;
use App\Services\Purchases\Invoices;
use App\Services\Purchases\Occurrences;
use App\Support\Document;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOrders;
use Tests\Concerns\BuildsPurchases;
use Tests\TestCase;

/**
 * Porte de `app/test/perfis_grupo.test.js`, `dda.test.js` e da parte de grupo e DDA de `revisao_rodada2.test.js`:
 * empresas do grupo (nota e boleto no CNPJ delas), acerto entre empresas e o DDA do banco.
 */
class GroupAndDdaTest extends TestCase
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

    private function companies(): GroupCompanies
    {
        return app(GroupCompanies::class);
    }

    private function rental(): GroupCompany
    {
        return GroupCompany::query()->where('cnpj', self::RENTAL_CNPJ)->first()
            ?? $this->companies()->create(['name' => 'LOCADORA TESTE', 'cnpj' => self::RENTAL_CNPJ, 'role' => 'locadora'], $this->gestor());
    }

    private function find(string $type): ?array
    {
        return collect(app(Occurrences::class)->all())->firstWhere('type', $type);
    }

    // ------------------------------------------------------------------ empresas do grupo

    public function test_cnpj_desconhecido_e_grave_e_cadastrado_como_empresa_do_grupo_deixa_de_alertar(): void
    {
        $invoice = $this->invoiceOf(310, 400, [['due' => '2026-10-21', 'amount' => 400]], ['recipient' => self::RENTAL_CNPJ]);
        $slip = $this->registerSlip(400, '2026-10-21', ['payer_cnpj' => self::RENTAL_CNPJ])['slip'];

        $this->assertSame('alta', $this->find('nota_destinatario_diverge')['severity']);
        $this->assertSame('alta', $this->find('boleto_pagador_diverge')['severity']);
        $this->assertNotNull($slip->payable_id);                                    // enquanto é desconhecido, entra no A pagar da oficina

        $this->assertThrows(fn () => $this->companies()->create(['name' => 'X', 'cnpj' => self::WORKSHOP_CNPJ], $this->gestor()), DomainException::class, 'própria oficina');
        $this->assertThrows(fn () => $this->companies()->create(['name' => 'X', 'cnpj' => '11222333000299'], $this->gestor()), DomainException::class, 'validação');
        $this->assertThrows(fn () => $this->companies()->create(['name' => 'X', 'cnpj' => self::SUPPLIER_CNPJ], $this->gestor()), DomainException::class, 'fornecedor');
        $this->assertThrows(fn () => $this->companies()->create(['name' => 'X', 'cnpj' => self::RENTAL_CNPJ], $this->desk()), DomainException::class, 'gestor');
        $company = $this->rental();
        $this->assertThrows(fn () => $this->companies()->create(['name' => 'OUTRA', 'cnpj' => self::RENTAL_CNPJ], $this->gestor()), DomainException::class, 'já está cadastrada');

        $this->assertNull($this->find('nota_destinatario_diverge'));
        $this->assertNull($this->find('boleto_pagador_diverge'));
        $slip->refresh();
        $this->assertSame($company->id, $slip->group_company_id);
        $this->assertNull($slip->payable_id);
        $this->assertSame(0, Payable::count());                                      // fora do A pagar da oficina
        $this->assertSame($company->id, $invoice->fresh()->group_company_id);
        $this->assertSame('Da LOCADORA TESTE', app(Occurrences::class)->verdict($slip, app(Occurrences::class)->forSlip($slip))['short']);
    }

    public function test_boleto_da_empresa_exige_dizer_quem_paga_e_a_oficina_pagando_fica_a_receber(): void
    {
        $company = $this->rental();
        $this->invoiceOf(320, 900, [['due' => '2026-10-21', 'amount' => 900]], ['recipient' => self::RENTAL_CNPJ]);
        $r = $this->registerSlip(900, '2026-10-21', ['payer_cnpj' => self::RENTAL_CNPJ]);
        $this->assertSame([], $r['occurrences']);

        $this->assertThrows(fn () => app(BankSlips::class)->pay($r['slip'], ['bank_checked' => true], $this->gestor()), DomainException::class, 'quem está pagando');
        app(BankSlips::class)->pay($r['slip']->fresh(), ['bank_checked' => true, 'paid_by_party' => 'oficina'], $this->gestor());

        $overview = $this->companies()->overview();
        $this->assertSame(90000, $overview['receivable']);
        $settlement = $overview['companies'][0]['settlements'][0];
        $this->assertSame(['boleto', true], [$settlement->source, $settlement->cash]);

        // Devolução parcial e total; não passa do que falta; não volta no tempo.
        $this->assertThrows(fn () => $this->companies()->addReturn($settlement, ['amount' => '1000', 'returned_on' => '2026-10-08'], $this->gestor()), DomainException::class, 'Só faltam');
        $this->assertThrows(fn () => $this->companies()->addReturn($settlement, ['amount' => '300', 'returned_on' => '2026-10-05'], $this->gestor()), DomainException::class, 'anterior');
        $this->assertThrows(fn () => $this->companies()->addReturn($settlement, ['amount' => '300', 'returned_on' => '2026-12-31'], $this->gestor()), DomainException::class, 'futura');
        $partial = $this->companies()->addReturn($settlement, ['amount' => '300', 'returned_on' => '2026-10-08'], $this->gestor());
        $this->assertSame(60000, $this->companies()->overview()['receivable']);

        // Com devolução feita, o pagamento não se desfaz (primeiro desfaz a devolução).
        $this->assertThrows(fn () => app(BankSlips::class)->undoPayment($r['slip']->fresh(), $this->gestor()), DomainException::class, 'devolveu');
        $this->companies()->deleteReturn($partial, $this->gestor());
        $this->companies()->addReturn($settlement->fresh(), [], $this->gestor());                // sem valor = o que falta
        $this->assertSame(0, $this->companies()->overview()['receivable']);
        $this->assertThrows(fn () => $this->companies()->addReturn($settlement->fresh(), ['amount' => '1'], $this->gestor()), DomainException::class, 'por inteiro');
        $this->companies()->update($company, ['active' => false], $this->gestor());                // saldo zerado: pode desativar
        $this->assertFalse($company->fresh()->active);
    }

    public function test_empresa_pagando_nao_gera_a_receber_e_desfazer_o_pagamento_da_oficina_desfaz_o_acerto(): void
    {
        $this->rental();
        $release = ['bank_checked' => true, 'release' => true, 'reason' => 'Sem nota ainda, fornecedor confirmou'];
        $first = $this->registerSlip(200, '2026-10-25', ['payer_cnpj' => self::RENTAL_CNPJ])['slip'];
        app(BankSlips::class)->pay($first, [...$release, 'paid_by_party' => 'empresa'], $this->gestor());
        $this->assertSame(0, $this->companies()->overview()['receivable']);

        $second = $this->registerSlip(250, '2026-10-26', ['payer_cnpj' => self::RENTAL_CNPJ])['slip'];
        app(BankSlips::class)->pay($second, [...$release, 'paid_by_party' => 'oficina'], $this->gestor());
        $this->assertSame(25000, $this->companies()->overview()['receivable']);
        app(BankSlips::class)->undoPayment($second->fresh(), $this->gestor());
        $this->assertSame(0, $this->companies()->overview()['receivable']);
    }

    public function test_desativar_a_empresa_devolve_o_boleto_ao_a_pagar_e_acerto_aberto_impede(): void
    {
        $company = $this->companies()->create(['name' => 'OFICINA DO SOCIO', 'cnpj' => self::RENTAL_CNPJ, 'role' => 'socio'], $this->gestor());
        $slip = $this->registerSlip(150, '2026-10-30', ['payer_cnpj' => self::RENTAL_CNPJ])['slip'];
        $this->assertNull($slip->payable_id);

        $this->companies()->update($company, ['active' => false], $this->gestor());
        $slip->refresh();
        $this->assertNull($slip->group_company_id);
        $this->assertNotNull($slip->payable_id);
        $this->assertSame('alta', $this->find('boleto_pagador_diverge')['severity']);

        $this->companies()->update($company->fresh(), ['active' => true], $this->gestor());
        $manual = $this->companies()->createManual(['group_company_id' => $company->id, 'direction' => 'a_receber', 'amount' => '120', 'description' => 'Transferi para pagar o aluguel do pátio', 'occurred_on' => '2026-10-02', 'cash' => true], $this->gestor());
        $this->assertThrows(fn () => $this->companies()->update($company->fresh(), ['active' => false], $this->gestor()), DomainException::class, 'acerto em aberto');
        $this->companies()->deleteManual($manual, $this->gestor());
        $this->companies()->update($company->fresh(), ['active' => false], $this->gestor());
        $this->assertFalse($company->fresh()->active);
        $this->assertThrows(fn () => $this->companies()->createManual(['group_company_id' => $company->id, 'amount' => '100', 'description' => 'Futuro', 'occurred_on' => '2026-12-31'], $this->gestor()), DomainException::class, 'futura');
    }

    public function test_peca_da_oficina_entregue_a_empresa_fica_a_receber_e_desfazer_o_destino_desfaz(): void
    {
        $company = $this->rental();
        $invoice = $this->importInvoice(['number' => 330, 'items' => [['code' => 'F1', 'name' => 'FILTRO DE OLEO', 'qty' => 2, 'total' => 80.0], ['code' => 'F2', 'name' => 'FILTRO DE AR', 'qty' => 1, 'total' => 70.0]]]);
        $item = $invoice->items->first();
        $allocations = app(Allocations::class);

        $this->assertThrows(fn () => $allocations->allocate($item, ['destination' => 'outra_empresa'], $this->gestor()), DomainException::class, 'Escolha a empresa');
        $this->assertThrows(fn () => $allocations->allocate($item, ['destination' => 'outra_empresa', 'group_company_id' => $company->id], $this->desk()), DomainException::class, 'gestor');
        $allocation = $allocations->allocate($item, ['destination' => 'outra_empresa', 'group_company_id' => $company->id], $this->gestor());
        $this->assertSame(8000, $this->companies()->overview()['receivable']);

        // Item de nota em nome da própria empresa não gera acerto: não foi a oficina que comprou.
        $theirs = $this->importInvoice(['number' => 331, 'recipient' => self::RENTAL_CNPJ, 'items' => [['code' => 'F3', 'name' => 'FILTRO', 'qty' => 1, 'total' => 55.0]]]);
        $allocations->allocate($theirs->items->first(), ['destination' => 'outra_empresa', 'group_company_id' => $company->id], $this->gestor());
        $this->assertSame(8000, $this->companies()->overview()['receivable']);

        $settlement = GroupSettlement::query()->where('source', 'peca')->first();
        $this->assertFalse($settlement->cash);
        $return = $this->companies()->addReturn($settlement, ['amount' => '30'], $this->gestor());
        $this->assertThrows(fn () => $allocations->remove($allocation, $this->gestor()), DomainException::class, 'devolveu');
        $this->companies()->deleteReturn($return, $this->gestor());
        $this->assertThrows(fn () => $allocations->remove($allocation, $this->desk()), DomainException::class, 'gestor');
        $allocations->remove($allocation, $this->gestor());
        $this->assertSame(0, $this->companies()->overview()['receivable']);

        // Brinde (custo zero) entregue à empresa: aceita, sem acerto.
        $gift = $this->importInvoice(['number' => 778, 'items' => [['code' => 'B', 'name' => 'BRINDE', 'qty' => 1, 'total' => 0.0], ['code' => 'C', 'name' => 'CAPA', 'qty' => 1, 'total' => 50.0]]]);
        $allocations->allocate($gift->items->first(), ['destination' => 'outra_empresa', 'group_company_id' => $company->id], $this->gestor());
        $this->assertSame(0, $this->companies()->overview()['receivable']);
    }

    public function test_o_mesmo_gasto_nao_vira_a_receber_duas_vezes(): void
    {
        $company = $this->rental();
        $supplier = Supplier::query()->where('document', self::SUPPLIER_CNPJ)->first();
        $invoice = app(Invoices::class)->createManual(['supplier_id' => $supplier->id, 'number' => '777', 'issue_date' => '2026-10-01', 'total' => '1000', 'installments' => [['due_date' => '2026-10-25', 'amount' => '1000']]], $this->gestor());
        $r = $this->registerSlip(1000, '2026-10-25', ['payer_cnpj' => self::RENTAL_CNPJ]);
        $this->assertTrue($r['auto']['linked']);
        app(BankSlips::class)->pay($r['slip'], ['bank_checked' => true, 'release' => true, 'reason' => 'Nota digitada, fornecedor confirmou', 'paid_by_party' => 'oficina'], $this->gestor());

        app(Allocations::class)->allocate($invoice->items->first(), ['destination' => 'outra_empresa', 'group_company_id' => $company->id], $this->gestor());
        $this->assertSame(100000, $this->companies()->overview()['receivable']);               // e não 2.000
    }

    public function test_boleto_de_notas_de_empresas_diferentes_e_grave_e_cnpj_da_oficina_nunca_e_do_grupo(): void
    {
        $this->rental();
        $supplier = Supplier::query()->where('document', self::SUPPLIER_CNPJ)->first();
        $ours = app(Invoices::class)->createManual(['supplier_id' => $supplier->id, 'number' => '801', 'issue_date' => '2026-10-01', 'total' => '600', 'recipient_cnpj' => self::WORKSHOP_CNPJ], $this->gestor());
        $theirs = app(Invoices::class)->createManual(['supplier_id' => $supplier->id, 'number' => '802', 'issue_date' => '2026-10-01', 'total' => '400', 'recipient_cnpj' => self::RENTAL_CNPJ], $this->gestor());
        $slip = $this->registerSlip(1000, '2026-10-25', ['payer_cnpj' => ''])['slip'];
        app(BankSlips::class)->link($slip, [['invoice_id' => $ours->id, 'amount' => '600'], ['invoice_id' => $theirs->id, 'amount' => '400']], $this->gestor());

        $this->assertContains(['boleto_notas_de_empresas_misturadas', 'alta'], $this->pairs(app(Occurrences::class)->forSlip($slip)));
        $this->assertNull($this->companies()->companyFor(self::WORKSHOP_CNPJ));
        $this->assertThrows(fn () => app(BankSlips::class)->pay($slip->fresh(), ['bank_checked' => true, 'release' => true, 'reason' => 'Teste de data futura aqui', 'paid_on' => '2026-12-31'], $this->gestor()), DomainException::class, 'futura');
    }

    public function test_atendimento_nao_troca_o_pagador_para_empresa_do_grupo(): void
    {
        $this->rental();
        $this->invoiceOf(920, 300, [['due' => '2026-10-25', 'amount' => 300]]);
        $slip = $this->registerSlip(300, '2026-10-25', [], $this->desk())['slip'];

        $this->assertThrows(fn () => app(BankSlips::class)->update($slip, ['payer_cnpj' => self::RENTAL_CNPJ], $this->desk()), DomainException::class, 'gestor');
        $this->assertNotNull($slip->fresh()->payable_id);
    }

    public function test_peca_da_locadora_na_os_da_oficina_e_acerto_antigo_avisam(): void
    {
        $company = $this->rental();
        $order = $this->order();
        $invoice = $this->importInvoice(['number' => 340, 'recipient' => self::RENTAL_CNPJ, 'items' => [['code' => 'K', 'name' => 'KIT EMBREAGEM', 'qty' => 1, 'total' => 500.0]]]);
        app(Allocations::class)->allocate($invoice->items->first(), ['destination' => 'os', 'service_order_id' => $order->id], $this->desk());

        $occurrence = $this->find('peca_de_empresa_na_os');
        $this->assertSame('media', $occurrence['severity']);
        $this->assertStringContainsString('LOCADORA TESTE', $occurrence['title']);

        $this->companies()->createManual(['group_company_id' => $company->id, 'amount' => '700', 'description' => 'Peças e serviço pagos pela oficina', 'occurred_on' => '2026-08-01'], $this->gestor());
        $this->assertStringContainsString('deve à oficina há 68 dias', $this->find('acerto_antigo')['title']);
        Settings::set('purchases.group_return_days', 90);
        $this->assertNull($this->find('acerto_antigo'));
    }

    public function test_nota_e_boleto_em_nome_de_empresas_diferentes_avisam(): void
    {
        $this->rental();
        $this->invoiceOf(350, 260, [['due' => '2026-10-28', 'amount' => 260]], ['recipient' => self::RENTAL_CNPJ]);

        $this->assertContains('boleto_nota_empresas_diferentes', array_column($this->registerSlip(260, '2026-10-28')['occurrences'], 'type'));
    }

    // ------------------------------------------------------------------ DDA

    public function test_dda_confere_preenche_quem_recebe_acusa_o_que_ninguem_cadastrou_e_dispensa_o_app_do_banco(): void
    {
        $this->invoiceOf(701, 300, [['due' => '2026-10-25', 'amount' => 300]]);
        $lA = $this->slipLine(300, '2026-10-25');             // cadastrado pelo atendimento SEM quem recebe
        $lB = $this->slipLine(410, '2026-10-28');             // cadastrado com um recebedor diferente do banco
        $lC = $this->slipLine(88, '2026-10-29');              // cadastrado, mas o banco não mostra
        $lD = $this->slipLine(999, '2026-10-27');             // o banco mostra, ninguém cadastrou
        $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00'));
        $create = fn (string $line, array $extra = []) => app(BankSlips::class)->create(['line' => $line, 'supplier_name' => self::SUPPLIER_NAME, ...$extra], $this->desk());
        $a = $create($lA);
        $b = $create($lB, ['payee_cnpj' => self::OTHER_CNPJ]);
        $c = $create($lC);
        $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00'));
        $this->assertTrue($a['auto']['linked']);

        // Antes do DDA: A está "não conferido" (falta quem recebe) e o pagamento pede a conferência no app do banco.
        $occurrences = app(Occurrences::class);
        $this->assertSame('atencao', $occurrences->verdict($a['slip']->fresh(), $occurrences->forSlip($a['slip']))['level']);
        $this->assertThrows(fn () => app(BankSlips::class)->pay($a['slip']->fresh(), [], $this->gestor()), DomainException::class);

        $file = $this->cnabDda(self::WORKSHOP_CNPJ, [
            ['barcode' => $this->barcodeOf($lA), 'payee' => self::SUPPLIER_CNPJ, 'payee_name' => self::SUPPLIER_NAME, 'due' => '25102026', 'amount' => 300, 'document' => '000701'],
            ['barcode' => $this->barcodeOf($lB), 'payee' => self::SUPPLIER_CNPJ, 'payee_name' => self::SUPPLIER_NAME, 'due' => '28102026', 'amount' => 410],
            ['barcode' => $this->barcodeOf($lD), 'payee' => self::OTHER_CNPJ, 'payee_name' => 'PECAS DO FULANO ME', 'due' => '27102026', 'amount' => 999],
        ]);
        $this->assertThrows(fn () => app(Dda::class)->import($file, 'dda.rem', null, $this->desk()), DomainException::class, 'gestor');
        $import = app(Dda::class)->import($file, 'dda.rem', null, $this->gestor());
        $this->assertSame([3, 3, 1, 1, 1, 1], [$import['count'], $import['new'], $import['ok'], $import['diverge'], $import['without_registration'], $import['outside']]);

        // A: o banco preencheu quem recebe e quem paga, e o boleto ficou confirmado no DDA.
        $slipA = $a['slip']->fresh();
        $this->assertSame([self::SUPPLIER_CNPJ, self::WORKSHOP_CNPJ], [$slipA->payee_cnpj, $slipA->payer_cnpj]);
        $status = app(Dda::class)->statusBySlip();
        $this->assertSame('confirmado', $status[$slipA->id]);
        $verdict = $occurrences->verdict($slipA, $occurrences->forSlip($slipA), $status[$slipA->id]);
        $this->assertSame('ok', $verdict['level']);
        $this->assertStringContainsString('confirmado no DDA', $verdict['text']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'purchase.slip_filled_by_dda', 'auditable_id' => $slipA->id]);
        app(BankSlips::class)->pay($slipA, [], $this->gestor());              // sem marcar "conferi no app do banco": o DDA é o banco
        $this->assertTrue($slipA->fresh()->isPaid());

        // B: o digitado difere do banco (quem recebe) → grave.
        $this->assertTrue(collect($occurrences->forSlip($b['slip']))->contains(fn ($o) => $o['type'] === 'dda_diverge' && str_contains($o['detail'], 'quem recebe')));
        // C: cadastrado e fora do DDA → para conferir.
        $this->assertContains(['boleto_fora_do_dda', 'media'], $this->pairs($occurrences->forSlip($c['slip'])));
        // D: sem cadastro → grave, com o beneficiário.
        $missing = $this->find('dda_sem_cadastro');
        $this->assertSame(['alta', 99900], [$missing['severity'], $missing['amount_cents']]);
        $this->assertStringContainsString('PECAS DO FULANO ME', $missing['detail']);

        // Importar de novo o mesmo arquivo não duplica; cadastrar o que faltava resolve o alerta.
        $this->assertSame(0, app(Dda::class)->import($file, null, null, $this->gestor())['new']);
        $this->assertSame(3, DdaTitle::count());
        app(BankSlips::class)->create(['line' => $lD, 'supplier_name' => 'PECAS DO FULANO'], $this->gestor());
        $this->assertNull($this->find('dda_sem_cadastro'));
    }

    public function test_dda_de_outro_cnpj_e_recusado_e_o_da_locadora_fica_separado(): void
    {
        $line = $this->slipLine(120, '2026-10-30');
        $title = ['barcode' => $this->barcodeOf($line), 'payee' => self::SUPPLIER_CNPJ, 'payee_name' => self::SUPPLIER_NAME, 'due' => '30102026', 'amount' => 120];

        $this->assertThrows(fn () => app(Dda::class)->import($this->cnabDda(self::OTHER_CNPJ, [$title]), null, null, $this->gestor()), DomainException::class, 'nem de uma empresa cadastrada');
        $company = $this->rental();
        app(Dda::class)->import($this->cnabDda(self::RENTAL_CNPJ, [$title]), null, null, $this->gestor());         // o cabeçalho manda: é da locadora
        $this->assertThrows(fn () => app(Dda::class)->import($this->cnabDda(self::RENTAL_CNPJ, [$title]), null, 999, $this->gestor()), DomainException::class, 'Empresa não encontrada');

        $cross = app(Dda::class)->cross();
        $this->assertSame([$company->id], array_map(fn ($i) => $i->group_company_id, $cross['imports']));
        $this->assertSame($company->id, $cross['without_registration'][0]['scope']);

        // O boleto, cadastrado depois como da oficina, casa com o título da locadora: divergência de empresa.
        $slip = app(BankSlips::class)->create(['line' => $line, 'supplier_name' => self::SUPPLIER_NAME], $this->gestor())['slip'];
        $this->assertTrue(collect(app(Occurrences::class)->forSlip($slip))->contains(fn ($o) => $o['type'] === 'dda_diverge' && str_contains($o['detail'], 'outro CNPJ')));
    }

    public function test_dda_antigo_vira_lembrete_e_boleto_novo_ou_de_convenio_nao_e_cobrado(): void
    {
        app(Dda::class)->import($this->cnabDda(self::WORKSHOP_CNPJ, [['barcode' => $this->barcodeOf($this->slipLine(50, '2026-10-30')), 'payee' => self::SUPPLIER_CNPJ, 'due' => '30102026', 'amount' => 50]]), null, null, $this->gestor());
        $supplier = Supplier::query()->first();
        BankSlip::create(['supplier_id' => $supplier->id, 'barcode' => $this->barcodeOf($this->slipLine(77, '2026-10-30')), 'amount_cents' => 7700, 'due_date' => '2026-10-30', 'status' => 'aberto']);        // cadastrado hoje
        $convenio = BankSlip::create(['supplier_id' => $supplier->id, 'barcode' => '84670000001435900240200240359402975401440015', 'amount_cents' => 14359, 'due_date' => '2026-10-30', 'status' => 'aberto']);
        $convenio->forceFill(['created_at' => '2026-10-01 10:00:00'])->save();

        $this->assertSame([], app(Dda::class)->cross()['outside']);

        $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00'));
        $this->assertSame('baixa', $this->find('dda_desatualizado')['severity']);
        $this->travelTo(CarbonImmutable::parse('2026-10-30 10:00'));
        $this->assertSame('media', $this->find('dda_desatualizado')['severity']);
        $this->assertSame([], app(Dda::class)->cross()['outside']);
    }

    public function test_sem_dda_importado_nenhuma_ocorrencia_de_dda(): void
    {
        $this->registerSlip(100, '2026-10-30');

        $this->assertFalse(collect(app(Occurrences::class)->all())->contains(fn ($o) => str_starts_with($o['type'], 'dda_') || $o['type'] === 'boleto_fora_do_dda'));
    }

    public function test_cnab_com_um_lote_por_cnpj_separa_os_titulos_e_lote_estranho_nao_derruba(): void
    {
        $company = $this->rental();
        $t = fn (float $amount, string $due, string $ddmm) => ['barcode' => $this->barcodeOf($this->slipLine($amount, $due)), 'payee' => self::SUPPLIER_CNPJ, 'due' => $ddmm, 'amount' => $amount];
        $file = $this->cnabDda(self::WORKSHOP_CNPJ, lots: [['cnpj' => self::WORKSHOP_CNPJ, 'titles' => [$t(100, '2026-10-30', '30102026')]], ['cnpj' => self::RENTAL_CNPJ, 'titles' => [$t(200, '2026-10-31', '31102026')]]]);

        $r = app(Dda::class)->import($file, null, $company->id, $this->gestor());       // a escolha não vale com mais de um CNPJ no arquivo
        $this->assertEqualsCanonicalizing([null, $company->id], array_column($r['imports'], 'company'));
        $this->assertSame([1, 1], [DdaTitle::query()->where('scope', 0)->count(), DdaTitle::query()->where('scope', $company->id)->count()]);

        $mixed = $this->cnabDda(self::WORKSHOP_CNPJ, lots: [['cnpj' => self::WORKSHOP_CNPJ, 'titles' => [$t(55, '2026-11-02', '02112026')]], ['cnpj' => self::UNKNOWN_CNPJ, 'titles' => [$t(66, '2026-11-03', '03112026')]]]);
        $warnings = app(Dda::class)->import($mixed, null, null, $this->gestor())['warnings'];
        $this->assertNotEmpty(preg_grep('/Ignorei 1 título/', $warnings));
    }

    public function test_valor_mexido_no_codigo_com_o_mesmo_campo_livre_e_o_mesmo_titulo_divergente(): void
    {
        $real = $this->slipLine(300, '2026-10-25', '341', 1234567);
        $tampered = $this->slipLine(3000, '2026-10-25', '341', 1234567);           // mesmo campo livre, valor 10x
        $slip = app(BankSlips::class)->create(['line' => $tampered, 'supplier_name' => self::SUPPLIER_NAME, 'payee_cnpj' => self::SUPPLIER_CNPJ, 'payer_cnpj' => self::WORKSHOP_CNPJ], $this->desk())['slip'];
        app(Dda::class)->import($this->cnabDda(self::WORKSHOP_CNPJ, [['barcode' => $this->barcodeOf($real), 'payee' => self::SUPPLIER_CNPJ, 'due' => '25102026', 'amount' => 300]]), null, null, $this->gestor());

        $divergence = collect(app(Occurrences::class)->forSlip($slip))->firstWhere('type', 'dda_diverge');
        $this->assertStringContainsString('valor: cadastrado R$ 3.000,00, banco R$ 300,00', $divergence['detail']);
        $cross = app(Dda::class)->cross();
        $this->assertSame([0, 1], [count($cross['without_registration']), count($cross['diverge'])]);
        $this->assertNotSame('confirmado', app(Dda::class)->statusBySlip($cross)[$slip->id] ?? null);
    }

    public function test_dda_sem_codigo_ou_csv_casa_mas_nao_dispensa_o_app_do_banco_e_cnab_recente_dispensa(): void
    {
        $this->invoiceOf(810, 450, [['due' => '2026-10-26', 'amount' => 450]]);
        $line = $this->slipLine(450, '2026-10-26');
        $slip = app(BankSlips::class)->create(['line' => $line, 'supplier_name' => self::SUPPLIER_NAME, 'payee_cnpj' => self::SUPPLIER_CNPJ, 'payer_cnpj' => self::WORKSHOP_CNPJ], $this->desk())['slip'];

        $r = app(Dda::class)->import("Beneficiário;CNPJ;Vencimento;Valor\n\"DISTRIBUIDORA TESTE LTDA\";11.222.333/0001-81;26/10/2026;\"450,00\"\n", 'dda.csv', null, $this->gestor());
        $this->assertSame([1, 0], [$r['ok'], $r['without_registration']]);
        $this->assertSame('no_dda', app(Dda::class)->statusBySlip()[$slip->id]);
        $this->assertThrows(fn () => app(BankSlips::class)->pay($slip->fresh(), [], $this->gestor()), DomainException::class);

        app(Dda::class)->import("Beneficiário;CNPJ;Vencimento;Valor;Linha digitável\n\"DISTRIBUIDORA TESTE LTDA\";11.222.333/0001-81;26/10/2026;\"450,00\";{$line}\n", null, null, $this->gestor());
        $this->assertSame('no_dda', app(Dda::class)->statusBySlip()[$slip->id]);                // CSV com o código: ainda não dispensa

        app(Dda::class)->import($this->cnabDda(self::WORKSHOP_CNPJ, [['barcode' => $this->barcodeOf($line), 'payee' => self::SUPPLIER_CNPJ, 'due' => '26102026', 'amount' => 450]]), null, null, $this->gestor());
        $this->assertSame('confirmado', app(Dda::class)->statusBySlip()[$slip->id]);

        // Fornecedor que o gestor não conferiu ou arquivo gerado há muito tempo não confirmam.
        Supplier::query()->update(['confirmed_at' => null]);
        $this->assertSame('no_dda', app(Dda::class)->statusBySlip()[$slip->id]);
        Supplier::query()->update(['confirmed_at' => now()]);
        DdaImport::query()->update(['generated_on' => '2026-08-01']);
        $this->assertSame('no_dda', app(Dda::class)->statusBySlip()[$slip->id]);
    }

    public function test_cnpj_das_notas_que_o_atendimento_importa_nao_confirma_no_dda(): void
    {
        $this->invoiceOf(970, 8000, [['due' => '2026-10-25', 'amount' => 8000]], ['issuer' => self::OTHER_CNPJ, 'issuer_name' => 'PECAS FALSAS LTDA'], $this->desk());
        $line = $this->slipLine(8000, '2026-10-25');
        $slip = app(BankSlips::class)->create(['line' => $line, 'supplier_name' => 'PECAS FALSAS LTDA', 'payee_cnpj' => self::OTHER_CNPJ], $this->desk())['slip'];
        app(Dda::class)->import($this->cnabDda(self::WORKSHOP_CNPJ, [['barcode' => $this->barcodeOf($line), 'payee' => self::OTHER_CNPJ, 'payee_name' => 'PECAS FALSAS LTDA', 'due' => '25102026', 'amount' => 8000]]), null, null, $this->gestor());

        $this->assertNotSame('confirmado', app(Dda::class)->statusBySlip()[$slip->id] ?? null);
        $this->assertThrows(fn () => app(BankSlips::class)->pay($slip->fresh(), [], $this->gestor()), DomainException::class);       // fornecedor nunca conferido
        $this->assertSame(Document::format(self::OTHER_CNPJ), Document::format($slip->payee_cnpj));
    }
}
