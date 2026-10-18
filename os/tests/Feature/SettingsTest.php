<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Unit;
use App\Models\User;
use App\Services\Settings\UnitSettings;
use App\Support\Settings;
use Database\Seeders\UnitSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sem_ajuste_gravado_vale_o_padrao_da_oficina(): void
    {
        $this->assertSame(150000, Settings::int('izi_approval_limit_cents'));
        $this->assertSame(90, Settings::int('warranty_days'));
        $this->assertNull(Settings::int('warranty_km'));
        $this->assertStringContainsString('[REVISAR', Settings::text('warranty'));
    }

    public function test_ajuste_gravado_vale_e_fica_na_auditoria(): void
    {
        $this->actingAs(User::factory()->gestor()->create());

        Settings::set('izi_approval_limit_cents', 200000);

        $this->assertSame(200000, Settings::int('izi_approval_limit_cents'));
        $log = AuditLog::query()->where('action', 'settings.updated')->firstOrFail();
        $this->assertSame(['antes' => 150000, 'depois' => 200000], $log->changes['izi_approval_limit_cents']);
    }

    public function test_tela_de_ajustes_salva_regras_e_textos(): void
    {
        $this->seed(UnitSeeder::class);
        $gestor = User::factory()->gestor()->create();

        Livewire::actingAs($gestor)->test('pages::auth.settings.index')
            ->assertSet('iziLimit', 150000)
            ->set('iziLimit', 180000)
            ->set('warrantyDays', 120)
            ->set('warrantyKm', 5000)
            ->call('saveRules')
            ->assertHasNoErrors()
            ->set('texts.pickup', 'Texto do termo de retirada')
            ->call('saveTexts');

        $this->assertSame(180000, Settings::int('izi_approval_limit_cents'));
        $this->assertSame(120, Settings::int('warranty_days'));
        $this->assertSame(5000, Settings::int('warranty_km'));
        $this->assertSame('Texto do termo de retirada', Settings::text('pickup'));
    }

    public function test_garantia_contratual_nao_fica_abaixo_da_legal(): void
    {
        $this->seed(UnitSeeder::class);

        Livewire::actingAs(User::factory()->gestor()->create())->test('pages::auth.settings.index')
            ->set('warrantyDays', 30)
            ->call('saveRules')
            ->assertHasErrors(['warrantyDays' => 'min']);
    }

    public function test_dados_da_oficina_e_numeracao(): void
    {
        $this->seed(UnitSeeder::class);
        $unit = Unit::default();
        $this->assertSame('LIB', $unit->code);
        $this->assertSame('63796929000199', $unit->cnpj);
        $this->assertFalse($unit->numbersAutomatically(), 'no piloto o número vem do sistema atual');

        Livewire::actingAs(User::factory()->gestor()->create())->test('pages::auth.settings.index')
            ->set('unit.numbering_mode', 'auto')
            ->set('unit.next_number', 1300)
            ->call('saveUnit')
            ->assertHasNoErrors();

        $unit->refresh();
        $this->assertTrue($unit->numbersAutomatically());
        $this->assertSame(1300, $unit->next_number);
        $this->assertTrue(AuditLog::query()->where('action', 'unit.updated')->exists());
    }

    public function test_cnpj_invalido_e_numeracao_automatica_sem_numero_sao_recusados(): void
    {
        $this->seed(UnitSeeder::class);
        $settings = app(UnitSettings::class);
        $unit = Unit::default();
        $base = ['trade_name' => 'Multmec', 'legal_name' => null, 'address' => null, 'city' => null, 'phone' => null, 'active' => true];

        try {
            $settings->save($unit, [...$base, 'cnpj' => '11.111.111/1111-11', 'numbering_mode' => 'manual', 'next_number' => null]);
            $this->fail('CNPJ inválido passou.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('CNPJ', $e->getMessage());
        }

        $this->expectException(DomainException::class);
        $settings->save($unit, [...$base, 'cnpj' => null, 'numbering_mode' => 'auto', 'next_number' => null]);
    }

    public function test_unidade_padrao_nao_pode_ser_desativada(): void
    {
        $this->seed(UnitSeeder::class);

        $this->expectException(DomainException::class);
        app(UnitSettings::class)->save(Unit::default(), ['trade_name' => 'Multmec', 'numbering_mode' => 'manual', 'next_number' => null, 'active' => false]);
    }
}
