<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\Stage;
use App\Enums\SupplierKind;
use App\Models\ServiceOrder;
use App\Models\ServiceOrder as Order;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Inspections\EntryInspection;
use App\Services\Inspections\InspectionDelivery;
use App\Services\Inspections\TechnicalChecklist;
use App\Services\Items\OrderItems;
use App\Services\Media\MediaStore;
use App\Services\Orders\OrderOpener;
use App\Services\Orders\StageMachine;
use App\Services\Registry\IziFleet;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Dados de exemplo para testar sem cadastrar nada (nunca em produção): 5 usuários, 3 fornecedores
 * (um deles prestador de serviço), um carro da frota Izi e 2 OS em etapas diferentes (a do carro Izi já com
 * diagnóstico, peças e serviços, pronta para a cotação). Tudo inventado.
 *     php artisan db:seed --class=DemoSeeder
 * Senha de todos: multmec123 (e-mails @exemplo.test). Rodar de novo não duplica.
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'multmec123';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder não roda em produção (cria usuários com senha conhecida).');
        }

        $this->call(UnitSeeder::class);

        $team = [
            ['Gabriel Cezimbra', 'Gabriel C.', 'gabriel@exemplo.test', Role::Gestor],
            ['Matheus Schott', 'Matheus', 'matheus@exemplo.test', Role::Gestor],
            ['Giovana', 'Giovana', 'giovana@exemplo.test', Role::Atendimento],
            ['Robson', 'Robson', 'robson@exemplo.test', Role::Mecanico],
            ['Gabriel (mecânico)', 'Gabriel M.', 'gabriel.mecanico@exemplo.test', Role::Mecanico],
        ];

        foreach ($team as [$name, $short, $email, $role]) {
            User::query()->updateOrCreate(['email' => $email], [
                'name' => $name,
                'short_name' => $short,
                'role' => $role,
                'active' => true,
                'must_change_password' => false,
                'password' => Hash::make(self::PASSWORD),
            ]);
        }

        $this->suppliers();
        $this->orders();
    }

    private function suppliers(): void
    {
        $suppliers = [
            ['Auto Peças Exemplo', SupplierKind::Pecas, '55999990001', 'Suspensão, freio e motor (linha leve)', 'Mesmo dia até 15h', 'Boleto 28 dias'],
            ['Distribuidora Exemplo', SupplierKind::Pecas, '55999990002', 'Filtros, óleo e elétrica', 'Dia seguinte', 'Pix à vista'],
            ['Eletricista Exemplo', SupplierKind::Servico, '55999990003', 'Elétrica e ar-condicionado (prestador externo)', 'Combinar', 'Pix por serviço'],
        ];

        foreach ($suppliers as [$name, $kind, $whatsapp, $lines, $lead, $terms]) {
            Supplier::query()->firstOrCreate(['name' => $name], [
                'kind' => $kind, 'whatsapp' => $whatsapp, 'lines' => $lines, 'lead_time' => $lead, 'payment_terms' => $terms,
            ]);
        }
    }

    /** Uma OS aberta (cliente particular) e uma em diagnóstico (carro da frota Izi). */
    private function orders(): void
    {
        if (ServiceOrder::query()->exists()) {
            return;
        }

        $unit = Unit::default();
        $giovana = User::query()->where('email', 'giovana@exemplo.test')->firstOrFail();
        $robson = User::query()->where('email', 'robson@exemplo.test')->firstOrFail();

        Vehicle::query()->firstOrCreate(['plate_key' => 'EXE1A23'], [
            'plate' => 'EXE1A23', 'brand_model' => 'Chevrolet Prisma 1.4', 'year' => '2019/2020', 'color' => 'Prata',
            'is_izi_fleet' => true, 'investor_name' => 'Investidor Exemplo', 'customer_id' => IziFleet::customer()->id,
        ]);

        $opener = app(OrderOpener::class);
        $opener->open($unit, [
            'number' => 1190, 'plate' => 'TST2B34', 'brand_model' => 'VW Gol 1.0', 'color' => 'Branco', 'year' => '2015/2016',
            'customer_name' => 'Cliente Exemplo', 'customer_whatsapp' => '55999990010',
            'complaint' => 'Barulho na frente quando passa em lombada',
        ], $giovana);

        $izi = $opener->open($unit, [
            'number' => 1191, 'plate' => 'EXE1A23', 'complaint' => 'Revisão dos 60 mil e luz do óleo piscando',
        ], $giovana);

        $machine = app(StageMachine::class);
        $izi = $machine->move($izi, Stage::Aberta, Stage::EmVistoria, $giovana);
        $this->inspect($izi, $giovana, $robson);
        $izi = $machine->move($izi->fresh(), Stage::EmVistoria, Stage::EmDiagnostico, $giovana);
        $izi->update(['responsible_id' => $robson->id, 'diagnosis' => 'Óleo abaixo do mínimo e sensor de pressão do óleo com defeito (por isso a luz pisca). Revisão dos 60 mil: troca do óleo e do filtro.']);

        // Diagnóstico do mecânico: peças a cotar e serviços com horas (pronto para "Enviar para cotação").
        $items = app(OrderItems::class);
        $items->add($izi, 'peca', ['description' => 'Óleo 5W30 sintético', 'quantity' => 4, 'requires_photos' => false], $robson);
        $items->add($izi, 'peca', ['description' => 'Filtro de óleo', 'preferred_brand' => 'Tecfil'], $robson);
        $items->add($izi, 'peca', ['description' => 'Sensor de pressão do óleo', 'part_code' => 'EXEMPLO-123', 'quote_detail' => 'rosca 1/8'], $robson);
        $items->add($izi, 'servico', ['description' => 'Troca de óleo e filtro', 'hours' => 0.5], $robson);
        $items->add($izi, 'servico', ['description' => 'Troca do sensor de pressão do óleo', 'hours' => 0.5, 'priority' => 'necessario'], $robson);
    }

    /** Vistoria de exemplo completa: fotos geradas na hora (desenho simples), parte A, parte B e envio. */
    private function inspect(Order $order, User $giovana, User $robson): void
    {
        $store = app(MediaStore::class);

        foreach (EntryInspection::PHOTOS as $position => $label) {
            $file = $this->fakePhoto($label);
            $store->storePhoto($order, EntryInspection::kindFor($position), $file, hash_file('sha256', $file->getRealPath()), now()->getTimestampMs(), $giovana, ['position' => $position]);
        }

        app(EntryInspection::class)->update($order, [
            'km_in' => 61230, 'fuel_in' => '1/4', 'dashboard_lights' => 'Luz do óleo piscando', 'belongings' => 'Cabo de carregador no porta-luvas',
            'has_spare_tire' => true, 'has_jack' => true, 'has_triangle' => true, 'has_wheel_wrench' => true,
            'no_visible_damage' => true, 'test_drive_authorized' => true, 'diagnosis_charged' => false, 'approver_kind' => 'izi',
        ], $giovana);

        $checklist = app(TechnicalChecklist::class);

        foreach ($checklist->itemsFor($order) as $item) {
            $checklist->answer($item, $item->is_fluid ? ($item->label === 'Óleo do motor' ? 'media' : 'ideal') : 'ok', null, $robson);
        }

        app(InspectionDelivery::class)->markSent($order->fresh(), 'manual', $giovana);
    }

    private function fakePhoto(string $label): UploadedFile
    {
        $image = imagecreatetruecolor(1600, 1200);
        imagefill($image, 0, 0, imagecolorallocate($image, 0xB8, 0xC4, 0xCE));
        imagefilledrectangle($image, 300, 500, 1300, 900, imagecolorallocate($image, 0x7A, 0x86, 0x90));
        imagestring($image, 5, 40, 40, 'FOTO DE EXEMPLO: '.iconv('UTF-8', 'ASCII//TRANSLIT', $label), imagecolorallocate($image, 0x1D, 0x23, 0x28));
        $path = tempnam(sys_get_temp_dir(), 'demo').'.jpg';
        imagejpeg($image, $path, 80);

        return new UploadedFile($path, 'exemplo.jpg', 'image/jpeg', null, true);
    }
}
