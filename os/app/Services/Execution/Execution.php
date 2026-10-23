<?php

namespace App\Services\Execution;

use App\Enums\MediaKind;
use App\Enums\PartOrigin;
use App\Enums\Stage;
use App\Models\AuditLog;
use App\Models\Media;
use App\Models\OrderItem;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Budgets\Budgets;
use App\Services\Media\MediaStore;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Execução com evidências: peça recebida (ou não usada), foto da peça velha e da nova, torque das rodas em Nm
 * com o nº do torquímetro, lacres com número e foto, e se o serviço exige test drive. O que falta para concluir
 * (ir para a conferência) fica em missing(), que o StageGate usa.
 */
final class Execution
{
    public const STAGES = [Stage::Aprovada, Stage::AguardandoPecas, Stage::EmExecucao];

    public function __construct(
        private readonly MediaStore $store,
        private readonly WorkLog $log,
        private readonly Budgets $budgets,
    ) {}

    /** @return Collection<int, OrderItem> */
    public function approvedParts(ServiceOrder $order): Collection
    {
        return $order->items()->with('media')->get()->filter(fn (OrderItem $i) => $i->isPart() && $i->isApproved())->values();
    }

    /** Peça aprovada que ainda não chegou (a do estoque já está na oficina). */
    public function waitsForPart(OrderItem $item): bool
    {
        return $item->isPart() && $item->isApproved() && $item->received_at === null && ! $item->not_used && $item->origin !== PartOrigin::Estoque;
    }

    public function partsPending(ServiceOrder $order): bool
    {
        return $this->approvedParts($order)->contains(fn (OrderItem $i) => $this->waitsForPart($i));
    }

    public function markReceived(OrderItem $item, bool $received, User $by): OrderItem
    {
        $this->assertPartInExecution($item, $by);
        $item->update($received ? ['received_at' => now(), 'received_by' => $by->id] : ['received_at' => null, 'received_by' => null]);
        AuditLog::record($item->serviceOrder, $received ? 'part.received' : 'part.unreceived', null, ['item' => $item->id]);

        return $item;
    }

    /** Peça aprovada que acabou não sendo usada (fica registrado; não pede foto). */
    public function markNotUsed(OrderItem $item, bool $notUsed, User $by): OrderItem
    {
        $this->assertPartInExecution($item, $by);
        $item->update(['not_used' => $notUsed]);
        AuditLog::record($item->serviceOrder, 'part.not_used', null, ['item' => $item->id, 'nao_usada' => $notUsed]);

        return $item;
    }

    /** Foto da peça velha ("velha") ou da nova ("nova"), ligada ao item. A anterior fica guardada como refeita. */
    public function storePartPhoto(OrderItem $item, string $which, UploadedFile $file, ?string $hash, ?int $takenAt, User $by): Media
    {
        $this->assertMechanic($item->serviceOrder, $by);

        if (! $item->isPart() || ! $item->isApproved()) {
            throw new DomainException('Foto de peça é para peça aprovada.');
        }

        $kind = $which === 'velha' ? MediaKind::PecaVelha : MediaKind::PecaNova;
        $previous = $item->media()->where('kind', $kind->value)->latest('id')->first();

        return $this->store->storePhoto($item->serviceOrder, $kind, $file, $hash, $takenAt, $by, [
            'os_item_id' => $item->id,
            'position' => (string) $item->id,
            'caption' => mb_substr($item->description, 0, 120),
            'replaces' => $previous,
        ]);
    }

    public function storeSeal(ServiceOrder $order, string $number, UploadedFile $file, ?string $hash, ?int $takenAt, User $by): Media
    {
        $this->assertMechanic($order, $by);
        $number = mb_substr(trim($number), 0, 30);

        if ($number === '') {
            throw new DomainException('Escreva o número do lacre.');
        }

        return $this->store->storePhoto($order, MediaKind::Lacre, $file, $hash, $takenAt, $by, ['position' => $number, 'caption' => 'Lacre nº '.$number]);
    }

    /**
     * Rodas e test drive (o mecânico marca se mexeu em roda; o torque é o que ele mediu, nunca um valor padrão).
     *
     * @param  array{has_wheel_work?: bool, torque_nm?: mixed, torque_wrench?: ?string, requires_test_drive?: bool}  $data
     */
    public function update(ServiceOrder $order, array $data, User $by): ServiceOrder
    {
        if (! Gate::forUser($by)->any(['oficina', 'atendimento'])) {
            throw new DomainException('Sem permissão.');
        }

        if (! in_array($order->stage, self::STAGES, true)) {
            throw new DomainException('Isso é registrado durante a execução.');
        }

        // O torque é medido pelo mecânico, com o torquímetro na mão: o atendimento não digita (CLAUDE.md).
        if ((array_key_exists('torque_nm', $data) || array_key_exists('torque_wrench', $data)) && ! Gate::forUser($by)->allows('oficina')) {
            throw new DomainException('O torque é digitado pelo mecânico que apertou as rodas.');
        }

        $values = [];

        if (array_key_exists('has_wheel_work', $data)) {
            $values['has_wheel_work'] = (bool) $data['has_wheel_work'];
        }

        if (array_key_exists('torque_nm', $data)) {
            $nm = $data['torque_nm'] === null || $data['torque_nm'] === '' ? null : (int) $data['torque_nm'];

            if ($nm !== null && ($nm < 1 || $nm > 1000)) {
                throw new DomainException('Torque fora do normal: confira o valor em Nm.');
            }

            $values['torque_nm'] = $nm;
        }

        if (array_key_exists('torque_wrench', $data)) {
            $values['torque_wrench'] = mb_substr(trim((string) $data['torque_wrench']), 0, 30) ?: null;
        }

        if (array_key_exists('requires_test_drive', $data)) {
            $values['requires_test_drive'] = (bool) $data['requires_test_drive'];
        }

        $order->fill($values);

        if ($order->isDirty()) {
            AuditLog::record($order, 'execution.updated', null, $order->getDirty());
            $order->save();
        }

        return $order;
    }

    /**
     * O que falta para concluir o serviço (ir para a conferência).
     *
     * @return list<array{label: string, tab: string, field: ?string}>
     */
    public function missing(ServiceOrder $order): array
    {
        $missing = [];

        foreach ($this->approvedParts($order) as $part) {
            if ($part->not_used) {
                continue;
            }

            if ($this->waitsForPart($part)) {
                $missing[] = ['label' => 'Peça recebida (ou não usada): '.$part->description, 'tab' => 'execucao', 'field' => 'peca-'.$part->id];
            }

            if ($part->requires_photos) {
                if (! $part->media->contains('kind', MediaKind::PecaVelha)) {
                    $missing[] = ['label' => 'Foto da peça velha: '.$part->description, 'tab' => 'execucao', 'field' => 'peca-'.$part->id];
                }

                if (! $part->media->contains('kind', MediaKind::PecaNova)) {
                    $missing[] = ['label' => 'Foto da peça nova: '.$part->description, 'tab' => 'execucao', 'field' => 'peca-'.$part->id];
                }
            }
        }

        if ($order->has_wheel_work && ($order->torque_nm === null || blank($order->torque_wrench))) {
            $missing[] = ['label' => 'Torque das rodas (Nm) e nº do torquímetro', 'tab' => 'execucao', 'field' => 'torque'];
        }

        if ($this->budgets->pending($order)) {
            $missing[] = ['label' => 'Adendo esperando a resposta do cliente', 'tab' => 'orcamento', 'field' => 'resposta'];
        }

        foreach ($this->budgets->draftItems($order) as $item) {
            $missing[] = ['label' => 'Item novo sem adendo enviado: '.$item->description, 'tab' => 'orcamento', 'field' => 'montagem'];
        }

        foreach ($this->log->open($order) as $entry) {
            $missing[] = ['label' => 'Apontamento aberto: '.$entry->user->short_name.' (pausar)', 'tab' => 'execucao', 'field' => 'apontamento'];
        }

        return $missing;
    }

    private function assertPartInExecution(OrderItem $item, User $by): void
    {
        if (! Gate::forUser($by)->any(['oficina', 'atendimento'])) {
            throw new DomainException('Sem permissão.');
        }

        if (! $item->isPart() || ! $item->isApproved()) {
            throw new DomainException('Só peça aprovada pelo cliente.');
        }

        if (! in_array($item->serviceOrder->stage, self::STAGES, true)) {
            throw new DomainException('Peça recebida é marcada entre a aprovação e o fim do serviço.');
        }
    }

    private function assertMechanic(ServiceOrder $order, User $by): void
    {
        if (! Gate::forUser($by)->allows('oficina')) {
            throw new DomainException('As fotos do serviço são do mecânico.');
        }

        if ($order->stage !== Stage::EmExecucao) {
            throw new DomainException('As fotos do serviço são tiradas com a OS "Em execução".');
        }
    }
}
