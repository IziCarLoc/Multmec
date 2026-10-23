<?php

namespace Database\Seeders;

use App\Enums\SupplierKind;
use App\Models\Loss;
use App\Models\Receivable;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Finance\Losses;
use App\Services\Finance\Payables;
use App\Services\Finance\ProviderPayments;
use App\Services\Finance\Receivables;
use App\Services\Registry\CustomerDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Lançamentos de exemplo do Financeiro (nunca em produção), com datas em volta de hoje: a receber vencido e pago
 * em parte, parcelas, recebimento pela matriz, boletos (vencido, hoje, a vencer, pago), prestador e prejuízos.
 * Rode depois do DemoSeeder:
 *     php artisan db:seed --class=FinanceDemoSeeder
 * Rodar de novo não duplica (só lança quando o "A receber" está vazio).
 */
class FinanceDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('FinanceDemoSeeder não roda em produção.');
        }

        if (Receivable::query()->exists()) {
            return;
        }

        $user = User::query()->where('email', 'gabriel@exemplo.test')->first() ?? User::query()->orderBy('id')->firstOrFail();
        $today = CarbonImmutable::today();
        $date = fn (int $days) => $today->addDays($days)->toDateString();
        $customers = app(CustomerDirectory::class);
        $receivables = app(Receivables::class);

        $maria = $customers->findOrCreate('Maria Exemplo', '55999990021');
        // Maria compra a prazo (parcelas a vencer): crédito de exemplo, senão a trava do crédito recusa.
        $maria->update(['credit_kind' => 'frota', 'credit_days' => 30, 'credit_limit_cents' => 500000]);
        $joao = $customers->findOrCreate('João Exemplo', '55999990022');
        $carla = $customers->findOrCreate('Carla Exemplo', '55999990023');

        // Maria: serviço vencido e pago em parte (conta como vencido) e mais duas parcelas a vencer.
        $late = $receivables->create(['customer_id' => $maria->id, 'order_number' => '1180', 'plate' => 'EXE2B45', 'vehicle_model' => 'Fiat Strada', 'service_date' => $date(-20), 'parts_cost_cents' => 42000, 'labor_cents' => 35000, 'freight_cents' => 1500, 'total_cents' => 98000, 'payment_method' => 'pix'], [$date(-8)], $user)->first();
        $receivables->addPayment($late, $date(-6), 30000, $user);
        $receivables->create(['customer_id' => $maria->id, 'order_number' => '1185', 'plate' => 'EXE2B45', 'vehicle_model' => 'Fiat Strada', 'parts_cost_cents' => 60000, 'labor_cents' => 40000, 'total_cents' => 120000, 'payment_method' => 'cartao'], [$date(5), $date(35)], $user);

        // João: pago à vista neste mês e outro que vence hoje.
        $receivables->create(['customer_id' => $joao->id, 'order_number' => '1186', 'vehicle_model' => 'VW Gol', 'labor_cents' => 18000, 'total_cents' => 18000, 'customer_brought_part' => true, 'payment_method' => 'dinheiro', 'received_on' => $date(-2)], [$date(-2)], $user);
        $receivables->create(['customer_id' => $joao->id, 'order_number' => '1188', 'vehicle_model' => 'VW Gol', 'parts_cost_cents' => 9000, 'labor_cents' => 12000, 'total_cents' => 26000, 'payment_method' => 'pix'], [$date(0)], $user);

        // Carla: recebimento pela matriz (só registro) e um calote antigo já baixado como prejuízo.
        $matrix = $receivables->create(['customer_id' => $carla->id, 'order_number' => '1189', 'parts_cost_cents' => 20000, 'labor_cents' => 15000, 'total_cents' => 45000, 'received_by_matrix' => true, 'payment_method' => 'boleto'], [$date(3)], $user)->first();
        $receivables->addPayment($matrix, $date(-1), 15000, $user);
        $old = $receivables->create(['customer_id' => $carla->id, 'order_number' => '1150', 'labor_cents' => 22000, 'total_cents' => 22000, 'payment_method' => 'pix'], [$date(-40)], $user)->first();
        $receivables->writeOff($old, $user);

        // Boletos: vencido, vence hoje, a vencer em parcelas e um pago.
        $parts = Supplier::query()->where('kind', SupplierKind::Pecas->value)->orderBy('id')->first();
        $payables = app(Payables::class);
        $supplier = $parts ? ['supplier_id' => $parts->id] : ['supplier_name' => 'Auto Peças Exemplo'];
        $payables->create($supplier + ['document_number' => 'NF 8801', 'description' => 'Pastilhas e discos', 'issue_date' => $date(-30), 'amount_cents' => 48700], [$date(-3)], $user);
        $payables->create($supplier + ['document_number' => 'NF 8812', 'description' => 'Óleo e filtros', 'issue_date' => $date(-15), 'amount_cents' => 31250], [$date(0)], $user);
        $payables->create(['supplier_name' => 'Distribuidora Exemplo', 'document_number' => 'NF 9020', 'description' => 'Kit embreagem', 'issue_date' => $date(-1), 'amount_cents' => 90000], [$date(6), $date(36), $date(66)], $user, amountIsTotal: true);
        $payables->create($supplier + ['document_number' => 'NF 8790', 'description' => 'Amortecedores', 'issue_date' => $date(-35), 'amount_cents' => 64000, 'status' => 'pago', 'paid_on' => $date(-4)], [$date(-5)], $user);

        // Prestador externo (o Douglas do artefato) e o eletricista do DemoSeeder.
        $providers = app(ProviderPayments::class);
        $providers->create(['supplier_name' => 'Douglas Exemplo', 'order_number' => '1180', 'plate' => 'EXE2B45', 'description' => 'Troca de embreagem', 'amount_cents' => 25000, 'due_date' => $date(2)], $user);
        $providers->create(['supplier_name' => 'Douglas Exemplo', 'order_number' => '1170', 'description' => 'Retífica do cabeçote', 'amount_cents' => 40000, 'due_date' => $date(-10), 'paid_on' => $date(-9)], $user);

        if (! Loss::query()->whereNull('receivable_id')->exists()) {
            app(Losses::class)->create(['occurred_on' => $date(-7), 'amount_cents' => 12000, 'item' => 'Sensor quebrado na montagem', 'cause' => 'erro', 'notes' => 'Exemplo'], $user);
        }
    }
}
