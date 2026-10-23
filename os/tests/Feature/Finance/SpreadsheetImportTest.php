<?php

namespace Tests\Feature\Finance;

use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Receivable;
use App\Models\User;
use App\Services\Import\SpreadsheetImport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Concerns\BuildsFinance;
use Tests\TestCase;

/**
 * Importação da planilha CONTROLE SERVIÇOS: os vetores do `importar.test.js` e do `util.test.js` do Node, com
 * CSV inventado (nomes, placas e valores de exemplo).
 */
class SpreadsheetImportTest extends TestCase
{
    use BuildsFinance;
    use RefreshDatabase;

    private const HEADER = 'O.S,DATA,VEÍCULO,PLACA,OPERADOR,CUSTO PEÇAS,M DE OBRA,INSUMOS,FRETE,VL SERVIÇO,LUCRO PÇ,PROPRIETA,DESC,LUCRO BRUTO,OBSERVAÇÃO,OBSERVAÇÃO,LOCADORA';

    private function line(string $os, string $date, string $plate, string $customer, string $total, array $o = []): string
    {
        $q = fn (string $v) => $v !== '' ? '"'.$v.'"' : '';

        return $os.','.$date.',GOL,'.$plate.',MECANICO TESTE,'.$q($o['parts'] ?? '').','.$q($o['labor'] ?? '').','.$q($o['supplies'] ?? '').',,'.$q($total).',,'.$customer.',,,'.($o['obs'] ?? '').','.($o['status'] ?? '').',';
    }

    private function sheet(): string
    {
        $lines = [self::HEADER];

        for ($i = 0; $i < 12; $i++) {
            $lines[] = $this->line((string) (100 + $i), sprintf('%02d/10/2025', 3 + $i), 'AAA'.(1000 + $i), 'JOAO TESTE', 'R$ 500,00', ['parts' => 'R$ 200,00', 'labor' => 'R$ 100,00']);
        }

        $lines[] = $this->line('112', '19/12', 'BBB2222', 'IZI', 'R$ 1.000,00', ['parts' => 'R$ 300,00', 'supplies' => 'R$ 20,00']);   // digitada errado no meio de outubro
        $lines[] = $this->line('113', 'ORÇAMENTO', 'CCC3333', 'ANA TESTE', 'R$ 750,00');
        $lines[] = $this->line('114', '15/10/2025', 'DDD4444', 'IZICAR', 'R$ 300,00', ['status' => 'RECEBIDO']);
        $lines[] = 'OUTUBRO,,,,,,,,,"R$ 9.999,00",,,,,,,';                                                                              // total do mês
        $lines[] = $this->line('115', '16/10/2025', 'EEE5555', 'ANA TESTE', '');                                                      // sem valor
        $lines[] = $this->line('116', '17/10/2025', 'FFF6666', 'ANA TESTE', 'R$ 410,00', ['status' => 'C.C 3X']);
        $lines[] = $this->line('117', 'PEÇAS', 'GGG7777', 'ANA TESTE', 'R$ 80,00');                                                    // compra de peça

        return implode("\n", $lines);
    }

    public function test_valores_e_csv_como_no_node(): void
    {
        $this->assertSame(123456, SpreadsheetImport::value('R$ 1.234,56'));
        $this->assertSame(6830, SpreadsheetImport::value('R$ 68,30'));
        $this->assertSame(-2800, SpreadsheetImport::value('-R$ 28,00'));
        $this->assertSame(123450, SpreadsheetImport::value('1234.5'));
        $this->assertSame(120, SpreadsheetImport::value('1.200'));            // sem vírgula, o ponto é decimal
        $this->assertSame(1235, SpreadsheetImport::value('12.345'));
        $this->assertNull(SpreadsheetImport::value(''));
        $this->assertNull(SpreadsheetImport::value('\\-'));

        $rows = SpreadsheetImport::csv("a,b,c\n\"x, y\",\"linha1\nlinha2\",\"diz \"\"oi\"\"\"\n");
        $this->assertSame(['x, y', "linha1\nlinha2", 'diz "oi"'], $rows[1]);
    }

    public function test_le_a_planilha_ignora_totais_corrige_data_absurda_e_separa_orcamento(): void
    {
        $read = app(SpreadsheetImport::class)->read($this->sheet());
        $lines = collect($read['lines'])->keyBy('number');

        $this->assertCount(12 + 1 + 1 + 1 + 1, $lines);                     // 12 + 112 + orçamento + 114 + 116
        $this->assertStringStartsWith('2025-10', $lines['112']['date']);
        $this->assertTrue($lines['112']['estimated']);
        $this->assertFalse($lines['100']['estimated']);
        $this->assertTrue($lines['113']['budget']);
        $this->assertSame('IZICAR', $lines['114']['customer']);
        $this->assertSame('IZICAR', $lines['112']['customer']);
        $this->assertSame(PaymentMethod::Cartao->value, $lines['116']['method']);
        $this->assertSame(20_000, $lines['100']['parts']);
        $this->assertSame(0, $lines['112']['labor']);
        $this->assertSame(['OS 117: compra de peças lançada como OS, ignorada.'], $read['warnings']);
    }

    public function test_importa_uma_vez_so_com_corte_e_previa_que_nao_grava(): void
    {
        $user = $this->financeUser();
        $import = app(SpreadsheetImport::class);

        $preview = $import->preview($this->sheet(), '2025-10-10', $user);
        $this->assertSame(15, $preview['new']);
        $this->assertSame(0, Receivable::query()->count());

        $report = $import->apply($this->sheet(), '2025-10-10', $user);
        $this->assertSame(15, $report['new']);
        $this->assertSame(1, $report['budgets']);
        $this->assertSame(8, $report['settled_by_cutoff']);                  // 03 a 10/10
        $this->assertSame(1, $report['received_in_sheet']);
        $this->assertSame(['2025-10' => 12 * 50_000 + 100_000 + 30_000 + 41_000], $report['months']);
        $this->assertSame(collect($preview)->except('sha256')->all(), collect($report)->except('sha256')->all());

        $izi = Customer::query()->where('is_izi_fleet', true)->sole();
        $this->assertSame(2, Receivable::query()->where('customer_id', $izi->id)->count());
        $this->assertSame(0, Receivable::query()->where('order_number', '114')->sole()->balanceCents());   // RECEBIDO na planilha
        $os112 = Receivable::query()->where('order_number', '112')->sole();
        $this->assertSame(32_000, $os112->parts_cost_cents);                                              // peças + insumos
        $this->assertStringContainsString('Data estimada', $os112->notes);
        $this->assertSame(50_000, Receivable::query()->where('order_number', '111')->sole()->balanceCents()); // 14/10, depois do corte
        $this->assertStringStartsWith(SpreadsheetImport::PREFIX, Receivable::query()->where('order_number', '100')->sole()->payments()->sole()->legacy_id);

        $again = $import->apply($this->sheet(), '2025-10-10', $user);
        $this->assertSame([0, 15], [$again['new'], $again['existing']]);
        $this->assertSame(15, Receivable::query()->count());
    }

    public function test_tela_so_do_gestor_com_previa_e_importar(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00'));
        $file = UploadedFile::fake()->createWithContent('servicos.csv', $this->sheet());

        $this->actingAs(User::factory()->atendimento()->create())->get(route('finance.spreadsheet-import'))->assertForbidden();

        Livewire::actingAs($this->financeUser())->test('pages::auth.finance.spreadsheet-import')
            ->assertSet('cutoff', '2026-10-01')
            ->set('cutoff', '2025-10-10')
            ->set('file', $file)
            ->assertSee('Prévia')
            ->assertSee('out/2025')
            ->assertSee('R$ 7.710,00')
            ->call('apply')
            ->assertDispatched('toast', type: 'success')
            ->assertSee('Importado');

        $this->assertSame(15, Receivable::query()->count());
    }
}
