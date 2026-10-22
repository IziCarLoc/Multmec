<?php

namespace App\Services\Inspections;

use App\Enums\MediaKind;
use App\Enums\Stage;
use App\Models\AuditLog;
use App\Models\Consent;
use App\Models\Damage;
use App\Models\Media;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Support\Phone;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Vistoria de entrada, parte A (atendimento, com ou sem o cliente): fotos dos 4 cantos com a placa e do
 * painel, km, combustível, luzes, objetos e acessórios, estepe/macaco/triângulo/chave, avarias marcadas ou
 * "sem avarias aparentes", autorização de test drive, diagnóstico cobrado e quem aprova. Depois a vistoria
 * vai para o cliente pelo WhatsApp ou é assinada na tela.
 */
final class EntryInspection
{
    /** Fotos obrigatórias da entrada: posição => rótulo (os 4 cantos com a placa e o painel ligado). */
    public const PHOTOS = [
        'dianteira_esquerda' => 'Frente, lado esquerdo (com a placa)',
        'dianteira_direita' => 'Frente, lado direito (com a placa)',
        'traseira_direita' => 'Traseira, lado direito (com a placa)',
        'traseira_esquerda' => 'Traseira, lado esquerdo (com a placa)',
        'painel' => 'Painel ligado (km, combustível e luzes)',
    ];

    public const FUEL = ['R' => 'Reserva', '1/4' => '1/4', '1/2' => '1/2', '3/4' => '3/4', 'C' => 'Cheio'];

    public const ACCESSORIES = [
        'has_spare_tire' => 'Estepe',
        'has_jack' => 'Macaco',
        'has_triangle' => 'Triângulo',
        'has_wheel_wrench' => 'Chave de roda',
    ];

    /** Quem aprova o orçamento. */
    public const APPROVERS = ['cliente' => 'O próprio cliente', 'outro' => 'Outra pessoa', 'izi' => 'Izi Car (alçada)'];

    public static function kindFor(string $position): MediaKind
    {
        return $position === 'painel' ? MediaKind::Painel : MediaKind::Entrada;
    }

    /**
     * Grava os campos da parte A que vieram (o que não veio fica como está).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(ServiceOrder $order, array $data, User $by): ServiceOrder
    {
        $this->assertCanEdit($order, $by);
        $values = [];

        if (array_key_exists('km_in', $data)) {
            $km = $data['km_in'] === null || $data['km_in'] === '' ? null : (int) preg_replace('/\D/', '', (string) $data['km_in']);

            if ($km !== null && $km > 2_000_000) {
                throw new DomainException('Km fora do normal. Confira o número do painel.');
            }

            $values['km_in'] = $km;
        }

        if (array_key_exists('fuel_in', $data)) {
            if ($data['fuel_in'] !== null && ! array_key_exists($data['fuel_in'], self::FUEL)) {
                throw new DomainException('Escolha o nível de combustível.');
            }

            $values['fuel_in'] = $data['fuel_in'];
        }

        foreach (['dashboard_lights' => 255, 'belongings' => 2000] as $field => $max) {
            if (array_key_exists($field, $data)) {
                $values[$field] = mb_substr(trim((string) $data[$field]), 0, $max) ?: null;
            }
        }

        foreach ([...array_keys(self::ACCESSORIES), 'test_drive_authorized', 'diagnosis_charged', 'no_visible_damage'] as $field) {
            if (array_key_exists($field, $data)) {
                $values[$field] = $data[$field] === null ? null : (bool) $data[$field];
            }
        }

        if (array_key_exists('diagnosis_fee_cents', $data)) {
            $values['diagnosis_fee_cents'] = $data['diagnosis_fee_cents'] === null ? null : max(0, (int) $data['diagnosis_fee_cents']);
        }

        if (($values['no_visible_damage'] ?? false) === true && $order->damages()->exists()) {
            throw new DomainException('Há avarias marcadas. Para dizer "sem avarias aparentes", remova as avarias antes.');
        }

        if (array_key_exists('approver_kind', $data)) {
            $values += $this->approver($order, (string) $data['approver_kind'], $data['approver_name'] ?? null, $data['approver_whatsapp'] ?? null);
        }

        $before = $order->only(array_keys($values));
        $order->fill($values);

        if ($order->isDirty()) {
            $changes = collect($order->getDirty())->map(fn ($v, $k) => ['antes' => $before[$k] ?? null, 'depois' => $v])->all();
            $order->save();
            AuditLog::record($order, 'inspection.updated', null, $changes);
        }

        return $order;
    }

    /** Foto de avaria: o local escolhido e, depois, as marcas tocadas sobre a foto. */
    public function addDamage(ServiceOrder $order, string $location, Media $media, User $by): Damage
    {
        $this->assertCanEdit($order, $by);

        if (trim($location) === '') {
            throw new DomainException('Escolha o local da avaria.');
        }

        return DB::transaction(function () use ($order, $location, $media, $by) {
            $order->update(['no_visible_damage' => false]);

            return Damage::create([
                'service_order_id' => $order->id,
                'media_id' => $media->id,
                'location' => mb_substr(trim($location), 0, 60),
                'marks' => [],
                'created_by' => $by->id,
            ]);
        });
    }

    /**
     * @param  list<array{x: float|int|string, y: float|int|string, type?: ?string, desc?: ?string}>  $marks
     */
    public function setMarks(Damage $damage, array $marks, User $by): Damage
    {
        $this->assertCanEdit($damage->serviceOrder, $by);

        if (count($marks) > 30) {
            throw new DomainException('Muitas marcas numa foto só. Tire outra foto para o resto.');
        }

        $clean = array_values(array_map(function (array $mark) {
            $type = in_array($mark['type'] ?? null, Damage::TYPES, true) ? $mark['type'] : 'Outro';

            return [
                'x' => round(min(1, max(0, (float) $mark['x'])), 4),
                'y' => round(min(1, max(0, (float) $mark['y'])), 4),
                'type' => $type,
                'desc' => mb_substr(trim((string) ($mark['desc'] ?? '')), 0, 120) ?: null,
            ];
        }, $marks));

        $damage->update(['marks' => $clean]);

        return $damage;
    }

    /** Avaria lançada por engano: sai da vistoria, mas a foto fica guardada (prova). */
    public function removeDamage(Damage $damage, User $by): void
    {
        $this->assertCanEdit($damage->serviceOrder, $by);

        DB::transaction(function () use ($damage) {
            $damage->media?->update(['replaced_at' => now()]);
            AuditLog::record($damage->serviceOrder, 'inspection.damage_removed', null, ['local' => $damage->location, 'foto' => $damage->media_id]);
            $damage->delete();
        });
    }

    /**
     * O que falta na parte A (sem contar o envio ou a assinatura).
     *
     * @return list<array{label: string, tab: string, field: ?string}>
     */
    public function missing(ServiceOrder $order): array
    {
        $missing = [];
        $need = function (bool $ok, string $label, ?string $field = null) use (&$missing) {
            if (! $ok) {
                $missing[] = ['label' => $label, 'tab' => 'vistoria', 'field' => $field];
            }
        };

        $positions = $order->media()->whereIn('kind', [MediaKind::Entrada->value, MediaKind::Painel->value])->pluck('position')->all();

        foreach (self::PHOTOS as $position => $label) {
            $need(in_array($position, $positions, true), 'Foto: '.mb_strtolower(mb_substr($label, 0, 1)).mb_substr($label, 1), 'foto-'.$position);
        }

        $need($order->km_in !== null, 'Km de entrada', 'km');
        $need($order->fuel_in !== null, 'Nível de combustível', 'combustivel');
        $need(filled($order->dashboard_lights), 'Luzes acesas no painel (ou "nenhuma")', 'luzes');
        $need(filled($order->belongings), 'Objetos e acessórios no carro (ou "nenhum")', 'objetos');
        $need(collect(array_keys(self::ACCESSORIES))->every(fn ($f) => $order->{$f} !== null), 'Estepe, macaco, triângulo e chave de roda', 'acessorios');
        $need($order->no_visible_damage === true || $order->damages()->exists(), 'Avarias marcadas na foto ou "sem avarias aparentes"', 'avarias');
        $need($order->test_drive_authorized !== null, 'Autorização de test drive (sim ou não)', 'test-drive');
        $need($order->diagnosis_charged !== null && (! $order->diagnosis_charged || (int) $order->diagnosis_fee_cents > 0), 'Diagnóstico cobrado (sim ou não, e o valor)', 'diagnostico-cobrado');
        $need($this->approverOk($order), 'Quem aprova o orçamento (nome e WhatsApp)', 'aprovador');

        return $missing;
    }

    /** Assinada na tela ou enviada pelo WhatsApp, e a vistoria não mudou depois disso. */
    public function deliveredToCustomer(ServiceOrder $order): bool
    {
        $current = $this->snapshotHash($order);

        if ($order->inspection_sent_at !== null && $order->inspection_snapshot_sha256 === $current) {
            return true;
        }

        return $order->consents()->where('purpose', Consent::PURPOSE_ENTRY)->where('snapshot_sha256', $current)->exists();
    }

    /** Já foi enviada ou assinada uma versão anterior (a vistoria mudou depois). */
    public function changedAfterDelivery(ServiceOrder $order): bool
    {
        $delivered = $order->inspection_sent_at !== null || $order->consents()->where('purpose', Consent::PURPOSE_ENTRY)->exists();

        return $delivered && ! $this->deliveredToCustomer($order);
    }

    /**
     * Cópia dos dados da parte A (o que o cliente recebe ou assina), com o SHA-256 de cada foto.
     *
     * @return array<string, mixed>
     */
    public function snapshot(ServiceOrder $order): array
    {
        $order->loadMissing(['vehicle', 'customer']);
        $photos = $order->media()->whereIn('kind', [MediaKind::Entrada->value, MediaKind::Painel->value, MediaKind::Avaria->value])->get();

        return [
            'os' => $order->number,
            'placa' => $order->vehicle?->plate,
            'veiculo' => $order->vehicle?->description(),
            'cliente' => $order->customer?->name,
            'km' => $order->km_in,
            'combustivel' => $order->fuel_in,
            'luzes' => $order->dashboard_lights,
            'objetos' => $order->belongings,
            'acessorios' => collect(self::ACCESSORIES)->map(fn ($label, $field) => $order->{$field})->all(),
            'sem_avarias' => $order->no_visible_damage,
            'avarias' => $order->damages()->get()->map(fn (Damage $d) => ['local' => $d->location, 'foto' => $d->media_id, 'marcas' => $d->markList()])->all(),
            'test_drive' => $order->test_drive_authorized,
            'diagnostico_cobrado' => $order->diagnosis_charged,
            'valor_diagnostico' => $order->diagnosis_fee_cents,
            'aprovador' => [$order->approver_kind, $order->approver_name, $order->approver_whatsapp],
            'fotos' => $photos->map(fn (Media $m) => [$m->id, $m->kind->value, $m->position, $m->stamped_sha256])->all(),
        ];
    }

    public function snapshotHash(ServiceOrder $order): string
    {
        return hash('sha256', json_encode($this->snapshot($order), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function approverOk(ServiceOrder $order): bool
    {
        return match ($order->approver_kind) {
            'izi' => true,
            'cliente', 'outro' => filled($order->approver_name) && Phone::isValid($order->approver_whatsapp),
            default => false,
        };
    }

    /** @return array<string, ?string> */
    private function approver(ServiceOrder $order, string $kind, ?string $name, ?string $whatsapp): array
    {
        if (! array_key_exists($kind, self::APPROVERS)) {
            throw new DomainException('Escolha quem aprova o orçamento.');
        }

        if ($kind === 'izi') {
            if (! $order->vehicle?->is_izi_fleet) {
                throw new DomainException('Só carro da frota Izi Car é aprovado pela alçada da Izi.');
            }

            return ['approver_kind' => 'izi', 'approver_name' => null, 'approver_whatsapp' => null];
        }

        if ($kind === 'cliente') {
            $order->loadMissing('customer');

            return ['approver_kind' => 'cliente', 'approver_name' => $order->customer->name, 'approver_whatsapp' => $order->customer->whatsapp];
        }

        $digits = Phone::digits($whatsapp);

        if (mb_strlen(trim((string) $name)) < 2 || ! Phone::isValid($digits)) {
            throw new DomainException('Informe o nome e o WhatsApp de quem aprova.');
        }

        return ['approver_kind' => 'outro', 'approver_name' => mb_substr(trim((string) $name), 0, 120), 'approver_whatsapp' => $digits];
    }

    private function assertCanEdit(ServiceOrder $order, User $by): void
    {
        if (! Gate::forUser($by)->allows('atendimento')) {
            throw new DomainException('A parte A da vistoria é do atendimento.');
        }

        // Depois da etapa de vistoria ela vira registro; para corrigir, o gestor volta a OS para "Em vistoria".
        if ($order->stage !== Stage::EmVistoria) {
            throw new DomainException('A vistoria de entrada só muda na etapa "Em vistoria".');
        }
    }
}
