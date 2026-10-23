<?php

namespace Tests\Feature\Orders;

use App\Enums\Stage;
use App\Models\Customer;
use App\Models\ServiceOrder;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Inspections\InspectionDelivery;
use App\Services\Orders\OrderEditor;
use App\Services\Orders\OrderOpener;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class OrderOpeningTest extends TestCase
{
    use BuildsOrders;
    use RefreshDatabase;

    private function open(array $input, ?Unit $unit = null): ServiceOrder
    {
        return app(OrderOpener::class)->open($unit ?? $this->unit(), $input + [
            'number' => 1190,
            'plate' => 'IZN-4C21',
            'customer_name' => 'Maria Souza',
            'customer_whatsapp' => '(55) 99111-2233',
            'complaint' => 'Luz da injeção acesa',
        ], User::factory()->atendimento()->create());
    }

    public function test_abre_a_os_aberta_com_trilha_carro_e_cliente(): void
    {
        $order = $this->open(['brand_model' => 'Prisma 1.4', 'color' => 'Prata']);

        $this->assertSame(Stage::Aberta, $order->stage);
        $this->assertSame(1190, $order->number);
        $this->assertSame('IZN4C21', $order->vehicle->plate);
        $this->assertSame('Prisma 1.4', $order->vehicle->brand_model);
        $this->assertSame('55991112233', $order->customer->whatsapp);
        $this->assertSame($order->customer_id, $order->vehicle->customer_id);
        $this->assertSame([null, Stage::Aberta], [$order->transitions->first()->from_stage, $order->transitions->first()->to_stage]);
        $this->assertSame('cliente', $order->approver_kind);
    }

    public function test_corrigir_o_whatsapp_do_cliente_corrige_para_quem_vai_a_vistoria(): void
    {
        $order = $this->open([]);
        $this->assertSame('55991112233', $order->approver_whatsapp);

        $order = app(OrderEditor::class)->details($order, ['customer_name' => 'Maria Souza Lima', 'customer_whatsapp' => '(55) 99111-9999', 'entry_date' => $order->entry_date->toDateString()], User::factory()->atendimento()->create());

        $this->assertSame(['55991119999', 'Maria Souza Lima'], [$order->approver_whatsapp, $order->approver_name]);
        $this->assertSame('55991119999', app(InspectionDelivery::class)->recipientPhone($order));
    }

    public function test_numero_e_obrigatorio_e_unico_na_unidade(): void
    {
        $this->open([]);

        try {
            $this->open(['plate' => 'ABC1234']);
            $this->fail('Número repetido devia ser recusado.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Já existe a OS 1190', $e->getMessage());
        }

        $this->expectExceptionMessage('número da OS');
        $this->open(['number' => '', 'plate' => 'ABC1235']);
    }

    public function test_numeracao_automatica_segue_o_proximo_numero(): void
    {
        $unit = $this->unit();
        $unit->update(['numbering_mode' => Unit::NUMBERING_AUTO, 'next_number' => 2000]);

        $first = $this->open(['number' => null], $unit);
        $second = $this->open(['number' => '99', 'plate' => 'ABC1234'], $unit->fresh());

        $this->assertSame([2000, 2001], [$first->number, $second->number]);
    }

    public function test_placa_antiga_e_mercosul_sao_o_mesmo_carro_e_o_cadastro_se_completa(): void
    {
        $vehicle = $this->vehicle(['plate' => 'ABC1234', 'brand_model' => 'Gol', 'color' => null]);

        $order = $this->open(['plate' => 'abc-1c34', 'color' => 'Preto', 'brand_model' => '']);

        $this->assertSame($vehicle->id, $order->vehicle_id);
        $this->assertSame('Gol', $order->vehicle->brand_model, 'campo vazio não apaga o cadastro');
        $this->assertSame('Preto', $order->vehicle->color);
        $this->assertSame(1, Vehicle::query()->count());
    }

    public function test_cliente_sem_whatsapp_valido_nao_abre(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('WhatsApp');
        $this->open(['customer_whatsapp' => '1234']);
    }

    public function test_mesmo_whatsapp_e_o_mesmo_cliente(): void
    {
        $first = $this->open([]);
        $second = $this->open(['number' => 1191, 'plate' => 'ABC1234', 'customer_name' => 'Maria S.', 'customer_whatsapp' => '+55 55 99111-2233']);

        $this->assertSame($first->customer_id, $second->customer_id);
        $this->assertSame(1, Customer::query()->where('is_izi_fleet', false)->count());
    }

    public function test_carro_da_frota_izi_entra_no_cliente_izi_sem_pedir_whatsapp(): void
    {
        $this->vehicle(['plate' => 'IZN4C21', 'is_izi_fleet' => true, 'investor_name' => 'Antônio']);

        $order = $this->open(['customer_name' => '', 'customer_whatsapp' => '']);

        $this->assertTrue($order->customer->is_izi_fleet);
        $this->assertSame('Izi Car Locadora', $order->customer->name);
        $this->assertSame('izi', $order->approver_kind);
    }

    public function test_queixa_e_placa_sao_obrigatorias(): void
    {
        try {
            $this->open(['plate' => 'XX12']);
            $this->fail('Placa inválida devia ser recusada.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Placa inválida', $e->getMessage());
        }

        $this->expectExceptionMessage('queixa');
        $this->open(['complaint' => ' ']);
    }
}
