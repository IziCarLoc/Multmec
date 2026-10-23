<?php

namespace App\Services\Execution;

use App\Enums\MediaKind;
use App\Enums\Stage;
use App\Models\AuditLog;
use App\Models\ChecklistItem;
use App\Models\Consent;
use App\Models\Media;
use App\Models\OrderChecklistItem;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Budgets\Budgets;
use App\Services\Media\MediaStore;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Conferência (portão 2), feita por quem NÃO apontou tempo na OS. Modelo Expresso ou Completo (Ajustes), cada
 * item OK / Não se aplica / CORRIGIR; foto de cada roda com a listra quando mexeram em roda; test drive (km
 * antes e depois, hora, quem dirigiu, trajeto) quando exigido. Um CORRIGIR devolve a OS para a execução com o
 * motivo e a conferência recomeça numa rodada nova. A liberação grava o aceite de quem conferiu.
 */
final class FinalCheck
{
    public const TEMPLATES = ['expresso' => 'Expresso', 'completo' => 'Completo'];

    public const STATES = ['ok' => 'OK', 'na' => 'Não se aplica', 'corrigir' => 'CORRIGIR'];

    public const WHEELS = ['de' => 'Dianteira esquerda', 'dd' => 'Dianteira direita', 'te' => 'Traseira esquerda', 'td' => 'Traseira direita'];

    /**
     * Blocos da conferência e os "últimos 10 itens" do plano (Completo tem todos; Expresso só os marcados).
     * Semeados como "revisar": a oficina ajusta em Ajustes.
     */
    public const DEFAULTS = [
        ['Área trabalhada', 'Todos os itens aprovados foram feitos', true],
        ['Área trabalhada', 'Fixadores apertados e com a marca de torque', true],
        ['Área trabalhada', 'Sem vazamento na área trabalhada', true],
        ['Área trabalhada', 'Conectores, mangueiras e presilhas encaixados', false],
        ['Cofre e fluidos', 'Níveis de óleo, arrefecimento, freio e direção', true],
        ['Cofre e fluidos', 'Tampas de óleo, radiador e reservatórios fechadas', true],
        ['Cofre e fluidos', 'Nenhuma ferramenta, pano ou parafuso no cofre', true],
        ['Rodas e pneus', 'Parafusos de roda com torque e listra', false],
        ['Rodas e pneus', 'Pressão dos 4 pneus e do estepe', false],
        ['Rodas e pneus', 'Calotas e tampas no lugar', false],
        ['Painel e ajustes', 'Nenhuma luz de aviso acesa; luz de serviço zerada', true],
        ['Painel e ajustes', 'Relógio e rádio restaurados', false],
        ['Painel e ajustes', 'Vidros elétricos e teto reprogramados (um toque)', false],
        ['Teste final', 'Motor ligado sem ruído anormal', true],
        ['Teste final', 'Pedal de freio firme', true],
        ['Interior e acabamento', 'Bancos e retrovisores na posição do cliente', false],
        ['Interior e acabamento', 'Tapetes, volante e câmbio limpos, sem graxa', true],
        ['Interior e acabamento', 'Capas de proteção retiradas', true],
        ['Comunicação', 'Etiqueta da próxima troca colocada', false],
        ['Comunicação', 'Peças substituídas separadas para o cliente', false],
        ['Comunicação', 'Km de saída anotado; combustível igual ou anotado', true],
    ];

    public function __construct(
        private readonly WorkLog $log,
        private readonly MediaStore $store,
    ) {}

    public static function seedDefaults(): void
    {
        if (ChecklistItem::query()->where('kind', ChecklistItem::KIND_FINAL_CHECK)->exists()) {
            return;
        }

        foreach (self::DEFAULTS as $i => [$section, $label, $express]) {
            foreach ($express ? ['expresso', 'completo'] : ['completo'] as $template) {
                ChecklistItem::create([
                    'kind' => ChecklistItem::KIND_FINAL_CHECK,
                    'template' => $template,
                    'section' => $section,
                    'label' => $label,
                    'position' => $i + 1,
                    'needs_review' => true,
                ]);
            }
        }
    }

    /** Quem pode conferir: o perfil oficina (ou gestor) que não apontou tempo nesta OS. */
    public function canCheck(ServiceOrder $order, User $user): bool
    {
        return Gate::forUser($user)->allows('oficina') && ! $this->log->workedOn($order, $user);
    }

    /** Escolhe o modelo e copia os itens para a rodada atual (trocar de modelo só antes de responder). */
    public function start(ServiceOrder $order, string $template, User $by): Collection
    {
        $this->assertChecker($order, $by);

        if (! array_key_exists($template, self::TEMPLATES)) {
            throw new DomainException('Escolha o modelo da conferência.');
        }

        self::seedDefaults();

        return DB::transaction(function () use ($order, $template, $by) {
            $round = max(1, (int) $order->final_check_round);
            $current = $this->items($order);

            if ($current->contains(fn (OrderChecklistItem $i) => $i->state !== null)) {
                throw new DomainException('A conferência já começou com o modelo '.self::TEMPLATES[$order->final_check_template].'.');
            }

            OrderChecklistItem::query()->whereIn('id', $current->pluck('id'))->delete();

            foreach (ChecklistItem::query()->where('kind', ChecklistItem::KIND_FINAL_CHECK)->where('template', $template)->where('active', true)->orderBy('position')->orderBy('id')->get() as $item) {
                OrderChecklistItem::create([
                    'service_order_id' => $order->id,
                    'checklist_item_id' => $item->id,
                    'kind' => ChecklistItem::KIND_FINAL_CHECK,
                    'round' => $round,
                    'section' => $item->section,
                    'label' => $item->label,
                    'position' => $item->position,
                ]);
            }

            $order->update(['final_check_template' => $template, 'final_check_round' => $round, 'final_checker_id' => $by->id]);
            AuditLog::record($order, 'final_check.started', null, ['modelo' => $template, 'rodada' => $round]);

            return $this->items($order);
        });
    }

    /** @return Collection<int, OrderChecklistItem> */
    public function items(ServiceOrder $order): Collection
    {
        return $order->checklistItems()->where('kind', ChecklistItem::KIND_FINAL_CHECK)->where('round', max(1, (int) $order->final_check_round))->orderBy('position')->orderBy('id')->get();
    }

    public function answer(OrderChecklistItem $item, string $state, ?string $detail, User $by): OrderChecklistItem
    {
        $order = $item->serviceOrder;
        $this->assertChecker($order, $by);

        if ($item->kind !== ChecklistItem::KIND_FINAL_CHECK || $item->round !== max(1, (int) $order->final_check_round)) {
            throw new DomainException('Item de outra conferência.');
        }

        if (! array_key_exists($state, self::STATES)) {
            throw new DomainException('Escolha OK, Não se aplica ou CORRIGIR.');
        }

        $detail = mb_substr(trim((string) $detail), 0, 255) ?: null;

        if ($state === 'corrigir' && $detail === null) {
            throw new DomainException('Diga o que precisa corrigir em "'.$item->label.'".');
        }

        $item->update(['state' => $state, 'detail' => $state === 'corrigir' ? $detail : null, 'answered_by' => $by->id, 'answered_at' => now()]);

        return $item;
    }

    /** Foto da roda com a listra de torque (de, dd, te, td). */
    public function storeWheelPhoto(ServiceOrder $order, string $wheel, UploadedFile $file, ?string $hash, ?int $takenAt, User $by): Media
    {
        $this->assertChecker($order, $by);

        if (! array_key_exists($wheel, self::WHEELS)) {
            throw new DomainException('Roda inválida.');
        }

        $previous = $order->media()->where('kind', MediaKind::Roda->value)->where('position', $wheel)->whereNull('replaced_at')->latest('id')->first();

        return $this->store->storePhoto($order, MediaKind::Roda, $file, $hash, $takenAt, $by, ['position' => $wheel, 'caption' => self::WHEELS[$wheel].' com a listra', 'replaces' => $previous]);
    }

    /**
     * Test drive de saída: km antes e depois, hora de saída e de volta, quem dirigiu e o trajeto.
     *
     * @param  array{km_before: mixed, km_after: mixed, started_at: ?string, ended_at: ?string, driver_id: mixed, route: ?string}  $data
     */
    public function recordTestDrive(ServiceOrder $order, array $data, User $by): ServiceOrder
    {
        $this->assertChecker($order, $by);

        if (! $order->test_drive_authorized) {
            throw new DomainException('O cliente não autorizou test drive na entrada (parte A da vistoria).');
        }

        $before = (int) preg_replace('/\D/', '', (string) $data['km_before']);
        $after = (int) preg_replace('/\D/', '', (string) $data['km_after']);

        if ($before <= 0 || $after < $before) {
            throw new DomainException('Confira os km: o de volta não pode ser menor que o de saída.');
        }

        $started = filled($data['started_at']) ? CarbonImmutable::parse($data['started_at']) : null;
        $ended = filled($data['ended_at']) ? CarbonImmutable::parse($data['ended_at']) : null;

        if (! $started || ! $ended || $ended->lt($started)) {
            throw new DomainException('Informe a hora de saída e a de volta do test drive.');
        }

        $driver = User::query()->where('active', true)->find((int) $data['driver_id']) ?? throw new DomainException('Escolha quem dirigiu.');
        $route = mb_substr(trim((string) $data['route']), 0, 200);

        if (mb_strlen($route) < 3) {
            throw new DomainException('Descreva o trajeto do test drive.');
        }

        $order->update([
            'test_drive_km_before' => $before,
            'test_drive_km_after' => $after,
            'test_drive_started_at' => $started,
            'test_drive_ended_at' => $ended,
            'test_drive_driver_id' => $driver->id,
            'test_drive_route' => $route,
            'km_out' => $after,
        ]);
        AuditLog::record($order, 'final_check.test_drive', null, ['km' => $after - $before, 'motorista' => $driver->id]);

        return $order;
    }

    /**
     * O que falta para liberar a retirada.
     *
     * @return list<array{label: string, tab: string, field: ?string}>
     */
    public function missing(ServiceOrder $order, ?User $by = null): array
    {
        $missing = [];
        $items = $this->items($order);

        if ($by && $this->log->workedOn($order, $by)) {
            $missing[] = ['label' => 'Quem conferir não pode ter apontado tempo nesta OS', 'tab' => 'conferencia', 'field' => null];
        }

        if ($items->isEmpty()) {
            $missing[] = ['label' => 'Escolher o modelo da conferência (Expresso ou Completo)', 'tab' => 'conferencia', 'field' => 'modelo'];
        }

        foreach ($items->whereNull('state') as $item) {
            $missing[] = ['label' => 'Conferir: '.$item->label, 'tab' => 'conferencia', 'field' => 'conf-'.$item->id];
        }

        if ($items->contains('state', 'corrigir')) {
            $missing[] = ['label' => 'Há CORRIGIR: use "Corrigir (volta para execução)"', 'tab' => 'conferencia', 'field' => null];
        }

        if ($order->has_wheel_work) {
            $wheels = $order->media()->where('kind', MediaKind::Roda->value)->whereNull('replaced_at')->pluck('position')->all();

            foreach (self::WHEELS as $key => $label) {
                if (! in_array($key, $wheels, true)) {
                    $missing[] = ['label' => 'Foto da roda com a listra: '.mb_strtolower($label), 'tab' => 'conferencia', 'field' => 'rodas'];
                }
            }
        }

        if ($order->requires_test_drive && $order->test_drive_ended_at === null) {
            $missing[] = ['label' => $order->test_drive_authorized ? 'Test drive (km, hora, quem dirigiu e trajeto)' : 'Test drive exigido, mas sem autorização do cliente: fale com ele', 'tab' => 'conferencia', 'field' => 'test-drive'];
        }

        return $missing;
    }

    /** Liberação: grava o aceite de quem conferiu (as respostas, as fotos das rodas e o test drive). */
    public function release(ServiceOrder $order, User $by): Consent
    {
        $snapshot = [
            'modelo' => $order->final_check_template,
            'rodada' => $order->final_check_round,
            'itens' => $this->items($order)->map(fn (OrderChecklistItem $i) => ['secao' => $i->section, 'item' => $i->label, 'resposta' => $i->state])->all(),
            'rodas' => $order->media()->where('kind', MediaKind::Roda->value)->whereNull('replaced_at')->get()->map(fn (Media $m) => ['roda' => $m->position, 'sha256' => $m->original_sha256])->all(),
            'test_drive' => $order->test_drive_ended_at ? ['km_antes' => $order->test_drive_km_before, 'km_depois' => $order->test_drive_km_after, 'motorista' => $order->test_drive_driver_id, 'trajeto' => $order->test_drive_route] : null,
            'torque' => $order->has_wheel_work ? ['nm' => $order->torque_nm, 'torquimetro' => $order->torque_wrench] : null,
        ];

        return Consent::create([
            'service_order_id' => $order->id,
            'purpose' => Consent::PURPOSE_FINAL_CHECK,
            'method' => Consent::METHOD_CONFIRMATION,
            'signer_name' => $by->name,
            'signed_at' => now(),
            'snapshot' => $snapshot,
            'snapshot_sha256' => Budgets::hash($snapshot),
            'recorded_by' => $by->id,
        ]);
    }

    /** Nova rodada (a OS voltou da correção): as respostas da anterior ficam guardadas. */
    public function newRound(ServiceOrder $order): void
    {
        $order->update(['final_check_round' => (int) $order->final_check_round + 1, 'final_check_template' => null, 'final_checker_id' => null]);
    }

    private function assertChecker(ServiceOrder $order, User $by): void
    {
        if ($order->stage !== Stage::EmConferencia) {
            throw new DomainException('A conferência é feita com a OS "Em conferência".');
        }

        if (! Gate::forUser($by)->allows('oficina')) {
            throw new DomainException('A conferência é feita pela oficina.');
        }

        if ($this->log->workedOn($order, $by)) {
            throw new DomainException('Quem apontou tempo nesta OS não confere o serviço dela. Chame outro mecânico.');
        }
    }
}
