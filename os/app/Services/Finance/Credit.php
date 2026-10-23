<?php

namespace App\Services\Finance;

use App\Enums\CreditKind;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Receivable;
use App\Models\Unit;
use App\Models\User;
use App\Support\LicensePlate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Crédito a prazo (Izi Car, frotas, revendas): as regras de `clientes` do financeiro em Node sobre o "A receber".
 *  - O que o cliente deve: lançamentos em aberto que contam (sem a matriz), "saldo anterior" incluído.
 *  - Situação: em dia, atenção (atraso ou perto do limite) ou TRAVADO (passou do limite ou do atraso máximo dos
 *    Ajustes). Travado só compra à vista.
 *  - Limite zero = sem crédito: a prazo só com a liberação do gestor (com motivo, na trilha de auditoria).
 *  - A prazo = parcelado ou com vencimento depois de hoje (e não pago na hora). A trava vale no
 *    Receivables::create/update e na entrega da OS (Delivery::deliver).
 *  - Recebimento do cliente quita primeiro o saldo anterior e depois o vencimento mais antigo.
 */
final class Credit
{
    /** Faixas de atraso (chave => rótulo). */
    public const BANDS = [
        'a_vencer' => 'A vencer / no prazo',
        'ate_7' => '1 a 7 dias',
        'de_8_a_15' => '8 a 15 dias',
        'de_16_a_30' => '16 a 30 dias',
        'mais_30' => 'Mais de 30 dias',
    ];

    public const OK = 'ok';

    public const ATTENTION = 'atencao';

    public const LOCKED = 'travado';

    public function __construct(private Receivables $receivables, private MoneyRules $rules) {}

    /** Parcelado ou com vencimento depois de hoje, e não pago na hora. @param  list<string>  $dueDates */
    public static function isOnCredit(array $dueDates, bool $paidNow = false, ?CarbonImmutable $today = null): bool
    {
        $dueDates = array_values(array_filter($dueDates, fn ($d) => filled($d)));
        $today = ($today ?? CarbonImmutable::today())->toDateString();

        if ($paidNow && count($dueDates) === 1) {
            return false;
        }

        return count($dueDates) > 1 || collect($dueDates)->contains(fn ($d) => substr((string) $d, 0, 10) > $today);
    }

    /**
     * Em aberto do cliente, na ordem em que um recebimento quita: saldo anterior, depois o vencimento mais antigo.
     *
     * @return Collection<int, Receivable>
     */
    public function openItems(Customer $customer): Collection
    {
        return Receivable::query()->open()->counted()->where('customer_id', $customer->id)
            ->orderByDesc('opening_balance')->orderBy('due_date')->orderBy('id')->get();
    }

    /**
     * Situação de crédito do cliente (o `situacaoCliente` do Node).
     *
     * @param  Collection<int, Receivable>|null  $items  em aberto já carregados (a carteira lê todos de uma vez)
     * @return array{customer: Customer, exposure: int, overdue: int, bands: array<string, int>, days_late: int, usage: ?float, status: string, reason: string, open_count: int, released: bool, items: Collection<int, Receivable>}
     */
    public function situation(Customer $customer, ?CarbonImmutable $today = null, ?Collection $items = null): array
    {
        $today ??= CarbonImmutable::today();
        $items ??= $this->openItems($customer);
        $rules = $this->rules->values();
        $bands = array_fill_keys(array_keys(self::BANDS), 0);
        $overdue = 0;
        $late = 0;

        foreach ($items as $item) {
            $days = $item->daysLate($today);
            $balance = $item->balanceCents();
            $band = match (true) {
                $days <= 0 => 'a_vencer',
                $days <= 7 => 'ate_7',
                $days <= 15 => 'de_8_a_15',
                $days <= 30 => 'de_16_a_30',
                default => 'mais_30',
            };
            $bands[$band] += $balance;
            $overdue += $days > 0 ? $balance : 0;
            $late = max($late, $days);
        }

        $exposure = (int) $items->sum(fn (Receivable $r) => $r->balanceCents());
        $limit = $customer->credit_limit_cents;
        $usage = $limit > 0 ? $exposure / $limit : null;
        [$status, $reason] = match (true) {
            $limit > 0 && $exposure > $limit => [self::LOCKED, 'passou do limite de crédito'],
            $late > $rules['credit_lock_days'] => [self::LOCKED, 'atraso de '.$late.' dias (máx. '.$rules['credit_lock_days'].')'],
            $late > 0 => [self::ATTENTION, $late.' dia(s) de atraso'],
            $usage !== null && $usage * 100 >= $rules['credit_warning_pct'] => [self::ATTENTION, 'perto do limite'],
            default => [self::OK, ''],
        };

        if ($status === self::OK && $limit === 0 && $exposure > 0) {
            [$status, $reason] = [self::ATTENTION, 'cliente sem crédito com valor em aberto'];
        }

        return [
            'customer' => $customer,
            'exposure' => $exposure,
            'overdue' => $overdue,
            'bands' => $bands,
            'days_late' => $late,
            'usage' => $usage,
            'status' => $status,
            'reason' => $reason,
            'open_count' => $items->count(),
            'released' => $customer->releaseActive($today),
            'items' => $items,
        ];
    }

    /**
     * Carteira (o `carteira` do Node): clientes com prazo, limite ou do tipo frota/locadora/revenda, mais exposto
     * primeiro. Lê os lançamentos de todos numa consulta só.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function portfolio(?CarbonImmutable $today = null): Collection
    {
        $customers = Customer::query()->onCredit()->orderBy('name')->get();
        $items = Receivable::query()->open()->counted()->whereIn('customer_id', $customers->modelKeys())
            ->orderByDesc('opening_balance')->orderBy('due_date')->orderBy('id')->get()->groupBy('customer_id');

        return $customers->map(fn (Customer $c) => $this->situation($c, $today, $items->get($c->id, collect())))
            ->sortByDesc('exposure')->values();
    }

    /**
     * Pode lançar a prazo? Sem problema devolve nulo. Com problema (sem crédito, travado ou passaria do limite) vale a
     * liberação do gestor: o motivo digitado agora (só o gestor) ou a liberação com data no cadastro do cliente; ela
     * volta como texto para a trilha de auditoria. Sem liberação: DomainException explicando o motivo.
     */
    public function authorize(?Customer $customer, int $newCents, bool $onCredit, ?User $by = null, ?string $releaseReason = null, ?CarbonImmutable $today = null): ?string
    {
        if (! $onCredit || $customer === null) {
            return null;
        }

        $today ??= CarbonImmutable::today();
        $problem = $this->problem($customer, $newCents, $today);

        if ($problem === null) {
            return null;
        }

        $releaseReason = trim((string) $releaseReason);

        if ($releaseReason !== '') {
            if ($by === null || ! Gate::forUser($by)->allows('gestor')) {
                throw new DomainException('Só o gestor libera venda a prazo para cliente travado ou sem crédito.');
            }

            if (mb_strlen($releaseReason) < 5) {
                throw new DomainException('Escreva o motivo da liberação (pelo menos 5 letras).');
            }

            return 'Liberado na hora por '.$by->name.': '.Str::limit($releaseReason, 180, '').' ('.$problem.')';
        }

        if ($customer->releaseActive($today)) {
            return 'Liberação do gestor até '.$customer->credit_release_until->format('d/m/Y').': '.$customer->credit_release_reason.' ('.$problem.')';
        }

        throw new DomainException($problem.' Para lançar a prazo, receba à vista (vencimento hoje) ou peça ao gestor para liberar com o motivo.');
    }

    /**
     * Para a tela avisar antes de tentar: o motivo da trava (nulo quando pode lançar a prazo, inclusive pela
     * liberação com data do gestor). A regra que vale é a do `authorize`.
     */
    public function warning(?Customer $customer, int $newCents, bool $onCredit, ?CarbonImmutable $today = null): ?string
    {
        if (! $onCredit || $customer === null) {
            return null;
        }

        $today ??= CarbonImmutable::today();

        return $customer->releaseActive($today) ? null : $this->problem($customer, $newCents, $today);
    }

    /** Liberação com data (só o gestor): até o dia informado, o cliente compra a prazo mesmo travado ou sem crédito. */
    public function release(Customer $customer, string $until, string $reason, User $by): Customer
    {
        $this->assertGestor($by, 'Só o gestor libera o crédito do cliente.');
        $until = FinanceInput::date($until, 'Informe até quando vale a liberação.');
        $today = CarbonImmutable::today();
        $reason = trim(preg_replace('/\s+/', ' ', $reason) ?? '');

        if ($until < $today->toDateString() || $until > $today->addDays(90)->toDateString()) {
            throw new DomainException('A liberação vale de hoje até no máximo 90 dias.');
        }

        if (mb_strlen($reason) < 5) {
            throw new DomainException('Escreva o motivo da liberação (pelo menos 5 letras).');
        }

        $customer->update(['credit_release_until' => $until, 'credit_release_reason' => Str::limit($reason, 200, ''), 'credit_released_by' => $by->id]);
        AuditLog::record($customer, 'customer.credit_released', $reason, ['ate' => $until]);

        return $customer->fresh();
    }

    public function revokeRelease(Customer $customer, User $by): Customer
    {
        $this->assertGestor($by, 'Só o gestor mexe na liberação do crédito.');
        $customer->update(['credit_release_until' => null, 'credit_release_reason' => null, 'credit_released_by' => null]);
        AuditLog::record($customer, 'customer.credit_release_revoked');

        return $customer->fresh();
    }

    /**
     * Tipo, prazo e limite (a política de crédito: só o gestor, como o "só o dono" do Node). Fica na trilha o antes e
     * o depois.
     *
     * @param  array{kind?: mixed, days?: mixed, limit?: mixed}  $data  limite como texto digitado ou centavos
     */
    public function updateTerms(Customer $customer, array $data, User $by): Customer
    {
        $kind = CreditKind::tryFrom((string) ($data['kind'] ?? '')) ?? throw new DomainException('Escolha o tipo do cliente.');
        $days = filter_var(trim((string) ($data['days'] ?? '0')) ?: '0', FILTER_VALIDATE_INT);

        if ($days === false || $days < 0 || $days > 180) {
            throw new DomainException('O prazo de pagamento vai de 0 a 180 dias.');
        }

        $limit = FinanceInput::cents($data['limit'] ?? null, 'limite de crédito');
        $before = ['tipo' => $customer->credit_kind->value, 'prazo' => $customer->credit_days, 'limite' => $customer->credit_limit_cents];
        $after = ['tipo' => $kind->value, 'prazo' => $days, 'limite' => $limit];

        if ($before === $after) {
            return $customer;
        }

        $this->assertGestor($by, 'Tipo, prazo e limite de crédito do cliente são do gestor.');
        $customer->update(['credit_kind' => $kind, 'credit_days' => $days, 'credit_limit_cents' => $limit]);
        AuditLog::record($customer, 'customer.credit_updated', null, ['antes' => $before, 'depois' => $after]);

        return $customer->fresh();
    }

    /**
     * "Saldo anterior": o que o cliente já devia antes do sistema (só o gestor). Entra no A receber dele (é quitado
     * primeiro), mas não conta como faturamento. Um por cliente: lançar de novo corrige o valor e a data.
     */
    public function setOpeningBalance(Customer $customer, mixed $amount, string $date, User $by): Receivable
    {
        $this->assertGestor($by, 'O saldo anterior é lançado pelo gestor.');
        $cents = FinanceInput::cents($amount, 'saldo anterior');
        $date = FinanceInput::date($date, 'Informe desde quando o cliente deve.');

        if ($cents <= 0) {
            throw new DomainException('Informe o valor do saldo anterior.');
        }

        return DB::transaction(function () use ($customer, $cents, $date, $by) {
            $existing = Receivable::query()->where('customer_id', $customer->id)->where('opening_balance', true)
                ->whereNull('written_off_at')->lockForUpdate()->orderBy('id')->first();

            if ($existing) {
                if ($cents < $existing->paid_cents) {
                    throw new DomainException('O saldo anterior não pode ficar abaixo do que já foi recebido dele ('.Money::brl($existing->paid_cents).').');
                }

                $existing->update(['total_cents' => $cents, 'due_date' => $date, 'service_date' => $date, 'received_on' => $existing->paid_cents >= $cents ? $existing->received_on : null]);
                AuditLog::record($existing, 'receivable.updated', 'Saldo anterior corrigido', ['total_cents' => $cents, 'due_date' => $date]);

                return $existing->fresh();
            }

            $receivable = Receivable::create([
                'customer_id' => $customer->id,
                'order_number' => 'SALDO',
                'service_date' => $date,
                'due_date' => $date,
                'total_cents' => $cents,
                'opening_balance' => true,
                'notes' => 'Saldo anterior ao sistema.',
                'created_by' => $by->id,
            ]);
            AuditLog::record($receivable, 'receivable.created', 'Saldo anterior');

            return $receivable;
        });
    }

    /**
     * Recebimento do cliente (o `receberDoCliente` do Node): quita o saldo anterior e depois o vencimento mais
     * antigo, um pagamento em cada lançamento (Receivables::addPayment). Não aceita valor acima do que ele deve.
     *
     * @return list<array{receivable: Receivable, cents: int}>
     */
    public function receive(Customer $customer, mixed $amount, string $paidOn, User $by): array
    {
        $cents = FinanceInput::cents($amount, 'valor recebido');
        $paidOn = FinanceInput::date($paidOn, 'Informe a data do pagamento.');

        if ($cents <= 0) {
            throw new DomainException('Informe o valor recebido.');
        }

        return DB::transaction(function () use ($customer, $cents, $paidOn, $by) {
            $items = $this->openItems($customer);
            $exposure = (int) $items->sum(fn (Receivable $r) => $r->balanceCents());

            if ($cents > $exposure) {
                throw new DomainException('O valor passa do que '.$customer->name.' deve ('.Money::brl($exposure).'). Confira o valor.');
            }

            $left = $cents;
            $applied = [];

            foreach ($items as $item) {
                if ($left <= 0) {
                    break;
                }

                $part = min($left, $item->balanceCents());
                $this->receivables->addPayment($item, $paidOn, $part, $by);
                $applied[] = ['receivable' => $item->fresh(), 'cents' => $part];
                $left -= $part;
            }

            return $applied;
        });
    }

    /**
     * Extrato (o `extratoCliente` do Node): o que foi cobrado, os pagamentos e as baixas como prejuízo, com o saldo
     * corrido. Na mesma data, a cobrança vem antes do pagamento.
     *
     * @return list<array{date: string, kind: string, description: string, debit: int, credit: int, balance: int}>
     */
    public function statement(Customer $customer): array
    {
        $rows = [];
        $receivables = Receivable::query()->counted()->where('customer_id', $customer->id)->with(['payments', 'loss'])->orderBy('id')->get();

        foreach ($receivables as $r) {
            $rows[] = [
                'date' => $r->serviceDay()->toDateString(),
                'kind' => $r->opening_balance ? 'saldo' : 'os',
                'description' => $r->opening_balance ? 'Saldo anterior ao sistema' : trim($r->reference().' '.($r->plate ? LicensePlate::clean($r->plate) : '').' '.$r->vehicle_model).' · venc. '.$r->due_date->format('d/m/Y'),
                'debit' => $r->total_cents,
                'credit' => 0,
            ];

            foreach ($r->payments as $payment) {
                $rows[] = ['date' => $payment->paid_on->toDateString(), 'kind' => 'pagamento', 'description' => 'Pagamento ('.$r->reference().')', 'debit' => 0, 'credit' => $payment->amount_cents];
            }

            if ($r->isWrittenOff() && $r->loss) {
                $rows[] = ['date' => $r->written_off_at->toDateString(), 'kind' => 'baixa', 'description' => 'Baixa como prejuízo ('.$r->reference().')', 'debit' => 0, 'credit' => $r->loss->amount_cents];
            }
        }

        usort($rows, fn ($a, $b) => [$a['date'], $a['debit'] > 0 ? 0 : 1] <=> [$b['date'], $b['debit'] > 0 ? 0 : 1]);
        $balance = 0;

        return array_map(function (array $row) use (&$balance) {
            $balance += $row['debit'] - $row['credit'];

            return $row + ['balance' => $balance];
        }, $rows);
    }

    /**
     * Texto para colar no WhatsApp: o fechamento do que o cliente deve (o `textoCobranca` do Node). Só os números:
     * sem juros, multa nem linguagem de cobrança (o Node não tinha texto jurídico).
     */
    public function collectionText(Customer $customer, ?CarbonImmutable $today = null): string
    {
        $today ??= CarbonImmutable::today();
        $situation = $this->situation($customer, $today);
        $workshop = Unit::default()?->trade_name ?: (string) config('app.name');
        $lines = ['*'.$workshop.' x '.$customer->name.'* - fechamento em '.$today->format('d/m/Y'), ''];

        foreach ($situation['items'] as $item) {
            $days = $item->daysLate($today);
            $label = $item->opening_balance ? 'Saldo anterior' : $item->reference();
            $plate = $item->plate ? ' '.LicensePlate::clean($item->plate) : '';
            $lines[] = $label.' ('.$item->serviceDay()->format('d/m/Y').')'.$plate.': '.Money::brl($item->balanceCents())
                .($days > 0 ? ' - vencida há '.$days.' dia(s)' : ' - vence '.$item->due_date->format('d/m/Y'));
        }

        $lines[] = '';
        $lines[] = '*Total em aberto: '.Money::brl($situation['exposure']).'*';

        if ($situation['overdue'] > 0) {
            $lines[] = 'Vencido: '.Money::brl($situation['overdue']);
        }

        if ($customer->credit_limit_cents > 0) {
            $lines[] = 'Limite combinado: '.Money::brl($customer->credit_limit_cents);
        }

        return implode("\n", $lines);
    }

    /** Por que não pode vender a prazo (nulo = pode). */
    private function problem(Customer $customer, int $newCents, CarbonImmutable $today): ?string
    {
        if (! $customer->hasCredit()) {
            return $customer->name.' não tem crédito a prazo (limite em branco no cadastro).';
        }

        $situation = $this->situation($customer, $today);

        if ($situation['status'] === self::LOCKED) {
            return $customer->name.' está TRAVADO: '.$situation['reason'].'.';
        }

        if ($customer->credit_limit_cents < $situation['exposure'] + $newCents) {
            return $customer->name.' passaria do limite de crédito: deve '.Money::brl($situation['exposure']).', esta venda '.Money::brl($newCents).', limite '.Money::brl($customer->credit_limit_cents).'.';
        }

        return null;
    }

    private function assertGestor(User $by, string $message): void
    {
        if (! Gate::forUser($by)->allows('gestor')) {
            throw new DomainException($message);
        }
    }
}
