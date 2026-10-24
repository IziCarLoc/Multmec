<?php

namespace Tests\Feature\Orders;

use App\Enums\Stage;
use App\Enums\SupplierKind;
use App\Models\Customer;
use App\Models\Payable;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Registry\CustomerDirectory;
use App\Services\Registry\IziFleet;
use App\Services\Registry\SupplierDirectory;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

/**
 * O que o cadastro do artefato do Financeiro tinha e faltava: "Novo cliente" com o carro, excluir cliente e
 * fornecedor (só sem histórico) e o contato do fornecedor.
 */
class RegistryEditingTest extends TestCase
{
    use BuildsOrders;
    use RefreshDatabase;

    public function test_novo_cliente_com_o_carro_sem_repetir_whatsapp_nem_tomar_carro_da_izi(): void
    {
        $directory = app(CustomerDirectory::class);

        $customer = $directory->register(['name' => '  Maria   Teste ', 'whatsapp' => '(55) 99111-2222', 'document' => '529.982.247-25', 'address' => 'Rua Inventada, 10', 'plate' => 'abc1d23', 'brand_model' => 'Gol 1.0', 'year' => '2015', 'color' => 'Prata']);

        $this->assertSame(['Maria Teste', '55991112222', '52998224725', 'Rua Inventada, 10'], [$customer->name, $customer->whatsapp, $customer->document, $customer->address]);
        $vehicle = Vehicle::byPlate('ABC1D23');
        $this->assertSame([$customer->id, 'Gol 1.0', '2015', 'Prata'], [$vehicle->customer_id, $vehicle->brand_model, $vehicle->year, $vehicle->color]);

        // Placa já conhecida passa para o cliente novo; o que veio em branco não apaga o carro.
        $other = $directory->register(['name' => 'João Teste', 'plate' => 'ABC1D23']);
        $this->assertSame([$other->id, 'Gol 1.0'], [$vehicle->fresh()->customer_id, $vehicle->fresh()->brand_model]);

        foreach ([
            ['name' => 'X'],
            ['name' => 'Outra Maria', 'whatsapp' => '55991112222'],
            ['name' => 'Fulano', 'whatsapp' => '123'],
            ['name' => 'Fulano', 'document' => '111.111.111-11'],
            ['name' => 'Fulano', 'plate' => 'XX1'],
        ] as $invalid) {
            try {
                $directory->register($invalid);
                $this->fail('Deveria recusar: '.json_encode($invalid));
            } catch (DomainException) {
            }
        }

        Vehicle::create(['plate' => 'IZI1A23', 'is_izi_fleet' => true, 'customer_id' => IziFleet::customer()->id]);
        $this->expectExceptionMessage('frota Izi');
        $directory->register(['name' => 'Fulano', 'plate' => 'IZI1A23']);
    }

    public function test_exclui_so_cliente_sem_os_nem_lancamento_e_o_carro_fica(): void
    {
        $directory = app(CustomerDirectory::class);
        $loose = $this->customer(['name' => 'Sem Histórico', 'whatsapp' => '55991113333']);
        $car = $this->vehicle(['customer_id' => $loose->id]);
        $withOrder = $this->order(Stage::Aberta)->customer;

        $this->assertNotNull($directory->deleteBlocker(IziFleet::customer()));
        $this->assertStringContainsString('tem OS', $directory->deleteBlocker($withOrder));
        $this->assertNull($directory->deleteBlocker($loose));

        $directory->delete($loose);
        $this->assertNull(Customer::find($loose->id));
        $this->assertNull($car->fresh()->customer_id);

        $this->expectException(DomainException::class);
        $directory->delete($withOrder);
    }

    public function test_exclui_so_fornecedor_sem_historico(): void
    {
        $directory = app(SupplierDirectory::class);
        $unused = Supplier::create(['name' => 'Lançado Por Engano', 'kind' => SupplierKind::Pecas, 'active' => true]);
        $used = Supplier::create(['name' => 'Com Boleto', 'kind' => SupplierKind::Pecas, 'active' => true]);
        Payable::create(['supplier_id' => $used->id, 'due_date' => '2026-11-10', 'amount_cents' => 1000, 'status' => Payable::STATUS_OPEN]);

        $this->assertStringContainsString('Desmarque "Ativo"', $directory->deleteBlocker($used));
        $directory->delete($unused);
        $this->assertNull(Supplier::find($unused->id));

        $this->expectException(DomainException::class);
        $directory->delete($used);
    }

    public function test_telas_novo_cliente_excluir_e_contato_do_fornecedor(): void
    {
        $this->actingAs(User::factory()->atendimento()->create());

        Livewire::test('pages::auth.registry.index')
            ->call('createCustomer')
            ->assertSet('tab', 'clientes')
            ->set('form.name', 'Cliente Novo Teste')
            ->set('form.whatsapp', '55 99222-3333')
            ->set('form.plate', 'TST1A11')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('editing', null)
            ->assertSee('Cliente Novo Teste');

        $customer = Customer::query()->where('name', 'Cliente Novo Teste')->firstOrFail();
        $this->assertSame($customer->id, Vehicle::byPlate('TST1A11')->customer_id);

        // WhatsApp repetido: a mensagem aparece na janela e nada é criado.
        Livewire::test('pages::auth.registry.index')
            ->call('createCustomer')
            ->set('form.name', 'Repetido')
            ->set('form.whatsapp', '55992223333')
            ->call('save')
            ->assertHasErrors('form')
            ->assertSee('Esse WhatsApp já é do cliente Cliente Novo Teste.');

        Livewire::test('pages::auth.registry.index')
            ->call('editCustomer', $customer->id)
            ->assertSee('Excluir')
            ->call('$set', 'confirmDelete', true)
            ->call('deleteCustomer')
            ->assertDispatched('toast');
        $this->assertNull(Customer::find($customer->id));

        $withOrder = $this->order(Stage::Aberta)->customer;
        Livewire::test('pages::auth.registry.index')->call('editCustomer', $withOrder->id)->assertDontSee('data-test="excluir-cliente"', false);

        Livewire::test('pages::auth.suppliers.index')
            ->call('create')
            ->set('name', 'Peças Contato Teste')
            ->set('contactName', 'Seu Zé')
            ->call('save')
            ->assertSee('Seu Zé');
        $supplier = Supplier::query()->where('name', 'Peças Contato Teste')->firstOrFail();
        $this->assertSame('Seu Zé', $supplier->contact_name);

        Livewire::test('pages::auth.suppliers.index')
            ->call('edit', $supplier->id)
            ->assertSet('contactName', 'Seu Zé')
            ->call('delete')
            ->assertDispatched('toast');
        $this->assertNull(Supplier::find($supplier->id));
    }
}
