<?php

namespace Tests\Feature\Purchases;

use App\Enums\Stage;
use App\Models\BankSlip;
use App\Models\GroupCompany;
use App\Models\GroupSettlement;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Purchases\PurchaseSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Concerns\BuildsOrders;
use Tests\Concerns\BuildsPurchases;
use Tests\TestCase;

/** Telas da conferência de compras (Financeiro > Compras). */
class PurchaseScreensTest extends TestCase
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

    public function test_conferencia_mostra_o_boleto_sem_nota_e_o_gestor_aceita_com_motivo(): void
    {
        $r = $this->registerSlip(150, '2026-10-20');

        Livewire::actingAs($this->desk())->test('pages::auth.finance.purchases.index')
            ->assertSee('Conferência de compras')
            ->assertSee('data-test="ocorrencia-boleto_sem_nota"', false)
            ->assertDontSee('data-test="aceitar-boleto_sem_nota"', false);

        Livewire::actingAs($this->gestor())->test('pages::auth.finance.purchases.index')
            ->assertSee('data-test="aceitar-boleto_sem_nota"', false)
            ->call('startAccept', 'boleto_sem_nota:boleto:'.$r['slip']->id)
            ->assertSee('data-test="janela-aceite"', false)
            ->set('acceptReason', 'curto')->call('accept')->assertHasErrors('acceptReason')
            ->set('acceptReason', 'Serviço sem nota, combinado com o fornecedor')->call('accept')
            ->assertHasNoErrors()->assertSet('acceptKey', null)
            ->assertSee('Conferidas e aceitas (1)');
    }

    public function test_notas_importa_xml_e_lanca_nota_sem_xml(): void
    {
        $this->actingAs($this->desk());
        $file = UploadedFile::fake()->createWithContent('nota.xml', $this->nfeXml(['number' => 321, 'items' => [['code' => 'A1', 'name' => 'FILTRO', 'qty' => 1, 'total' => 80]], 'installments' => [['due' => '2026-10-20', 'amount' => 80]]]));

        Livewire::test('pages::auth.finance.purchases.invoices')
            ->call('openImport')
            ->set('xmlFiles', [$file])
            ->call('import')
            ->assertHasNoErrors()
            ->assertSee('data-test="resultado-importacao"', false)
            ->assertSee('importada');

        $invoice = PurchaseInvoice::query()->where('number', '321')->firstOrFail();
        $this->assertTrue($invoice->hasXml());

        Livewire::test('pages::auth.finance.purchases.invoices')
            ->call('open', $invoice->id)
            ->assertSee('data-test="janela-nota"', false)
            ->assertSee('FILTRO')
            ->assertSee('Abrir o portal da NF-e');

        Livewire::test('pages::auth.finance.purchases.invoices')
            ->call('openManual')
            ->set('form.supplier_id', 'novo')
            ->assertSet('newSupplier', true)
            ->set('form.supplier_name', 'AUTOPECAS INVENTADA')
            ->set('form.issuer_cnpj', '44.555.666/0001-81')
            ->set('form.number', '77')
            ->set('form.issue_date', '2026-10-05')
            ->set('form.total', '199,90')
            ->call('saveManual')
            ->assertHasNoErrors();

        $manual = PurchaseInvoice::query()->where('number', '77')->firstOrFail();
        $this->assertSame(19990, $manual->total_cents);
        $this->assertSame(PurchaseInvoice::SOURCE_MANUAL, $manual->source);
    }

    public function test_boletos_cadastra_pela_linha_liga_a_nota_e_o_gestor_paga_com_a_conferencia_do_banco(): void
    {
        $invoice = $this->invoiceOf(500, 320);
        $line = $this->slipLine(320, '2026-10-20');

        $component = Livewire::actingAs($this->desk())->test('pages::auth.finance.purchases.slips')
            ->call('openCreate')
            ->set('text', "Beneficiário: DISTRIBUIDORA TESTE LTDA CNPJ 11.222.333/0001-81\nPagador: OFICINA TESTE CNPJ 55.666.777/0001-81\n{$line}")
            ->call('readText')
            ->assertSee('data-test="leitura-boleto"', false)
            ->assertSet('form.amount', '320,00')
            ->assertSet('form.due_date', '2026-10-20')
            ->call('save')
            ->assertHasNoErrors();

        $slip = BankSlip::query()->firstOrFail();
        $this->assertSame(self::SUPPLIER_CNPJ, $slip->payee_cnpj);
        $this->assertSame(self::WORKSHOP_CNPJ, $slip->payer_cnpj);
        $this->assertSame([$invoice->id], $slip->links()->pluck('purchase_invoice_id')->all());
        $component->assertSet('slipId', $slip->id)->assertSee('data-test="janela-boleto"', false)->assertDontSee('data-test="pagar"', false);

        Livewire::actingAs($this->gestor())->withQueryParams(['boleto' => $slip->id])->test('pages::auth.finance.purchases.slips')
            ->assertSee('data-test="veredito"', false)
            ->assertSet('edit.payee_cnpj', '11.222.333/0001-81')
            ->call('startPay')
            ->call('confirmPay')
            ->assertHasErrors('pay')
            ->set('pay.bank_checked', true)
            ->call('confirmPay')
            ->assertHasNoErrors();

        $slip->refresh();
        $this->assertTrue($slip->isPaid());
        $this->assertTrue($slip->payable->isPaid());
        $this->assertNotNull($slip->bank_checked_on);
    }

    public function test_boleto_sem_nota_so_e_pago_com_motivo_e_o_historico_registra(): void
    {
        $slip = $this->registerSlip(150, '2026-10-20', [], $this->desk())['slip'];

        Livewire::actingAs($this->gestor())->test('pages::auth.finance.purchases.slips')
            ->assertSee('NÃO PAGUE')
            ->call('open', $slip->id)
            ->assertSee('data-test="ocorrencia-boleto_sem_nota"', false)
            ->call('startPay')
            ->assertSee('data-test="pagar-mesmo-assim"', false)
            ->set('pay.bank_checked', true)
            ->call('confirmPay')
            ->assertHasErrors('pay')
            ->set('pay.release', true)->set('pay.reason', 'Serviço do fornecedor, a nota chega amanhã')
            ->call('confirmPay')
            ->assertHasNoErrors()
            ->assertSee('Boleto pago com ressalva');

        $this->assertTrue($slip->fresh()->isPaid());
        $this->assertStringContainsString('a nota chega amanhã', (string) $slip->fresh()->released_reason);
    }

    public function test_boletos_varios_de_uma_vez_e_ligacao_manual(): void
    {
        $invoice = $this->invoiceOf(610, 500, [['due' => '2026-10-20', 'amount' => 250], ['due' => '2026-11-20', 'amount' => 250]]);
        $lines = $this->slipLine(250, '2026-10-20')."\n".$this->slipLine(99, '2026-10-25')."\n123";
        $supplier = Supplier::query()->where('document', self::SUPPLIER_CNPJ)->firstOrFail();

        Livewire::actingAs($this->desk())->test('pages::auth.finance.purchases.slips')
            ->call('openBatch')
            ->set('batchSupplierId', (string) $supplier->id)
            ->set('batchText', $lines)
            ->call('saveBatch')
            ->assertSee('data-test="resultado-varios"', false)
            ->assertSee('ligado à nota');

        $this->assertSame(2, BankSlip::query()->count());
        $loose = BankSlip::query()->where('amount_cents', 9900)->firstOrFail();

        Livewire::actingAs($this->desk())->test('pages::auth.finance.purchases.slips')
            ->set('filter', 'sem_nota')
            ->assertSee('data-test="boleto-'.$loose->id.'"', false)
            ->call('open', $loose->id)
            ->set('linkInvoiceId', (string) $invoice->id)
            ->assertSet('linkAmount', '99,00')
            ->call('link')
            ->assertHasNoErrors();

        $this->assertSame(9900, (int) $loose->links()->sum('amount_cents'));
    }

    public function test_notas_da_destino_a_peca_na_os_e_o_custo_vem_da_nota(): void
    {
        $invoice = $this->invoiceOf(700, 301);                     // 2 unidades por R$ 301
        $item = $invoice->items()->firstOrFail();
        $order = $this->order(Stage::EmExecucao);
        $part = $this->item($order);

        Livewire::actingAs($this->desk())->withQueryParams(['nota' => $invoice->id])->test('pages::auth.finance.purchases.invoices')
            ->assertSee('data-test="janela-nota"', false)
            ->call('startAllocate', $item->id)
            ->assertSee('data-test="form-destino"', false)
            ->set('alloc.order_search', (string) $order->number)
            ->assertSee('data-test="escolher-os-'.$order->number.'"', false)
            ->call('pickOrder', $order->id)
            ->set('alloc.os_item_id', (string) $part->id)
            ->set('alloc.quantity', '1')
            ->call('allocate')
            ->assertHasNoErrors()
            ->assertSet('allocItemId', null)
            ->assertSee('1 un sem destino');

        $this->assertSame(15050, $part->fresh()->cost_cents);
    }

    public function test_atendimento_nao_ve_lucro_nem_margem_nas_telas_de_compras(): void
    {
        $this->invoiceOf(500, 320);
        $this->registerSlip(320, '2026-10-20');
        $desk = $this->desk();

        foreach (['finance.purchases', 'finance.purchases.invoices', 'finance.purchases.slips', 'finance.purchases.suppliers'] as $route) {
            $this->actingAs($desk)->get(route($route))->assertOk()->assertDontSee('Lucro')->assertDontSee('lucro')->assertDontSee('argem')
                ->assertSee('data-test="menu-compras"', false)->assertDontSee(route('finance.purchases.dda'));
        }
    }

    public function test_quem_entra_em_cada_tela(): void
    {
        $mechanic = User::factory()->mecanico()->create();
        $desk = $this->desk();
        $gestor = $this->gestor();

        foreach (['finance.purchases', 'finance.purchases.invoices', 'finance.purchases.slips', 'finance.purchases.suppliers', 'finance.purchases.group', 'finance.purchases.dda', 'settings.purchases'] as $route) {
            $this->actingAs($mechanic)->get(route($route))->assertForbidden();
            $this->actingAs($gestor)->get(route($route))->assertOk();
        }

        foreach (['finance.purchases.group', 'finance.purchases.dda', 'settings.purchases'] as $route) {
            $this->actingAs($desk)->get(route($route))->assertForbidden();
        }

        $this->actingAs($gestor)->get(route('finance'))->assertSee(route('finance.purchases'));
        $this->actingAs($gestor)->get(route('settings'))->assertSee('data-test="ajustes-compras"', false);
    }

    public function test_a_pagar_mostra_o_selo_da_conferencia_e_leva_ao_boleto(): void
    {
        $slip = $this->registerSlip(150, '2026-10-20')['slip'];

        Livewire::withQueryParams(['mes' => 'todos'])->actingAs($this->gestor())->test('pages::auth.finance.payables')
            ->assertSee('data-test="selo-compras-'.$slip->payable_id.'"', false)
            ->assertSee('NÃO PAGUE')
            ->call('edit', $slip->payable_id)
            ->assertSee('data-test="conferencia-compras"', false)
            ->call('markPaid', $slip->payable_id);

        $this->assertFalse($slip->fresh()->isPaid());
    }

    public function test_fornecedores_o_gestor_confere_e_autoriza_recebedor(): void
    {
        $supplier = $this->purchaseScene(false);
        $supplier->update(['confirmed_at' => null]);
        $this->registerSlip(150, '2026-10-20');

        Livewire::actingAs($this->desk())->test('pages::auth.finance.purchases.suppliers')
            ->assertSee(self::SUPPLIER_NAME)->assertSee('falta conferir')
            ->assertDontSee('data-test="conferir-'.$supplier->id.'"', false);

        Livewire::actingAs($this->gestor())->test('pages::auth.finance.purchases.suppliers')
            ->call('confirm', $supplier->id, true)
            ->assertSee('conferido')
            ->call('startPayees', $supplier->id)
            ->set('payees', '11.222.333/0001-99')
            ->call('savePayees')
            ->assertHasErrors('payees')
            ->set('payees', '11.222.333/0002-62')
            ->call('savePayees')
            ->assertHasNoErrors();

        $supplier->refresh();
        $this->assertTrue($supplier->isConfirmed());
        $this->assertSame([self::SUPPLIER_BRANCH_CNPJ], $supplier->authorizedPayees());
    }

    public function test_grupo_cadastra_empresa_lanca_acerto_e_devolucao(): void
    {
        $component = Livewire::actingAs($this->gestor())->test('pages::auth.finance.purchases.group')
            ->set('addingCompany', true)
            ->set('company.name', 'LOCADORA TESTE')
            ->set('company.cnpj', self::WORKSHOP_CNPJ)
            ->call('saveCompany')
            ->assertHasErrors('company')
            ->set('company.cnpj', '66.777.888/0001-81')
            ->call('saveCompany')
            ->assertHasNoErrors();

        $company = GroupCompany::query()->where('cnpj', self::RENTAL_CNPJ)->firstOrFail();

        $component->call('startSettlement', $company->id)
            ->set('settlement.amount', '100')
            ->set('settlement.description', 'Pneus para a frota')
            ->call('saveSettlement')
            ->assertHasNoErrors();

        $settlement = GroupSettlement::query()->firstOrFail();
        $this->assertSame(10000, $settlement->amount_cents);

        $component->call('startReturn', $settlement->id)
            ->assertSet('return.amount', '100,00')
            ->set('return.amount', '40')
            ->call('saveReturn')
            ->assertHasNoErrors()
            ->assertSee('falta R$ 60,00');
    }

    public function test_dda_importa_o_arquivo_e_mostra_o_que_o_banco_tem_e_ninguem_cadastrou(): void
    {
        $slip = $this->registerSlip(320, '2026-10-20')['slip'];
        $intruder = $this->barcodeOf($this->slipLine(77, '2026-10-25'));
        $content = $this->cnabDda(self::WORKSHOP_CNPJ, [
            ['barcode' => $slip->barcode, 'payee' => self::SUPPLIER_CNPJ, 'payee_name' => self::SUPPLIER_NAME, 'due' => '20102026', 'amount' => 320],
            ['barcode' => $intruder, 'payee' => self::UNKNOWN_CNPJ, 'payee_name' => 'BENEFICIARIO DESCONHECIDO', 'due' => '25102026', 'amount' => 77],
        ]);

        Livewire::actingAs($this->gestor())->test('pages::auth.finance.purchases.dda')
            ->assertSee('data-test="sem-dda"', false)
            ->set('file', UploadedFile::fake()->createWithContent('dda.txt', $content))
            ->call('import')
            ->assertHasNoErrors()
            ->assertSee('data-test="resultado-dda"', false)
            ->assertSee('data-test="dda-sem-cadastro"', false)
            ->assertSee('BENEFICIARIO DESCONHECIDO')
            ->assertSee('data-test="dda-batem"', false);

        Livewire::actingAs($this->gestor())->test('pages::auth.finance.purchases.dda')
            ->call('import')
            ->assertHasErrors('dda');
    }

    public function test_ajustes_de_compras_grava_os_parametros_em_reais_e_dias(): void
    {
        Livewire::actingAs($this->gestor())->test('pages::auth.settings.purchases')
            ->assertSet('values.tolerance_cents', '0,05')
            ->assertSee('data-test="cnpj-oficina"', false)
            ->set('values.tolerance_cents', '20')
            ->call('save')
            ->assertHasErrors('values.tolerance_cents')
            ->set('values.tolerance_cents', '0,10')
            ->set('values.allocation_days', '10')
            ->set('auditSince', '2026-09-01')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(10, PurchaseSettings::toleranceCents());
        $this->assertSame(10, PurchaseSettings::allocationDays());
        $this->assertSame('2026-09-01', PurchaseSettings::auditSince('2026-10-08'));
    }
}
