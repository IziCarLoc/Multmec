<?php

namespace Tests\Feature\Purchases;

use App\Models\AuditLog;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Services\Purchases\BankSlips;
use App\Services\Purchases\Invoices;
use App\Services\Purchases\Occurrences;
use App\Services\Purchases\PurchaseSuppliers;
use App\Support\Purchases\AccessKey;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPurchases;
use Tests\TestCase;

/**
 * Porte da parte de notas de `app/test/nfe.test.js`, `compras.test.js`, `nota_sem_xml.test.js`, `revisao_compras.test.js`
 * e `revisao_rodada2.test.js`: importar o XML, nota digitada, chave, consulta no portal e a prova da nota.
 */
class InvoicesTest extends TestCase
{
    use BuildsPurchases;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00'));
        $this->purchaseScene();
    }

    private function invoices(): Invoices
    {
        return app(Invoices::class);
    }

    private function manual(array $data, $by = null): PurchaseInvoice
    {
        return $this->invoices()->createManual($data + ['supplier_name' => self::SUPPLIER_NAME, 'issue_date' => '2026-10-01'], $by ?? $this->gestor());
    }

    public function test_importar_cria_nota_parcelas_e_itens_com_o_frete_rateado_e_nao_duplica(): void
    {
        $xml = $this->nfeXml(['number' => 77, 'freight' => 30.0, 'installments' => [['due' => '2026-10-31', 'amount' => 330]], 'items' => [
            ['code' => 'P1', 'name' => 'PASTILHA DE FREIO DIANTEIRA', 'qty' => 1, 'total' => 120.0, 'order' => '1148'],
            ['code' => 'D2', 'name' => 'DISCO DE FREIO', 'qty' => 2, 'total' => 180.0],
        ]]);
        $first = $this->invoices()->importXml($xml, $this->gestor());

        $this->assertSame('importada', $first['status']);
        $invoice = PurchaseInvoice::findOrFail($first['invoice_id']);
        $this->assertSame(33000, $invoice->total_cents);
        $this->assertSame(self::SUPPLIER_CNPJ, $invoice->supplier->document);
        $this->assertSame(33000, (int) $invoice->items()->sum('cost_cents'));      // o frete entra no custo das peças (o MariaDB devolve a soma como texto)
        $this->assertSame('gestor', $invoice->created_role);
        $this->assertSame($xml, $invoice->originalXml());                            // o XML original fica guardado, igual ao que entrou

        $this->assertSame('ja_existia', $this->invoices()->importXml($xml, $this->gestor())['status']);
        $this->assertSame(1, PurchaseInvoice::count());
        $changed = $this->invoices()->importXml(str_replace('<xNome>OFICINA TESTE LTDA</xNome>', '<xNome>OUTRO NOME</xNome>', $xml), $this->gestor());
        $this->assertSame('ja_existia', $changed['status']);
        $this->assertStringContainsString('DIFERENTE', $changed['warnings'][0]);

        // O mesmo arquivo com BOM e outras quebras de linha não dá alarme falso.
        $this->assertSame([], $this->invoices()->importXml("\xEF\xBB\xBF".str_replace('><', ">\r\n  <", $xml), $this->gestor())['warnings']);
    }

    public function test_lote_importa_bons_avisa_repetido_e_recusa_adulterado(): void
    {
        $good = $this->nfeXml(['number' => 4321, 'installments' => [['due' => '2026-10-30', 'amount' => 100]]]);
        $results = $this->invoices()->importMany(['a.xml' => $good, 'b.xml' => $good, 'c.xml' => str_replace('<nNF>4321</nNF>', '<nNF>4999</nNF>', $good)], $this->desk());

        $this->assertSame(['importada', 'ja_existia', 'erro'], array_column($results, 'status'));
        $this->assertStringContainsString('não batem com a chave', $results[2]['error']);
    }

    public function test_xml_de_cancelamento_marca_a_nota_como_cancelada_e_nao_se_desfaz_a_mao(): void
    {
        $key = $this->nfeKey(['number' => 88]);
        $invoice = $this->importInvoice(['number' => 88, 'key' => $key]);

        $this->assertThrows(fn () => $this->invoices()->importXml($this->cancellationXml($this->nfeKey(['number' => 99])), $this->gestor()), DomainException::class, 'ainda não foi importada');
        $this->assertSame('cancelada', $this->invoices()->importXml($this->cancellationXml($key), $this->gestor())['status']);
        $this->assertSame(PurchaseInvoice::CANCELED, $invoice->fresh()->status);
        $this->assertThrows(fn () => $this->invoices()->setStatus($invoice->fresh(), 'ativa', 'quero reativar mesmo assim', $this->gestor()), DomainException::class, 'cancelada pela SEFAZ');

        $byProtocol = $this->importInvoice(['number' => 591, 'status' => '101']);
        $this->assertSame('sefaz', $byProtocol->canceled_source);
        $this->assertThrows(fn () => $this->invoices()->importXml($this->cancellationXml($this->nfeKey(['number' => 592]), false), $this->gestor()), DomainException::class, 'não traz a resposta da SEFAZ');
    }

    public function test_nota_digitada_sem_itens_vira_pecas_total_parcelas_fecham_e_chave_e_validada(): void
    {
        $invoice = $this->manual(['supplier_name' => 'Auto Peças do Zé', 'number' => '000321', 'issue_date' => '2026-10-05', 'total' => '450', 'installments' => [['due_date' => '2026-10-30', 'amount' => '450']]]);

        $this->assertSame('321', $invoice->number);
        $this->assertSame([[45000, 'Peças (total da nota)']], $invoice->items->map(fn ($i) => [$i->cost_cents, $i->description])->all());
        $this->assertSame(PurchaseInvoice::SOURCE_MANUAL, $invoice->source);

        $this->assertThrows(fn () => $this->manual(['supplier_name' => 'Auto Peças do Zé', 'number' => '322', 'total' => '450', 'installments' => [['due_date' => '2026-10-30', 'amount' => '400']]]), DomainException::class, 'parcelas somam');
        $this->assertThrows(fn () => $this->manual(['supplier_name' => 'Auto Peças do Zé', 'number' => '321', 'total' => '450']), DomainException::class, 'Já existe');
        $this->assertThrows(fn () => $this->manual(['supplier_name' => 'FORNECEDOR X', 'number' => '5', 'total' => '10', 'key' => '123']), DomainException::class, '44 caracteres');
    }

    public function test_chave_digitada_precisa_dizer_o_mesmo_que_a_nota(): void
    {
        $base = ['issue_date' => '2026-10-05', 'total' => '100'];
        $key = $this->nfeKey(['number' => 1234, 'series' => 1]);

        $this->assertThrows(fn () => $this->manual([...$base, 'number' => '999', 'series' => '1', 'key' => $key]), DomainException::class, 'número dentro da chave');
        $this->assertThrows(fn () => $this->manual([...$base, 'number' => '1234', 'series' => '7', 'key' => $key]), DomainException::class, 'série dentro da chave');
        $this->assertThrows(fn () => $this->manual([...$base, 'number' => '1234', 'series' => '1', 'key' => $key, 'issue_date' => '2026-03-05']), DomainException::class, 'mês dentro da chave');
        $this->assertThrows(fn () => $this->manual([...$base, 'supplier_name' => 'OUTRA EMPRESA', 'issuer_cnpj' => self::OTHER_CNPJ, 'number' => '1234', 'series' => '1', 'key' => $key]), DomainException::class, 'outra empresa');

        $this->assertNotNull($this->manual([...$base, 'number' => '1234', 'series' => '1', 'key' => $key])->id);
        $this->assertThrows(fn () => $this->manual([...$base, 'number' => '1234', 'series' => '1']), DomainException::class, 'Já existe');
        $this->assertNotNull($this->manual([...$base, 'number' => '1234', 'series' => '2'])->id);          // outra série é outra nota
    }

    public function test_pedido_recusado_nao_deixa_fornecedor_criado(): void
    {
        $before = Supplier::count();
        $bad = substr($this->nfeKey(['number' => 4800, 'cnpj' => self::OTHER_CNPJ]), 0, 43);
        $bad .= (string) ((AccessKey::checkDigit($bad) + 1) % 10);

        $this->assertThrows(fn () => $this->manual(['supplier_name' => 'FORNECEDOR FANTASMA', 'number' => '4800', 'series' => '1', 'total' => '10', 'key' => $bad]), DomainException::class);
        $this->assertSame($before, Supplier::count());
    }

    public function test_xml_que_chega_depois_completa_a_nota_digitada(): void
    {
        $this->manual(['issuer_cnpj' => self::SUPPLIER_CNPJ, 'number' => '4321', 'total' => '300', 'installments' => [['due_date' => '2026-10-31', 'amount' => '300']]]);
        $r = $this->invoices()->importXml($this->nfeXml(['number' => 4321, 'installments' => [['due' => '2026-10-31', 'amount' => 300]], 'items' => [
            ['code' => 'A', 'name' => 'PASTILHA', 'qty' => 1, 'total' => 120.0], ['code' => 'B', 'name' => 'DISCO', 'qty' => 2, 'total' => 180.0],
        ]]), $this->gestor());

        $this->assertSame('atualizada', $r['status']);
        $this->assertSame(1, PurchaseInvoice::count());
        $invoice = PurchaseInvoice::findOrFail($r['invoice_id']);
        $this->assertSame(PurchaseInvoice::SOURCE_XML, $invoice->source);
        $this->assertSame(44, strlen($invoice->access_key));
        $this->assertSame(2, $invoice->items()->count());

        // Valor diferente: não mexe, avisa.
        $this->manual(['number' => '555', 'total' => '100']);
        $divergent = $this->invoices()->importXml($this->nfeXml(['number' => 555, 'items' => [['code' => 'A', 'name' => 'X', 'qty' => 1, 'total' => 250.0]]]), $this->gestor());
        $this->assertSame('divergente_manual', $divergent['status']);
        $this->assertSame(10000, PurchaseInvoice::query()->where('number', '555')->value('total_cents'));

        // Série "001" e "1" são a mesma.
        $key = $this->nfeKey(['number' => 4700]);
        $typed = $this->manual(['number' => '4700', 'series' => '001', 'total' => '400', 'key' => $key]);
        $this->assertThrows(fn () => $this->manual(['number' => '4700', 'series' => '1', 'total' => '400']), DomainException::class, 'Já existe');
        $completed = $this->invoices()->importXml($this->nfeXml(['number' => 4700, 'items' => [['code' => 'A', 'name' => 'PEÇA', 'qty' => 1, 'total' => 400.0]]]), $this->gestor());
        $this->assertSame(['atualizada', $typed->id], [$completed['status'], $completed['invoice_id']]);
    }

    public function test_mesmo_nome_com_cnpj_diferente_nao_funde_e_cnpj_novo_desfaz_a_conferencia(): void
    {
        $this->importInvoice(['number' => 1, 'issuer_name' => 'AUTO PECAS CENTRAL', 'issuer' => '12345678000195']);
        $this->importInvoice(['number' => 2, 'issuer_name' => 'AUTO PECAS CENTRAL', 'issuer' => self::OTHER_CNPJ]);
        $suppliers = Supplier::query()->where('name', 'like', 'AUTO PECAS CENTRAL%')->orderBy('id')->get();

        $this->assertCount(2, $suppliers);
        $this->assertSame('AUTO PECAS CENTRAL (44555666)', $suppliers[1]->name);

        $withoutCnpj = Supplier::create(['name' => 'FORNECEDOR SEM CNPJ', 'kind' => 'pecas', 'active' => true, 'confirmed_at' => now()->subDay()]);
        $same = app(PurchaseSuppliers::class)->ensure('Fornecedor sem cnpj', self::RENTAL_CNPJ);
        $this->assertSame($withoutCnpj->id, $same->id);
        $this->assertSame(self::RENTAL_CNPJ, $same->fresh()->document);
        $this->assertNull($same->fresh()->confirmed_at);
    }

    public function test_so_a_consulta_do_gestor_com_o_mesmo_valor_vira_prova(): void
    {
        $key = $this->nfeKey(['number' => 4321]);
        $invoice = $this->manual(['number' => '4321', 'series' => '1', 'total' => '1050', 'key' => $key, 'recipient_cnpj' => self::WORKSHOP_CNPJ, 'installments' => [['due_date' => '2026-10-25', 'amount' => '1050']]], $this->desk());
        $slip = $this->registerSlip(1050, '2026-10-25', [], $this->desk());
        $this->assertTrue($slip['auto']['linked']);
        $proof = fn () => collect(app(Occurrences::class)->forSlip($slip['slip']))->firstWhere('type', 'boleto_nota_sem_prova');
        $this->assertNotNull($proof());

        // Consulta do atendimento: registrada, mas ainda não prova.
        $this->assertTrue($this->invoices()->registerPortalCheck($invoice, ['status' => 'autorizada', 'amount' => '1050'], $this->desk())['confirms']);
        $this->assertMatchesRegularExpression('/consultada no portal pelo atendimento.*gestor repetir/', $proof()['detail']);

        // O gestor repete, mas o portal mostrou outro valor: continua sem prova e a mensagem diz por quê.
        $this->assertFalse($this->invoices()->registerPortalCheck($invoice->fresh(), ['status' => 'autorizada', 'amount' => '1500'], $this->gestor())['confirms']);
        $this->assertMatchesRegularExpression('/portal mostrou R\$ 1\.500,00.*digitada com R\$ 1\.050,00/', $proof()['detail']);

        $this->assertTrue($this->invoices()->registerPortalCheck($invoice->fresh(), ['status' => 'autorizada', 'amount' => '1.050,00'], $this->gestor())['confirms']);
        $this->assertNull($proof());
        $this->assertSame(['gestor', '2026-10-08'], [$invoice->fresh()->portal_checked_role, $invoice->fresh()->portal_checked_on->toDateString()]);
        $this->assertSame(3, AuditLog::query()->where('action', 'purchase.invoice_portal_check')->count());
    }

    public function test_consulta_nao_encontrada_alerta_e_denegada_do_gestor_cancela_a_nota(): void
    {
        $invoice = $this->manual(['number' => '4321', 'series' => '1', 'total' => '1050', 'key' => $this->nfeKey(['number' => 4321]), 'installments' => [['due_date' => '2026-10-25', 'amount' => '1050']]]);
        $slip = $this->registerSlip(1050, '2026-10-25')['slip'];

        $this->invoices()->registerPortalCheck($invoice, ['status' => 'nao_encontrada'], $this->gestor());
        $this->assertStringContainsString('NÃO foi encontrada no portal', collect(app(Occurrences::class)->forSlip($slip))->firstWhere('type', 'boleto_nota_sem_prova')['detail']);

        $this->invoices()->registerPortalCheck($invoice->fresh(), ['status' => 'denegada'], $this->gestor());
        $this->assertSame(['cancelada', 'sefaz', '110'], [$invoice->fresh()->status, $invoice->fresh()->canceled_source, $invoice->fresh()->protocol_status]);
        $this->assertSame('Boleto de nota DENEGADA', collect(app(Occurrences::class)->forSlip($slip))->firstWhere('type', 'nota_cancelada_com_boleto')['title']);

        foreach ([['status' => 'qualquer'], ['status' => 'autorizada'], ['status' => 'autorizada', 'amount' => '1e2'], ['status' => 'autorizada', 'amount' => '-5'], ['status' => 'autorizada', 'amount' => '0']] as $bad) {
            $this->assertThrows(fn () => $this->invoices()->registerPortalCheck($invoice->fresh(), $bad, $this->gestor()), DomainException::class);
        }
    }

    public function test_consulta_cancelada_do_atendimento_nao_cancela_mas_trava_o_boleto(): void
    {
        $invoice = $this->manual(['number' => '4400', 'series' => '1', 'total' => '700', 'key' => $this->nfeKey(['number' => 4400]), 'recipient_cnpj' => self::WORKSHOP_CNPJ, 'installments' => [['due_date' => '2026-10-25', 'amount' => '700']]], $this->desk());
        $slip = $this->registerSlip(700, '2026-10-25', [], $this->desk())['slip'];

        $this->assertFalse($this->invoices()->registerPortalCheck($invoice, ['status' => 'cancelada'], $this->desk())['canceled_here']);
        $this->assertSame(PurchaseInvoice::ACTIVE, $invoice->fresh()->status);
        $this->assertContains(['nota_consulta_cancelada_pendente', 'alta'], $this->pairs(app(Occurrences::class)->forSlip($slip)));

        $this->assertTrue($this->invoices()->registerPortalCheck($invoice->fresh(), ['status' => 'cancelada'], $this->gestor())['canceled_here']);
        $this->assertSame('sefaz', $invoice->fresh()->canceled_source);
    }

    public function test_destinatario_e_emissao_que_o_portal_mostrou_diferentes_tiram_a_prova_e_o_portal_contradiz_o_xml(): void
    {
        $invoice = $this->manual(['number' => '4500', 'series' => '1', 'total' => '300', 'key' => $this->nfeKey(['number' => 4500]), 'recipient_cnpj' => self::WORKSHOP_CNPJ, 'installments' => [['due_date' => '2026-10-25', 'amount' => '300']]]);
        $slip = $this->registerSlip(300, '2026-10-25')['slip'];
        $proof = fn () => collect(app(Occurrences::class)->forSlip($slip))->firstWhere('type', 'boleto_nota_sem_prova');

        $this->invoices()->registerPortalCheck($invoice, ['status' => 'autorizada', 'amount' => '300', 'recipient' => '44.555.666/0001-81'], $this->gestor());
        $this->assertStringContainsString('outro cliente', $proof()['detail']);

        $this->invoices()->registerPortalCheck($invoice->fresh(), ['status' => 'autorizada', 'amount' => '300,00', 'recipient' => '55.666.***/0001-81', 'issue_date' => '2026-10-01'], $this->gestor());
        $this->assertNull($proof());

        $this->invoices()->registerPortalCheck($invoice->fresh(), ['status' => 'autorizada', 'amount' => '300', 'issue_date' => '2026-09-20'], $this->gestor());
        $this->assertStringContainsString('emitida em 20/09/2026', $proof()['detail']);

        $xmlInvoice = $this->importInvoice(['number' => 4600, 'items' => [['code' => 'A', 'name' => 'PEÇA', 'qty' => 1, 'total' => 150.0]], 'installments' => [['due' => '2026-10-26', 'amount' => 150]]]);
        $other = $this->registerSlip(150, '2026-10-26')['slip'];
        $this->invoices()->registerPortalCheck($xmlInvoice, ['status' => 'nao_encontrada'], $this->gestor());
        $this->assertContains('nota_contradita_pelo_portal', $this->openTypes($other));
    }

    public function test_informar_a_chave_depois_confere_fornecedor_numero_mes_e_repeticao(): void
    {
        $invoice = $this->manual(['issuer_cnpj' => self::SUPPLIER_CNPJ, 'number' => '4321', 'series' => '1', 'total' => '1050']);

        $this->assertThrows(fn () => $this->invoices()->registerPortalCheck($invoice, ['status' => 'autorizada', 'amount' => '1050'], $this->gestor()), DomainException::class, 'Sem a chave');

        foreach ([['number' => 9999], ['number' => 4321, 'yymm' => '2609'], ['number' => 4321, 'cnpj' => self::OTHER_CNPJ]] as $wrong) {
            $this->assertThrows(fn () => $this->invoices()->setKey($invoice->fresh(), $this->nfeKey($wrong), $this->gestor()), DomainException::class);
        }

        $key = $this->nfeKey(['number' => 4321]);
        $this->assertThrows(fn () => $this->invoices()->setKey($invoice->fresh(), substr($key, 0, 43).((int) $key[43] + 1) % 10, $this->gestor()), DomainException::class, 'dígito verificador');
        $this->invoices()->setKey($invoice->fresh(), $key, $this->desk());
        $this->assertSame($key, $invoice->fresh()->access_key);
        $this->assertThrows(fn () => $this->invoices()->setKey($invoice->fresh(), $key, $this->desk()), DomainException::class, 'só o gestor troca');

        $other = $this->manual(['number' => '4322', 'series' => '1', 'issue_date' => '2026-10-02', 'total' => '10']);
        $this->assertThrows(fn () => $this->invoices()->setKey($other, $key, $this->gestor()), DomainException::class);         // a chave é de outra nota
        $this->invoices()->setKey($other, $this->nfeKey(['number' => 4322]), $this->gestor());
        $this->assertSame(44, strlen((string) $other->fresh()->access_key));
    }

    public function test_xml_do_atendimento_pede_a_consulta_do_gestor_quando_o_boleto_e_alto(): void
    {
        $has = fn ($slip) => in_array('nota_xml_sem_conferencia_do_gestor', $this->openTypes($slip), true);
        $invoice = $this->invoiceOf(5100, 2500, [['due' => '2026-10-25', 'amount' => 2500]], [], $this->desk());
        $slip = $this->registerSlip(2500, '2026-10-25', [], $this->desk())['slip'];
        $this->assertTrue($has($slip));

        $this->invoices()->registerPortalCheck($invoice, ['status' => 'autorizada', 'amount' => '2500'], $this->gestor());
        $this->assertFalse($has($slip));

        $this->invoiceOf(5101, 150, [['due' => '2026-10-26', 'amount' => 150]], [], $this->desk());
        $this->assertFalse($has($this->registerSlip(150, '2026-10-26', [], $this->desk())['slip']));          // valor baixo não incomoda
        $this->invoiceOf(5102, 3000, [['due' => '2026-10-27', 'amount' => 3000]]);
        $this->assertFalse($has($this->registerSlip(3000, '2026-10-27', [], $this->desk())['slip']));         // XML importado pelo gestor também não
    }

    public function test_nota_digitada_ou_sem_protocolo_nao_prova_ate_chegar_o_xml_autorizado(): void
    {
        $this->manual(['number' => '900', 'total' => '640', 'issue_date' => '2026-10-05', 'installments' => [['due_date' => '2026-10-25', 'amount' => '640']]]);
        $slip = $this->registerSlip(640, '2026-10-25');
        $this->assertTrue($slip['auto']['linked']);              // liga (é o melhor palpite) mas não libera
        $this->assertContains(['boleto_nota_sem_prova', 'alta'], $this->pairs($slip['occurrences']));
        $this->assertThrows(fn () => app(BankSlips::class)->pay($slip['slip'], ['bank_checked' => true], $this->gestor()), DomainException::class);

        $noProtocol = $this->invoices()->importXml($this->nfeXml(['number' => 901, 'protocol' => false, 'items' => [['code' => 'P', 'name' => 'P', 'qty' => 1, 'total' => 55.0]], 'installments' => [['due' => '2026-10-26', 'amount' => 55]]]), $this->gestor());
        $this->assertStringContainsString('sem protocolo', implode(' ', $noProtocol['warnings']));
        $this->assertContains('boleto_nota_sem_prova', $this->openTypes($this->registerSlip(55, '2026-10-26')['slip']));

        $this->invoices()->importXml($this->nfeXml(['number' => 900, 'issued_at' => '2026-10-05T10:00:00-03:00', 'items' => [['code' => 'P', 'name' => 'P', 'qty' => 1, 'total' => 640.0]], 'installments' => [['due' => '2026-10-25', 'amount' => 640]]]), $this->gestor());
        $this->assertNotContains('boleto_nota_sem_prova', $this->openTypes($slip['slip']));
    }

    public function test_apagar_nota_e_do_gestor_e_so_sem_ligacoes(): void
    {
        $invoice = $this->invoiceOf(800, 100);
        $linked = $this->invoiceOf(801, 120, [['due' => '2026-10-21', 'amount' => 120]]);
        $this->registerSlip(120, '2026-10-21');

        $this->assertThrows(fn () => $this->invoices()->delete($invoice, $this->desk()), DomainException::class, 'gestor');
        $this->assertThrows(fn () => $this->invoices()->delete($linked, $this->gestor()), DomainException::class, 'Desfaça as ligações');
        $this->invoices()->delete($invoice, $this->gestor());
        $this->assertNull(PurchaseInvoice::find($invoice->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'purchase.invoice_deleted', 'auditable_id' => $invoice->id]);
    }

    public function test_rateio_do_custo_fecha_com_o_total(): void
    {
        $this->assertSame(31000, array_sum(Invoices::costSplit([['total_cents' => 10000], ['total_cents' => 10000], ['total_cents' => 10000]], 31000)));
        $this->assertSame([15000, 5000], Invoices::costSplit([['total_cents' => 20000, 'discount_cents' => 5000], ['total_cents' => 5000]], 20000));
        $this->assertSame([10667, 5333], Invoices::costSplit([['total_cents' => 10000], ['total_cents' => 5000]], 16000));     // sem base por item: proporcional
        $this->assertSame([84000, 36000], Invoices::costSplit([['total_cents' => 80000, 'base_cost_cents' => 84000], ['total_cents' => 30000, 'base_cost_cents' => 36000]], 120000));
    }
}
