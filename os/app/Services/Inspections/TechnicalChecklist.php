<?php

namespace App\Services\Inspections;

use App\Enums\Stage;
use App\Models\ChecklistItem;
use App\Models\Media;
use App\Models\OrderChecklistItem;
use App\Models\ServiceOrder;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Vistoria de entrada, parte B (mecânico): os itens técnicos (Elétrica, Motor, Suspensão), um toque por item.
 * Troca ou nível baixo pedem foto; lâmpada com troca pede dizer qual. A lista é copiada de Ajustes para a OS
 * na primeira vez que alguém abre a parte B (mudar Ajustes depois não muda OS antigas).
 */
final class TechnicalChecklist
{
    /** Lista padrão do v12 (21/10): seção, item, é fluido, pede detalhe na troca. */
    public const DEFAULTS = [
        ['Elétrica', 'Lâmpadas', false, true],
        ['Elétrica', 'Palhetas do limpador', false, false],
        ['Motor', 'Água do radiador (arrefecimento)', true, false],
        ['Motor', 'Óleo do motor', true, false],
        ['Motor', 'Filtro de ar', false, false],
        ['Motor', 'Fluido de freio', true, false],
        ['Motor', 'Fluido da direção hidráulica', true, false],
        ['Motor', 'Água do limpador', true, false],
        ['Motor', 'Bateria', false, false],
        ['Motor', 'Cabo da bateria', false, false],
        ['Suspensão', 'Dianteira direita', false, false],
        ['Suspensão', 'Dianteira esquerda', false, false],
        ['Suspensão', 'Traseira direita', false, false],
        ['Suspensão', 'Traseira esquerda', false, false],
    ];

    /** Cria a configuração padrão em Ajustes, se ainda não houver (seeder e primeira OS). */
    public static function seedDefaults(): void
    {
        if (ChecklistItem::query()->where('kind', ChecklistItem::KIND_TECHNICAL)->exists()) {
            return;
        }

        foreach (self::DEFAULTS as $i => [$section, $label, $fluid, $detail]) {
            ChecklistItem::create([
                'kind' => ChecklistItem::KIND_TECHNICAL,
                'section' => $section,
                'label' => $label,
                'is_fluid' => $fluid,
                'requires_detail' => $detail,
                'position' => $i + 1,
            ]);
        }
    }

    /** @return Collection<int, OrderChecklistItem> */
    public function itemsFor(ServiceOrder $order): Collection
    {
        if (! $order->checklistItems()->where('kind', ChecklistItem::KIND_TECHNICAL)->exists()) {
            self::seedDefaults();

            DB::transaction(function () use ($order) {
                // Outra aba pode ter criado no mesmo instante: confere de novo dentro da transação.
                if (ServiceOrder::query()->lockForUpdate()->find($order->id) && ! $order->checklistItems()->where('kind', ChecklistItem::KIND_TECHNICAL)->exists()) {
                    foreach (ChecklistItem::query()->technical()->get() as $item) {
                        OrderChecklistItem::create([
                            'service_order_id' => $order->id,
                            'checklist_item_id' => $item->id,
                            'kind' => ChecklistItem::KIND_TECHNICAL,
                            'section' => $item->section,
                            'label' => $item->label,
                            'is_fluid' => $item->is_fluid,
                            'requires_detail' => $item->requires_detail,
                            'position' => $item->position,
                        ]);
                    }
                }
            });
        }

        return $order->checklistItems()->with(['media', 'answerer'])->where('kind', ChecklistItem::KIND_TECHNICAL)->get();
    }

    public function answer(OrderChecklistItem $item, string $state, ?string $detail, User $by): OrderChecklistItem
    {
        $this->assertCanEdit($item, $by);

        if (! array_key_exists($state, $item->options())) {
            throw new DomainException('Resposta inválida para "'.$item->label.'".');
        }

        $item->fill([
            'state' => $state,
            'detail' => in_array($state, ['troca', 'baixo'], true) ? (mb_substr(trim((string) $detail), 0, 255) ?: $item->detail) : null,
            'answered_by' => $by->id,
            'answered_at' => now(),
        ]);

        // Voltou a ficar conforme: a foto da não conformidade sai do item (continua guardada na OS).
        if (! $item->isNonConformity()) {
            $item->media_id = null;
        }

        $item->save();

        return $item;
    }

    public function detail(OrderChecklistItem $item, ?string $detail, User $by): OrderChecklistItem
    {
        $this->assertCanEdit($item, $by);
        $item->update(['detail' => mb_substr(trim((string) $detail), 0, 255) ?: null]);

        return $item;
    }

    public function attachPhoto(OrderChecklistItem $item, Media $media, User $by): OrderChecklistItem
    {
        $this->assertCanAttachPhoto($item, $by);
        $item->update(['media_id' => $media->id]);

        return $item;
    }

    /** Conferido antes de guardar a foto: a foto anterior só é substituída se a nova vai mesmo para o item. */
    public function assertCanAttachPhoto(OrderChecklistItem $item, User $by): void
    {
        $this->assertCanEdit($item, $by);

        if (! $item->isNonConformity()) {
            throw new DomainException('Foto só para item com troca ou nível baixo.');
        }
    }

    /** "Marcar o resto como em funcionamento": só os itens comuns (fluido precisa do nível de verdade). */
    public function markRemainingOk(ServiceOrder $order, User $by): int
    {
        $items = $this->itemsFor($order)->filter(fn (OrderChecklistItem $item) => $item->state === null && ! $item->is_fluid);

        foreach ($items as $item) {
            $this->answer($item, 'ok', null, $by);
        }

        return $items->count();
    }

    /**
     * @return list<array{label: string, tab: string, field: ?string}>
     */
    public function missing(ServiceOrder $order): array
    {
        $items = $order->checklistItems()->where('kind', ChecklistItem::KIND_TECHNICAL)->get();

        if ($items->isEmpty()) {
            return [['label' => 'Checklist técnico (parte B)', 'tab' => 'vistoria', 'field' => 'parte-b']];
        }

        $missing = [];
        $open = $items->whereNull('state')->count();

        if ($open > 0) {
            $missing[] = ['label' => 'Checklist técnico: '.$open.' '.($open === 1 ? 'item sem resposta' : 'itens sem resposta'), 'tab' => 'vistoria', 'field' => 'parte-b'];
        }

        foreach ($items as $item) {
            if ($item->isNonConformity() && $item->media_id === null) {
                $missing[] = ['label' => 'Foto do item "'.$item->label.'" ('.mb_strtolower($item->stateLabel()).')', 'tab' => 'vistoria', 'field' => 'item-'.$item->id];
            }

            if ($item->requires_detail && $item->state === 'troca' && blank($item->detail)) {
                $missing[] = ['label' => 'Dizer qual '.mb_strtolower(rtrim($item->label, 's')).' precisa de troca', 'tab' => 'vistoria', 'field' => 'item-'.$item->id];
            }
        }

        return $missing;
    }

    private function assertCanEdit(OrderChecklistItem $item, User $by): void
    {
        if (! Gate::forUser($by)->allows('oficina')) {
            throw new DomainException('A parte B da vistoria é do mecânico.');
        }

        $order = ServiceOrder::query()->findOrFail($item->service_order_id);

        if ($item->kind === ChecklistItem::KIND_TECHNICAL && $order->stage !== Stage::EmVistoria) {
            throw new DomainException('O checklist técnico da entrada só muda na etapa "Em vistoria".');
        }
    }
}
