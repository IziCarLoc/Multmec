<?php

namespace App\Services\Budgets;

use App\Enums\Stage;
use App\Models\AuditLog;
use App\Models\Budget;
use App\Models\Consent;
use App\Models\Media;
use App\Models\OrderItem;
use App\Models\PublicLink;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Inspections\SignatureImage;
use App\Services\Orders\StageMachine;
use App\Services\Quotes\Quotations;
use App\Support\Document;
use App\Support\Money;
use App\Support\Phone;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Orçamento (portão 1). O atendimento põe o preço de cada item; "Enviar orçamento" congela o que vai para o
 * cliente (cópia dos itens, preços, condições e textos, com SHA-256). O aceite é registrado de um destes
 * jeitos: link (o cliente responde sozinho), mensagem do cliente colada (com print opcional), presencial com
 * documento, assinatura na tela, ou a alçada da Izi Car (até o limite, o atendimento; acima, o gestor).
 * O cliente pode aprovar só parte dos itens. Item que aparece depois do aceite vira adendo, com aceite novo.
 */
final class Budgets
{
    /** Etapas em que item novo vira adendo. */
    public const ADDENDUM_STAGES = [Stage::Aprovada, Stage::AguardandoPecas, Stage::EmExecucao];

    public function __construct(private readonly Quotations $quotations) {}

    /** Itens que ainda não foram para o cliente (os do próximo orçamento ou adendo). */
    public function draftItems(ServiceOrder $order): Collection
    {
        return $order->items()->with('supplier')->get()->reject(fn (OrderItem $item) => $item->isFrozen())->values();
    }

    /** Orçamento ou adendo esperando a resposta do cliente. */
    public function pending(ServiceOrder $order): ?Budget
    {
        return $order->budgets()->where('status', Budget::STATUS_SENT)->reorder('number', 'desc')->first();
    }

    /** O orçamento principal mais recente (não cancelado). */
    public function main(ServiceOrder $order): ?Budget
    {
        return $order->budgets()->where('kind', Budget::KIND_BUDGET)->where('status', '!=', Budget::STATUS_CANCELLED)->reorder('number', 'desc')->first();
    }

    public static function isAddendumStage(Stage $stage): bool
    {
        return in_array($stage, self::ADDENDUM_STAGES, true);
    }

    /**
     * O que falta para enviar o orçamento (ou o adendo): preço em cada item, vencedor da cotação em cada peça,
     * custo do prestador e, no orçamento principal, a previsão de entrega e a forma de pagamento.
     *
     * @return list<array{label: string, tab: string, field: ?string}>
     */
    public function missing(ServiceOrder $order): array
    {
        $items = $this->draftItems($order);
        $addendum = self::isAddendumStage($order->stage);
        $missing = [];

        if ($items->isEmpty()) {
            $missing[] = ['label' => $addendum ? 'Ao menos um item novo para o adendo' : 'Ao menos uma peça ou serviço', 'tab' => 'itens', 'field' => 'novo-item'];
        }

        $missing = array_merge($missing, $this->quotations->missing($order, $items));

        foreach ($items as $item) {
            if ($item->price_cents === null) {
                $missing[] = ['label' => 'Preço: '.$item->description, 'tab' => 'orcamento', 'field' => 'preco-'.$item->id];
            }
        }

        if (! $addendum) {
            if ($order->expected_date === null) {
                $missing[] = ['label' => 'Previsão de entrega', 'tab' => 'orcamento', 'field' => 'expectedDate'];
            }

            if (blank($order->payment_terms)) {
                $missing[] = ['label' => 'Forma de pagamento', 'tab' => 'orcamento', 'field' => 'paymentTerms'];
            }
        }

        return $missing;
    }

    /**
     * @return array{parts: int, labor: int, total: int}
     */
    public function totals(Collection $items): array
    {
        $parts = (int) $items->filter(fn (OrderItem $i) => $i->isPart())->sum(fn (OrderItem $i) => (int) $i->lineTotalCents());
        $labor = (int) $items->filter(fn (OrderItem $i) => $i->isService())->sum(fn (OrderItem $i) => (int) $i->lineTotalCents());

        return ['parts' => $parts, 'labor' => $labor, 'total' => $parts + $labor];
    }

    /** Garantia do orçamento: o modelo de Ajustes com os dias, o km e a cor da marcação de torque. */
    public function warrantyText(): string
    {
        $km = Settings::int('warranty_km');

        return strtr(Settings::text('warranty'), [
            '{dias}' => (string) (Settings::int('warranty_days') ?? 90),
            '{km}' => $km ? ' ou '.number_format($km, 0, ',', '.').' km, o que vier primeiro' : '',
            '{cor_torque}' => (string) (Settings::get('torque_mark_color') ?: '[cor a definir]'),
        ]);
    }

    /**
     * O que vai para o cliente, congelado: é daqui que saem o PDF, o resumo e a tela do link.
     *
     * @return array<string, mixed>
     */
    public function snapshot(ServiceOrder $order, Collection $items, string $kind, int $number): array
    {
        $order->loadMissing(['vehicle', 'customer', 'unit']);
        $totals = $this->totals($items);

        return [
            'tipo' => $kind,
            'numero' => $number,
            'os' => $order->label(),
            'oficina' => [
                'nome' => $order->unit?->trade_name,
                'cnpj' => $order->unit?->cnpjFormatted(),
                'endereco' => trim(($order->unit?->address ?? '').($order->unit?->city ? ' · '.$order->unit->city : '')),
                'telefone' => $order->unit?->phone,
            ],
            'cliente' => $order->customer->name,
            'veiculo' => [
                'placa' => $order->vehicle->plateFormatted(),
                'descricao' => $order->vehicle->description(),
                'km' => $order->km_in,
            ],
            'entrada' => $order->entry_date?->format('Y-m-d'),
            'diagnostico' => $order->diagnosis,
            'itens' => $items->map(fn (OrderItem $item) => [
                'id' => $item->id,
                'tipo' => $item->kind,
                'descricao' => $item->description,
                'quantidade' => (float) $item->quantity,
                'unitario' => (int) $item->price_cents,
                'total' => (int) $item->lineTotalCents(),
                'origem' => $item->isPart() ? $item->origin?->shortLabel() : null,
                'nao_original' => $item->isPart() && ($item->origin?->needsAuthorization() ?? false),
                'marca' => $item->isPart() ? $item->brand : null,
                'prioridade' => $item->priority,
                'prazo' => $item->isPart() ? $item->lead_time : null,
            ])->values()->all(),
            'pecas' => $totals['parts'],
            'mao_de_obra' => $totals['labor'],
            'total' => $totals['total'],
            'pagamento' => $order->payment_terms,
            'previsao' => $order->expected_date?->format('Y-m-d'),
            'validade_dias' => (int) (Settings::int('budget_validity_days') ?? 10),
            'observacoes' => $order->budget_notes,
            'garantia' => $this->warrantyText(),
            'texto_aceite' => Settings::text('approval'),
            'tem_nao_original' => $items->contains(fn (OrderItem $i) => $i->isPart() && ($i->origin?->needsAuthorization() ?? false)),
        ];
    }

    public static function hash(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Congela o orçamento (ao entrar em "Aguardando aprovação") ou o adendo: cria a cópia com o SHA-256 e marca
     * os itens. Daqui em diante esses itens não mudam (o gestor volta a etapa para refazer).
     */
    public function freeze(ServiceOrder $order, User $by, string $kind = Budget::KIND_BUDGET): Budget
    {
        if ($this->pending($order)) {
            throw new DomainException('Já há um orçamento esperando a resposta do cliente. Registre a resposta antes.');
        }

        $items = $this->draftItems($order);

        if ($items->isEmpty()) {
            throw new DomainException('Nenhum item novo para enviar.');
        }

        return DB::transaction(function () use ($order, $by, $kind, $items) {
            $number = (int) $order->budgets()->max('number') + 1;
            $snapshot = $this->snapshot($order, $items, $kind, $number);

            $budget = Budget::create([
                'service_order_id' => $order->id,
                'number' => $number,
                'kind' => $kind,
                'status' => Budget::STATUS_SENT,
                'snapshot' => $snapshot,
                'snapshot_sha256' => self::hash($snapshot),
                'parts_cents' => $snapshot['pecas'],
                'labor_cents' => $snapshot['mao_de_obra'],
                'total_cents' => $snapshot['total'],
                'payment_terms' => $order->payment_terms,
                'expected_date' => $order->expected_date,
                'frozen_at' => now(),
                'frozen_by' => $by->id,
            ]);

            OrderItem::query()->whereIn('id', $items->pluck('id'))->update(['budget_id' => $budget->id, 'approval' => null]);
            AuditLog::record($order, 'budget.frozen', null, ['orcamento' => $budget->title(), 'total' => $budget->total_cents, 'sha256' => $budget->snapshot_sha256]);

            return $budget;
        });
    }

    /**
     * Condições do orçamento em montagem: previsão de entrega, forma de pagamento e observações.
     *
     * @param  array{expected_date?: ?string, payment_terms?: ?string, budget_notes?: ?string}  $data
     */
    public function updateConditions(ServiceOrder $order, array $data, User $by): ServiceOrder
    {
        $this->assertAtendimento($by);

        if (! in_array($order->stage, [Stage::EmDiagnostico, Stage::AguardandoCotacao], true)) {
            throw new DomainException('As condições mudam enquanto o orçamento é montado.');
        }

        $expected = filled($data['expected_date'] ?? null) ? CarbonImmutable::parse((string) $data['expected_date'])->startOfDay() : null;

        if ($expected && $order->entry_date && $expected->lt($order->entry_date)) {
            throw new DomainException('A previsão de entrega ficou antes da entrada do carro.');
        }

        $order->fill([
            'expected_date' => $expected?->toDateString(),
            'payment_terms' => mb_substr(trim((string) ($data['payment_terms'] ?? '')), 0, 200) ?: null,
            'budget_notes' => mb_substr(trim((string) ($data['budget_notes'] ?? '')), 0, 2000) ?: null,
        ]);

        if ($order->isDirty()) {
            $changes = collect($order->getDirty())->map(fn ($v, $k) => ['antes' => $order->getOriginal($k) instanceof \DateTimeInterface ? $order->getOriginal($k)->format('Y-m-d') : $order->getOriginal($k), 'depois' => $v])->all();
            $order->save();
            AuditLog::record($order, 'budget.conditions', null, $changes);
        }

        return $order;
    }

    /** Adendo: item que apareceu depois do aceite (ex.: "achei outra coisa"). Não muda a etapa da OS. */
    public function sendAddendum(ServiceOrder $order, User $by): Budget
    {
        $this->assertAtendimento($by);

        if (! self::isAddendumStage($order->stage)) {
            throw new DomainException('Adendo é para item novo depois do aceite (OS aprovada, aguardando peças ou em execução).');
        }

        $missing = $this->missing($order);

        if ($missing !== []) {
            throw new DomainException('Falta para enviar o adendo: '.implode('; ', array_column($missing, 'label')).'.');
        }

        return $this->freeze($order, $by, Budget::KIND_ADDENDUM);
    }

    /** Destinatário: quem aprova (outra pessoa, o investidor/Izi no carro da frota) ou o próprio cliente. */
    public function recipientPhone(ServiceOrder $order): ?string
    {
        $order->loadMissing(['vehicle', 'customer']);

        return match (true) {
            $order->approver_kind === 'izi' || (bool) $order->customer->is_izi_fleet => $order->vehicle->investor_whatsapp ?: Settings::get('izi_whatsapp'),
            $order->approver_kind === 'outro' && filled($order->approver_whatsapp) => $order->approver_whatsapp,
            default => $order->customer->whatsapp,
        };
    }

    /** Mensagem do WhatsApp que acompanha o PDF (ou o link). */
    public function message(Budget $budget, ?string $link = null): string
    {
        $s = $budget->snapshot;
        $order = $budget->serviceOrder;
        $name = $order->approver_kind === 'outro' ? $order->approver_name : ($order->customer->is_izi_fleet ? null : $s['cliente']);
        $first = trim(strtok((string) $name, ' ') ?: '');

        $lines = [
            'Olá'.($first !== '' ? ', '.$first : '').'! Segue o '.mb_strtolower($budget->title()).' da '.$s['os'].' ('.$s['veiculo']['descricao'].' placa '.$s['veiculo']['placa'].'): total '.Money::brl($s['total']).'.',
        ];

        if ($budget->valid_until) {
            $lines[] = 'Válido até '.$budget->valid_until->format('d/m/Y').'.';
        }

        $lines[] = $link
            ? 'Para aprovar ou recusar, toque no link: '.$link
            : 'Para aprovar, é só responder esta mensagem dizendo quais itens podemos fazer.';

        return implode("\n", $lines);
    }

    /** "Copiar resumo" para o cliente: os itens, os valores e as condições em texto. */
    public function summary(Budget $budget): string
    {
        $s = $budget->snapshot;
        $lines = ['*'.$budget->title().' · '.$s['os'].'* · '.($s['oficina']['nome'] ?? 'Oficina'), $s['veiculo']['descricao'].' · placa '.$s['veiculo']['placa'], ''];

        foreach (['peca' => 'Peças', 'servico' => 'Serviços'] as $kind => $title) {
            $items = array_values(array_filter($s['itens'], fn ($i) => $i['tipo'] === $kind));

            if ($items === []) {
                continue;
            }

            $lines[] = '*'.$title.'*';

            foreach ($items as $i => $item) {
                $lines[] = ($i + 1).'. '.$item['descricao']
                    .($kind === 'peca' ? ' · '.self::quantity($item['quantidade']).' un' : '')
                    .($item['origem'] ? ' · '.$item['origem'] : '')
                    .($item['prioridade'] === 'recomendado' ? ' · recomendado' : '')
                    .' · '.Money::brl($item['total']);
            }

            $lines[] = '';
        }

        $lines[] = 'Peças: '.Money::brl($s['pecas']).' · Mão de obra: '.Money::brl($s['mao_de_obra']);
        $lines[] = '*Total: '.Money::brl($s['total']).'*';

        if ($s['pagamento']) {
            $lines[] = 'Pagamento: '.$s['pagamento'];
        }

        if ($s['previsao']) {
            $lines[] = 'Previsão de entrega: '.CarbonImmutable::parse($s['previsao'])->format('d/m/Y');
        }

        $lines[] = $budget->valid_until ? 'Válido até '.$budget->valid_until->format('d/m/Y') : 'Validade: '.$s['validade_dias'].' dias a partir do recebimento';

        if ($s['tem_nao_original']) {
            $lines[] = 'Há peça não original (paralela ou usada): ela só é aplicada com a sua autorização.';
        }

        return implode("\n", $lines);
    }

    public static function quantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 2, ',', ''), '0'), ',');
    }

    public function whatsappLink(Budget $budget, ?string $link = null): ?string
    {
        return Phone::whatsappLink($this->recipientPhone($budget->serviceOrder), $this->message($budget, $link));
    }

    /**
     * PDF do orçamento enviado, guardado com o SHA-256.
     *
     * @return array{path: string, sha256: string, binary: string, at: CarbonImmutable}
     */
    public function preparePdf(Budget $budget, User $by): array
    {
        $this->assertAtendimento($by);
        $at = CarbonImmutable::now();
        $binary = app(BudgetPdf::class)->render($budget);
        $path = $budget->serviceOrder->storageDir().'/orcamento-'.$budget->number.'-'.$at->format('Ymd-His').'.pdf';
        Storage::disk(Media::DISK)->put($path, $binary);

        return ['path' => $path, 'sha256' => hash('sha256', $binary), 'binary' => $binary, 'at' => $at];
    }

    /** Registra o envio. A validade conta do primeiro envio (recebimento pelo cliente). */
    public function markSent(Budget $budget, string $via, User $by, ?array $prepared = null): Budget
    {
        $this->assertAtendimento($by);

        if (! $budget->isPending()) {
            throw new DomainException('Este orçamento já foi respondido.');
        }

        $first = $budget->sent_at === null;
        $at = $prepared['at'] ?? now();

        $budget->update(array_filter([
            'sent_at' => $first ? $at : $budget->sent_at,
            'valid_until' => $first ? CarbonImmutable::parse($at)->addDays((int) (Settings::int('budget_validity_days') ?? 10))->toDateString() : $budget->valid_until,
            'sent_by' => $by->id,
            'sent_via' => in_array($via, ['compartilhar', 'whatsapp', 'manual', 'link'], true) ? $via : 'manual',
            'pdf_path' => $prepared['path'] ?? $budget->pdf_path,
            'pdf_sha256' => $prepared['sha256'] ?? $budget->pdf_sha256,
        ], fn ($value) => $value !== null));

        AuditLog::record($budget->serviceOrder, 'budget.sent', null, ['orcamento' => $budget->title(), 'como' => $via]);

        return $budget->refresh();
    }

    /**
     * Aceite (inteiro ou de parte dos itens).
     *
     * @param  array{method: string, items: list<int>, non_original?: bool, signer_name?: ?string, signer_document?: ?string, message_text?: ?string, message_at?: ?string, signature?: ?string}  $data
     */
    public function approve(Budget $budget, array $data, User $by, ?string $ip = null, ?string $userAgent = null): Budget
    {
        $this->assertAtendimento($by);

        return $this->decide($budget, true, $data, $by, $ip, $userAgent);
    }

    /**
     * Recusa: o motivo e o valor do diagnóstico (se cobrado).
     *
     * @param  array{method: string, reason: ?string, diagnosis_fee_cents?: ?int, message_text?: ?string, signer_name?: ?string, signer_document?: ?string}  $data
     */
    public function refuse(Budget $budget, array $data, User $by, ?string $ip = null, ?string $userAgent = null): Budget
    {
        $this->assertAtendimento($by);

        return $this->decide($budget, false, $data, $by, $ip, $userAgent);
    }

    /** Link para o cliente responder sozinho (não serve para a Izi, que aprova pela alçada). */
    public function createLink(Budget $budget, User $by): PublicLink
    {
        $this->assertAtendimento($by);

        if (! $budget->isPending()) {
            throw new DomainException('Este orçamento já foi respondido.');
        }

        if ($budget->serviceOrder->isIziApproval()) {
            throw new DomainException('Carro da frota Izi: a aprovação é pela alçada, não pelo link.');
        }

        return DB::transaction(function () use ($budget, $by) {
            $budget->links()->whereNull('used_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);

            if ($budget->sent_at === null) {
                $budget = $this->markSent($budget, 'link', $by);
            }

            $token = Str::random(43);
            $expires = ($budget->valid_until ?? today()->addDays(10))->copy()->endOfDay();

            return PublicLink::create([
                'budget_id' => $budget->id,
                'token_hash' => hash('sha256', $token),
                'token' => $token,
                'expires_at' => $expires->isFuture() ? $expires : now()->addDay(),
                'created_by' => $by->id,
            ]);
        });
    }

    /** O link ativo deste orçamento (para copiar de novo). */
    public function activeLink(Budget $budget): ?PublicLink
    {
        return $budget->links()->whereNull('used_at')->whereNull('revoked_at')->where('expires_at', '>', now())->latest('id')->first();
    }

    public function findLink(string $token): ?PublicLink
    {
        return PublicLink::query()->with('budget.serviceOrder')->where('token_hash', hash('sha256', $token))->first();
    }

    /** Visita ao link (inclusive a prévia do WhatsApp): só conta, não muda nada. */
    public function viewed(PublicLink $link): void
    {
        $link->forceFill(['views' => $link->views + 1, 'last_viewed_at' => now()])->save();
    }

    /**
     * Resposta pelo link: só pelo botão (POST), uma vez, dentro da validade. Grava IP, aparelho e hora.
     *
     * @param  array{items?: list<int>, non_original?: bool, signer_name?: ?string, signer_document?: ?string, reason?: ?string}  $data
     */
    public function answerByLink(PublicLink $link, bool $approve, array $data, ?string $ip, ?string $userAgent): Budget
    {
        return DB::transaction(function () use ($link, $approve, $data, $ip, $userAgent) {
            $fresh = PublicLink::query()->lockForUpdate()->findOrFail($link->id);

            if (! $fresh->isUsable()) {
                throw new DomainException($fresh->used_at ? 'Este orçamento já foi respondido. Obrigado!' : 'Este link não vale mais. Fale com a oficina.');
            }

            $budget = $this->decide($fresh->budget, $approve, ['method' => 'link'] + $data, null, $ip, $userAgent);
            $fresh->update(['used_at' => now()]);

            return $budget;
        });
    }

    /** O gestor voltou a OS para antes do orçamento: o que estava com o cliente é cancelado e os itens voltam a mudar. */
    public function reopen(ServiceOrder $order): void
    {
        $budgets = $order->budgets()->where('status', '!=', Budget::STATUS_CANCELLED)->get();

        foreach ($budgets as $budget) {
            $budget->update(['status' => Budget::STATUS_CANCELLED]);
            $budget->links()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }

        OrderItem::query()->where('service_order_id', $order->id)->update(['budget_id' => null, 'approval' => null]);

        if ($budgets->isNotEmpty()) {
            AuditLog::record($order, 'budget.reopened', null, ['cancelados' => $budgets->pluck('number')->all()]);
        }
    }

    /** O gestor voltou a OS para "Aguardando aprovação" (o cliente mudou de ideia): o orçamento espera resposta de novo. */
    public function awaitAgain(ServiceOrder $order): void
    {
        $main = $this->main($order);

        if (! $main) {
            return;
        }

        $order->budgets()->where('number', '>', $main->number)->where('status', '!=', Budget::STATUS_CANCELLED)->update(['status' => Budget::STATUS_CANCELLED]);
        OrderItem::query()->where('service_order_id', $order->id)->where('budget_id', '!=', $main->id)->whereNotNull('budget_id')->update(['budget_id' => null, 'approval' => null]);
        OrderItem::query()->where('budget_id', $main->id)->update(['approval' => null]);
        $main->update(['status' => Budget::STATUS_SENT, 'decided_at' => null, 'decided_by' => null, 'decision_method' => null, 'consent_id' => null, 'approved_total_cents' => null, 'refusal_reason' => null]);
    }

    /** Pede aprovação do gestor: carro da Izi acima da alçada. */
    public function aboveAuthority(Budget $budget, ?int $total = null): bool
    {
        $limit = (int) (Settings::int('izi_approval_limit_cents') ?? 150000);

        return $budget->serviceOrder->isIziApproval() && ($total ?? $budget->total_cents) > $limit;
    }

    private function decide(Budget $budget, bool $approve, array $data, ?User $by, ?string $ip, ?string $userAgent): Budget
    {
        $budget->loadMissing('serviceOrder.customer', 'items');
        $order = $budget->serviceOrder;
        $method = (string) ($data['method'] ?? '');

        if (! $budget->isPending()) {
            throw new DomainException('Este orçamento já foi respondido.');
        }

        if (! array_key_exists($method, Budget::METHODS)) {
            throw new DomainException('Diga como o cliente respondeu.');
        }

        $expected = $budget->isAddendum() ? self::ADDENDUM_STAGES : [Stage::AguardandoAprovacao];

        if (! in_array($order->stage, $expected, true)) {
            throw new DomainException('A OS está em "'.$order->stage->label().'": este orçamento não espera mais resposta.');
        }

        if ($method === 'alcada' && ! $order->isIziApproval()) {
            throw new DomainException('Alçada é só para carro da frota Izi Car.');
        }

        $approvedIds = $approve ? array_values(array_intersect($budget->items->pluck('id')->all(), array_map('intval', (array) ($data['items'] ?? [])))) : [];
        $approvedItems = $budget->items->whereIn('id', $approvedIds);

        if ($approve && $approvedItems->isEmpty()) {
            throw new DomainException('Nenhum item aprovado: se o cliente não aprovou nada, registre a recusa.');
        }

        $approvedTotal = (int) $approvedItems->sum(fn (OrderItem $i) => (int) $i->lineTotalCents());

        if ($approve && $this->aboveAuthority($budget, $approvedTotal) && ! $by?->isGestor()) {
            throw new DomainException('Carro da Izi acima da alçada ('.Money::brl((int) (Settings::int('izi_approval_limit_cents') ?? 150000)).'): quem aprova é o gestor.');
        }

        $needsAuthorization = $approvedItems->contains(fn (OrderItem $i) => $i->isPart() && ($i->origin?->needsAuthorization() ?? false));

        if ($needsAuthorization && empty($data['non_original'])) {
            throw new DomainException('Há peça paralela ou usada: marque que o cliente autorizou a peça não original.');
        }

        $reason = trim((string) ($data['reason'] ?? ''));

        if (! $approve && mb_strlen($reason) < 3) {
            throw new DomainException('Escreva o motivo da recusa.');
        }

        $signerName = trim(preg_replace('/\s+/', ' ', (string) ($data['signer_name'] ?? '')) ?? '');
        $digits = Document::digits($data['signer_document'] ?? null);
        $message = trim((string) ($data['message_text'] ?? ''));
        $png = null;

        if ($method !== 'alcada') {
            if (mb_strlen($signerName) < 3) {
                throw new DomainException('Escreva o nome de quem '.($approve ? 'aprovou' : 'recusou').'.');
            }

            if ($approve && ($digits === '' || ! Document::isValid($digits))) {
                throw new DomainException('CPF ou CNPJ de quem aprovou (vale como identificação do aceite).');
            }

            if (! $approve && $digits !== '' && ! Document::isValid($digits)) {
                throw new DomainException('CPF ou CNPJ inválido (ou deixe em branco).');
            }
        }

        if ($method === 'mensagem' && $message === '') {
            throw new DomainException('Cole a mensagem do cliente.');
        }

        if ($method === 'assinatura') {
            $png = SignatureImage::decode((string) ($data['signature'] ?? ''));
        }

        // Print da mensagem do cliente (opcional): foto já guardada na OS, com o hash conferido.
        $evidence = filled($data['evidence_media_id'] ?? null)
            ? Media::query()->where('service_order_id', $order->id)->find((int) $data['evidence_media_id'])
            : null;

        return DB::transaction(function () use ($budget, $order, $approve, $method, $approvedIds, $approvedTotal, $needsAuthorization, $reason, $signerName, $digits, $message, $png, $evidence, $data, $by, $ip, $userAgent) {
            $decision = [
                'aprovado' => $approve,
                'itens_aprovados' => $approvedIds,
                'motivo' => $approve ? null : $reason,
                'print' => $evidence ? ['midia' => $evidence->id, 'sha256' => $evidence->original_sha256] : null,
            ];
            $snapshot = ['orcamento' => $budget->snapshot, 'orcamento_sha256' => $budget->snapshot_sha256, 'resposta' => $decision];
            $legal = $approve ? (string) ($budget->snapshot['texto_aceite'] ?? '') : '';
            $signaturePath = null;

            if ($png !== null) {
                $signaturePath = $order->storageDir().'/assinatura-orcamento-'.$budget->number.'-'.now()->format('Ymd-His').'.png';
                Storage::disk(Media::DISK)->put($signaturePath, $png);
            }

            $consent = Consent::create([
                'service_order_id' => $order->id,
                'purpose' => $approve ? Consent::PURPOSE_APPROVAL : Consent::PURPOSE_REFUSAL,
                'method' => match ($method) {
                    'assinatura' => Consent::METHOD_SCREEN,
                    'link' => Consent::METHOD_LINK,
                    'mensagem' => Consent::METHOD_MESSAGE,
                    'presencial' => Consent::METHOD_IN_PERSON,
                    default => Consent::METHOD_AUTHORITY,
                },
                'signer_name' => $method === 'alcada' ? ($by?->name ?? 'Alçada') : mb_substr($signerName, 0, 120),
                'signer_document' => $digits ?: null,
                'signer_relation' => $method === 'alcada' ? 'alçada Izi Car' : ($order->approver_kind === 'outro' ? 'autorizado pelo cliente' : null),
                'message_text' => $message !== '' ? mb_substr($message, 0, 2000) : null,
                'message_at' => filled($data['message_at'] ?? null) ? CarbonImmutable::parse($data['message_at']) : null,
                'ip' => $ip ? mb_substr($ip, 0, 45) : null,
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
                'signed_at' => now(),
                'signature_path' => $signaturePath,
                'signature_sha256' => $png !== null ? hash('sha256', $png) : null,
                'snapshot' => $snapshot,
                'snapshot_sha256' => self::hash($snapshot),
                'legal_text' => $legal,
                'legal_text_sha256' => hash('sha256', $legal),
                'recorded_by' => $by?->id,
            ]);

            // O documento de quem aprovou fica no cadastro, se for o próprio cliente e ainda não houver.
            if ($approve && $digits !== '' && blank($order->customer->document) && ! $order->customer->is_izi_fleet && $order->approver_kind !== 'outro') {
                $order->customer->update(['document' => $digits]);
            }

            $budget->update([
                'status' => $approve ? Budget::STATUS_APPROVED : Budget::STATUS_REFUSED,
                'approved_total_cents' => $approve ? $approvedTotal : 0,
                'decided_at' => now(),
                'decided_by' => $by?->id,
                'decision_method' => $method,
                'consent_id' => $consent->id,
                'non_original_authorized' => $needsAuthorization ? true : null,
                'refusal_reason' => $approve ? null : $reason,
                'diagnosis_fee_cents' => $approve ? null : (isset($data['diagnosis_fee_cents']) ? max(0, (int) $data['diagnosis_fee_cents']) : null),
            ]);

            OrderItem::query()->where('budget_id', $budget->id)->update(['approval' => OrderItem::REFUSED]);

            if ($approvedIds !== []) {
                OrderItem::query()->whereIn('id', $approvedIds)->update(['approval' => OrderItem::APPROVED]);
            }

            $budget->links()->whereNull('used_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            AuditLog::record($order, $approve ? 'budget.approved' : 'budget.refused', null, ['orcamento' => $budget->title(), 'como' => $method, 'aprovado' => $approvedTotal, 'consentimento' => $consent->id]);

            // O orçamento principal muda a etapa; o adendo não.
            if (! $budget->isAddendum()) {
                $machine = app(StageMachine::class);
                $to = $approve ? Stage::Aprovada : Stage::Recusada;
                $by
                    ? $machine->move($order, Stage::AguardandoAprovacao, $to, $by, $approve ? null : $reason)
                    : $machine->moveForCustomer($order, Stage::AguardandoAprovacao, $to, $approve ? null : $reason);
            }

            return $budget->refresh();
        });
    }

    private function assertAtendimento(User $by): void
    {
        if (! Gate::forUser($by)->allows('atendimento')) {
            throw new DomainException('O orçamento é com o atendimento.');
        }
    }
}
