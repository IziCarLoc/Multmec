<?php

namespace Tests\Concerns;

use App\Enums\SupplierKind;
use App\Models\Customer;
use App\Models\Payable;
use App\Models\ProviderPayment;
use App\Models\Receivable;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Finance\Receivables;
use Carbon\CarbonImmutable;

/**
 * Lançamentos do Financeiro para os testes: cliente, fornecedor, prestador, "A receber" e boletos.
 */
trait BuildsFinance
{
    protected function financeUser(): User
    {
        return User::query()->where('email', 'financeiro@teste.test')->first()
            ?? User::factory()->gestor()->create(['email' => 'financeiro@teste.test']);
    }

    /**
     * Cliente que compra a prazo (frota, 30 dias, limite de R$ 100 mil): os lançamentos com vencimento futuro dos
     * testes passam pela trava do crédito. Cliente sem crédito: `walkInCustomer()`.
     */
    protected function financeCustomer(string $name = 'Maria Cliente', string $whatsapp = '55991112222'): Customer
    {
        return Customer::create(['name' => $name, 'whatsapp' => $whatsapp, 'credit_kind' => 'frota', 'credit_days' => 30, 'credit_limit_cents' => 10_000_000]);
    }

    /** Cliente avulso, sem crédito (paga na retirada). */
    protected function walkInCustomer(string $name = 'Cliente Avulso', string $whatsapp = '55991113333'): Customer
    {
        return Customer::create(['name' => $name, 'whatsapp' => $whatsapp]);
    }

    protected function partsSupplier(string $name = 'Auto Peças Teste'): Supplier
    {
        return Supplier::create(['name' => $name, 'kind' => SupplierKind::Pecas, 'active' => true]);
    }

    protected function serviceProvider(string $name = 'Douglas'): Supplier
    {
        return Supplier::create(['name' => $name, 'kind' => SupplierKind::Servico, 'active' => true]);
    }

    /** Um lançamento do "A receber" pelo serviço (o mesmo caminho da tela). */
    protected function receivable(array $data = [], ?string $dueDate = null, ?Customer $customer = null): Receivable
    {
        $customer ??= $this->financeCustomer();

        return app(Receivables::class)->create($data + [
            'customer_id' => $customer->id,
            'order_number' => '4521',
            'total_cents' => 100000,
            'parts_cost_cents' => 40000,
            'labor_cents' => 50000,
            'freight_cents' => 2000,
        ], [$dueDate ?? CarbonImmutable::today()->addDays(10)->toDateString()], $this->financeUser())->first();
    }

    protected function payable(array $attributes = []): Payable
    {
        return Payable::create($attributes + [
            'supplier_id' => $attributes['supplier_id'] ?? $this->partsSupplier()->id,
            'due_date' => CarbonImmutable::today()->addDays(5)->toDateString(),
            'amount_cents' => 25000,
            'status' => Payable::STATUS_OPEN,
        ]);
    }

    protected function providerPayment(array $attributes = []): ProviderPayment
    {
        return ProviderPayment::create($attributes + [
            'supplier_id' => $this->serviceProvider()->id,
            'order_number' => '4521',
            'amount_cents' => 18000,
            'due_date' => CarbonImmutable::today()->addDays(3)->toDateString(),
        ]);
    }

    /** Um pouco de tudo, para as telas terem o que mostrar. */
    protected function financeFixtures(): void
    {
        $overdue = $this->receivable([], CarbonImmutable::today()->subDays(5)->toDateString());
        app(Receivables::class)->addPayment($overdue, CarbonImmutable::today()->toDateString(), 30000, $this->financeUser());
        $this->receivable(['order_number' => '4600', 'received_by_matrix' => true]);
        $this->payable();
        $this->providerPayment();
    }
}
