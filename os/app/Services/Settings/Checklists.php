<?php

namespace App\Services\Settings;

use App\Models\AuditLog;
use App\Models\ChecklistItem;
use App\Models\User;
use App\Services\Execution\FinalCheck;
use App\Services\Inspections\TechnicalChecklist;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Ajustes dos checklists (só o gestor): o técnico da entrada (parte B) e os modelos da conferência (Expresso e
 * Completo). Mudar aqui vale para as próximas OS: cada OS guarda a cópia que recebeu. Item semeado fica marcado
 * "revisar" até o gestor conferir; item desligado some das próximas OS, mas fica no histórico.
 */
final class Checklists
{
    /** Listas editáveis: chave => [kind, template, título]. */
    public const LISTS = [
        'tecnico' => [ChecklistItem::KIND_TECHNICAL, null, 'Vistoria técnica (parte B)'],
        'expresso' => [ChecklistItem::KIND_FINAL_CHECK, 'expresso', 'Conferência Expresso'],
        'completo' => [ChecklistItem::KIND_FINAL_CHECK, 'completo', 'Conferência Completo'],
    ];

    /** @return Collection<int, ChecklistItem> */
    public function items(string $list): Collection
    {
        TechnicalChecklist::seedDefaults();
        FinalCheck::seedDefaults();
        [$kind, $template] = $this->list($list);

        return ChecklistItem::query()->where('kind', $kind)->where('template', $template)->orderBy('position')->orderBy('id')->get();
    }

    /** @param array{section?: ?string, label?: ?string, is_fluid?: bool, requires_detail?: bool} $data */
    public function add(string $list, array $data, User $by): ChecklistItem
    {
        $this->assertGestor($by);
        [$kind, $template] = $this->list($list);
        [$section, $label] = $this->texts($data);

        $item = ChecklistItem::create([
            'kind' => $kind,
            'template' => $template,
            'section' => $section,
            'label' => $label,
            'is_fluid' => $kind === ChecklistItem::KIND_TECHNICAL && ! empty($data['is_fluid']),
            'requires_detail' => $kind === ChecklistItem::KIND_TECHNICAL && ! empty($data['requires_detail']),
            'position' => (int) ChecklistItem::query()->where('kind', $kind)->where('template', $template)->max('position') + 1,
        ]);
        AuditLog::record($item, 'checklist.added', null, ['lista' => $list, 'item' => $label]);

        return $item;
    }

    /** @param array{section?: ?string, label?: ?string} $data */
    public function update(ChecklistItem $item, array $data, User $by): ChecklistItem
    {
        $this->assertGestor($by);
        [$section, $label] = $this->texts($data + ['section' => $item->section, 'label' => $item->label]);
        $item->update(['section' => $section, 'label' => $label, 'needs_review' => false]);
        AuditLog::record($item, 'checklist.updated', null, ['item' => $label]);

        return $item;
    }

    public function toggle(ChecklistItem $item, User $by): ChecklistItem
    {
        $this->assertGestor($by);
        $item->update(['active' => ! $item->active]);
        AuditLog::record($item, 'checklist.toggled', null, ['ativo' => $item->active]);

        return $item;
    }

    public function reviewed(ChecklistItem $item, User $by): ChecklistItem
    {
        $this->assertGestor($by);
        $item->update(['needs_review' => false]);

        return $item;
    }

    /** Sobe (-1) ou desce (+1) o item na lista. */
    public function move(ChecklistItem $item, int $direction, User $by): void
    {
        $this->assertGestor($by);
        $siblings = ChecklistItem::query()->where('kind', $item->kind)->where('template', $item->template)->orderBy('position')->orderBy('id')->get()->values();
        $index = $siblings->search(fn (ChecklistItem $i) => $i->id === $item->id);
        $target = $index + ($direction < 0 ? -1 : 1);

        if ($index === false || $target < 0 || $target >= $siblings->count()) {
            return;
        }

        $ordered = $siblings->all();
        [$ordered[$index], $ordered[$target]] = [$ordered[$target], $ordered[$index]];

        foreach ($ordered as $position => $row) {
            if ($row->position !== $position + 1) {
                $row->update(['position' => $position + 1]);
            }
        }
    }

    /** @return array{0: string, 1: ?string} */
    private function list(string $list): array
    {
        $config = self::LISTS[$list] ?? throw new DomainException('Lista inválida.');

        return [$config[0], $config[1]];
    }

    /** @return array{0: string, 1: string} */
    private function texts(array $data): array
    {
        $section = mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($data['section'] ?? '')) ?? ''), 0, 60);
        $label = mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($data['label'] ?? '')) ?? ''), 0, 160);

        if ($section === '' || mb_strlen($label) < 3) {
            throw new DomainException('Escreva o bloco e o item.');
        }

        return [$section, $label];
    }

    private function assertGestor(User $by): void
    {
        if (! Gate::forUser($by)->allows('gestor')) {
            throw new DomainException('Os checklists são ajustados pelo gestor.');
        }
    }
}
