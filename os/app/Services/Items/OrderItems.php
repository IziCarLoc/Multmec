<?php

namespace App\Services\Items;

use App\Enums\PartOrigin;
use App\Enums\Stage;
use App\Models\AuditLog;
use App\Models\OrderItem;
use App\Models\ServiceOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Budgets\Budgets;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Peças e serviços da OS. O mecânico lança no diagnóstico (descrição, quantidade, horas de cada serviço,
 * necessário ou recomendado, detalhe para cotar); o atendimento completa a origem da peça e o prestador
 * externo do serviço, com o custo. Mecânico nunca grava nem vê custo.
 */
final class OrderItems
{
    /** Campos que o mecânico pode mexer. */
    private const TECHNICAL = ['description', 'quantity', 'priority', 'hours', 'part_code', 'preferred_brand', 'accepted_brands', 'quote_detail', 'requires_photos'];

    /** Campos do atendimento (além dos técnicos): origem, fornecedor/prestador, custo e o preço ao cliente. */
    private const COMMERCIAL = ['origin', 'supplier_id', 'cost_cents', 'price_cents'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function add(ServiceOrder $order, string $kind, array $data, User $by): OrderItem
    {
        $this->assertCanEdit($order, $by);

        if (! in_array($kind, [OrderItem::KIND_PART, OrderItem::KIND_SERVICE], true)) {
            throw new DomainException('Escolha se é peça ou serviço.');
        }

        $values = $this->clean(new OrderItem(['kind' => $kind]), $data, $by);

        return DB::transaction(function () use ($order, $kind, $values, $by) {
            $item = OrderItem::create($values + [
                'service_order_id' => $order->id,
                'kind' => $kind,
                'needs_quote' => $kind === OrderItem::KIND_PART,
                'requires_photos' => $kind === OrderItem::KIND_PART,
                'mechanic_id' => $by->isMecanico() ? $by->id : null,
                'created_by' => $by->id,
                'position' => (int) $order->items()->max('position') + 1,
            ]);

            AuditLog::record($order, 'item.added', null, ['item' => $item->id, 'tipo' => $kind, 'descricao' => $item->description]);

            return $item;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(OrderItem $item, array $data, User $by): OrderItem
    {
        $order = $item->serviceOrder;
        $this->assertNotFrozen($item);
        $this->assertCanEdit($order, $by);

        $item->fill($this->clean($item, $data, $by));

        if ($item->isDirty()) {
            $changes = collect($item->getDirty())->map(fn ($v, $k) => ['antes' => $item->getOriginal($k) instanceof PartOrigin ? $item->getOriginal($k)->value : $item->getOriginal($k), 'depois' => $v instanceof PartOrigin ? $v->value : $v])->all();
            $item->save();
            AuditLog::record($order, 'item.updated', null, ['item' => $item->id] + $changes);
        }

        return $item;
    }

    public function remove(OrderItem $item, User $by): void
    {
        $order = $item->serviceOrder;
        $this->assertNotFrozen($item);
        $this->assertCanEdit($order, $by);

        AuditLog::record($order, 'item.removed', null, ['item' => $item->id, 'descricao' => $item->description]);
        $item->delete();
    }

    /**
     * Serviços já lançados nas OS, do mais comum para o menos (vira o tempário próprio com o tempo).
     *
     * @return Collection<int, array{description: string, count: int, hours: ?float}>
     */
    public function frequentServices(int $limit = 40): Collection
    {
        return OrderItem::query()
            ->where('kind', OrderItem::KIND_SERVICE)
            ->selectRaw('description, count(*) as total, avg(hours) as avg_hours')
            ->groupBy('description')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => ['description' => $row->description, 'count' => (int) $row->total, 'hours' => $row->avg_hours !== null ? round((float) $row->avg_hours, 1) : null]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clean(OrderItem $item, array $data, User $by): array
    {
        $commercial = Gate::forUser($by)->allows('atendimento');
        $allowed = $commercial ? [...self::TECHNICAL, ...self::COMMERCIAL] : self::TECHNICAL;

        if (! $commercial && array_intersect(array_keys($data), self::COMMERCIAL) !== []) {
            throw new DomainException('Custo, preço, origem e fornecedor são lançados pelo atendimento.');
        }

        $values = [];

        foreach (array_intersect_key($data, array_flip($allowed)) as $field => $value) {
            $values[$field] = match ($field) {
                'description' => $this->description($value),
                'quantity' => $this->decimal($value, 'Quantidade', 0.01, 9999),
                'hours' => $value === null || $value === '' ? null : $this->decimal($value, 'Horas', 0.1, 200),
                'priority' => array_key_exists((string) $value, OrderItem::PRIORITIES) ? $value : throw new DomainException('Necessário ou recomendado?'),
                'origin' => $value === null || $value === '' ? null : (PartOrigin::tryFrom((string) $value) ?? throw new DomainException('Origem da peça inválida.')),
                'supplier_id' => $this->supplier($value, $item),
                'cost_cents', 'price_cents' => $value === null || $value === '' ? null : max(0, (int) $value),
                'requires_photos' => (bool) $value,
                default => mb_substr(trim((string) $value), 0, $field === 'quote_detail' ? 255 : ($field === 'accepted_brands' ? 120 : 60)) ?: null,
            };
        }

        if (array_key_exists('origin', $values)) {
            $values['needs_quote'] = ! ($values['origin']?->skipsQuote() ?? false);
        }

        if (array_key_exists('description', $values) === false && ! $item->exists) {
            throw new DomainException('Descreva a peça ou o serviço.');
        }

        return $values;
    }

    private function description(mixed $value): string
    {
        $text = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');

        if (mb_strlen($text) < 2) {
            throw new DomainException('Descreva a peça ou o serviço.');
        }

        return mb_substr($text, 0, 200);
    }

    private function decimal(mixed $value, string $label, float $min, float $max): float
    {
        $number = (float) str_replace(',', '.', (string) $value);

        if ($number < $min || $number > $max) {
            throw new DomainException($label.' fora do normal.');
        }

        return round($number, 2);
    }

    private function supplier(mixed $value, OrderItem $item): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $supplier = Supplier::query()->find((int) $value) ?? throw new DomainException('Fornecedor não encontrado.');

        if ($item->isService() && ! $supplier->kind->providesService()) {
            throw new DomainException($supplier->name.' não está cadastrado como prestador de serviço.');
        }

        return $supplier->id;
    }

    /**
     * Itens mudam no diagnóstico e na montagem do orçamento. Com o orçamento no cliente, nada muda; depois do
     * aceite, item novo vira adendo (o mecânico lança o que achou, o atendimento cota e põe o preço).
     */
    private function assertCanEdit(ServiceOrder $order, User $by): void
    {
        if (! Gate::forUser($by)->any(['oficina', 'atendimento'])) {
            throw new DomainException('Sem permissão para mexer nos itens.');
        }

        if (! in_array($order->stage, self::editableStages($by), true)) {
            throw new DomainException(match (true) {
                $order->stage === Stage::AguardandoCotacao => 'No orçamento, quem corrige os itens é o atendimento.',
                $order->stage === Stage::AguardandoAprovacao => 'O orçamento está com o cliente. Item novo entra depois do aceite, como adendo (ou o gestor volta a etapa).',
                default => 'Nesta etapa os itens não mudam mais.',
            });
        }
    }

    /** @return list<Stage> */
    public static function editableStages(User $by): array
    {
        $base = Gate::forUser($by)->allows('atendimento')
            ? [Stage::EmVistoria, Stage::EmDiagnostico, Stage::AguardandoCotacao]
            : [Stage::EmVistoria, Stage::EmDiagnostico];

        return [...$base, ...Budgets::ADDENDUM_STAGES];
    }

    private function assertNotFrozen(OrderItem $item): void
    {
        if ($item->isFrozen()) {
            throw new DomainException('"'.$item->description.'" já foi para o cliente no orçamento e não muda mais. Para refazer, o gestor volta a etapa.');
        }
    }
}
