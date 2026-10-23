<?php

namespace App\Services\Purchases;

use App\Models\AuditLog;
use App\Models\BankSlip;
use App\Models\Payable;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceInstallment;
use App\Models\PurchaseLink;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Finance\FinanceInput;
use App\Support\Document;
use App\Support\Money;
use App\Support\Purchases\BoletoCode;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Boletos de fornecedor na conferência de compras (doc 09 §2, §4 e "Pagamento: o que o sistema exige"): cadastro pela
 * linha digitável (o boleto da oficina vira, ou adota, a conta do "A pagar" do mesmo fornecedor, valor e vencimento),
 * ligação com as notas (automática só com uma explicação forte), correção de quem recebe e quem paga, cancelamento,
 * contestação e o pagamento com trava. O atendimento lança; pagar, liberar, contestar e reabrir é do gestor.
 *
 * Porte de `app/src/boletos.js`. Dinheiro em centavos.
 */
final class BankSlips
{
    public function __construct(private PurchaseSuppliers $suppliers, private GroupCompanies $companies, private Invoices $invoices) {}

    /**
     * Cadastra o boleto (pela linha digitável ou, sem ela, pelo valor e vencimento digitados) e tenta ligar à nota.
     *
     * @param  array<string, mixed>  $data  line, amount, due_date, document_number, supplier_id, supplier_name, payee_cnpj,
     *                                      payee_name, payer_cnpj, notes
     * @return array{slip: BankSlip, reading: ?array, auto: array, adopted: bool, occurrences: list<array>}
     */
    public function create(array $data, User $by, bool $withOccurrences = true): array
    {
        $today = PurchaseSettings::today();
        $reading = null;
        $line = trim((string) ($data['line'] ?? ''));

        if ($line !== '') {
            $reading = BoletoCode::read($line, $today);

            if (! $reading['ok']) {
                throw new DomainException(implode(' ', $reading['errors']));
            }
        }

        $typed = filled($data['amount'] ?? null) ? $this->amount($data['amount']) : null;
        $amount = $reading && $reading['amount_cents'] > 0 ? $reading['amount_cents'] : $typed;

        if ($reading && $reading['amount_cents'] > 0 && $typed !== null && $typed !== $reading['amount_cents']) {
            throw new DomainException('O valor digitado ('.Money::brl($typed).') é diferente do valor que está na linha digitável ('.Money::brl($reading['amount_cents']).'). Confira o que está escrito no boleto.');
        }

        if (! $amount || $amount <= 0) {
            throw new DomainException('Informe o valor do boleto.');
        }

        $typedDue = filled($data['due_date'] ?? null) ? FinanceInput::date((string) $data['due_date'], 'Vencimento inválido.') : null;
        $due = $reading['due_date'] ?? $typedDue;

        if ($reading && $reading['due_date'] && $typedDue && $reading['due_date'] !== $typedDue) {
            throw new DomainException('O vencimento digitado ('.CarbonImmutable::parse($typedDue)->format('d/m/Y').') é diferente do vencimento que está na linha digitável ('.CarbonImmutable::parse($reading['due_date'])->format('d/m/Y').'). Confira o boleto.');
        }

        if (! $due) {
            throw new DomainException('Informe o vencimento do boleto.');
        }

        $reactivate = null;

        if ($reading['barcode'] ?? null) {
            $existing = BankSlip::query()->where('barcode', $reading['barcode'])->first();

            if ($existing?->status === BankSlip::CANCELED) {
                // O atendimento não reabre, só por cadastrar de novo, o boleto que o gestor cancelou.
                if ($existing->canceled_role === 'gestor' && ! $by->isGestor()) {
                    throw new DomainException("Este boleto foi cancelado pelo gestor (nº {$existing->id}). Só o gestor reabre.");
                }

                $reactivate = $existing;
            } elseif ($existing?->status === BankSlip::CONTESTED) {
                throw new DomainException("Este boleto está contestado (nº {$existing->id}). Reabra-o em Compras > Boletos se o fornecedor confirmou a cobrança.");
            } elseif ($existing) {
                throw new DomainException("Este boleto já está cadastrado (nº {$existing->id}, ".mb_strtolower($existing->statusLabel()).'). Não pague duas vezes.');
            }
        }

        $payee = $this->cnpj($data['payee_cnpj'] ?? null, 'O CNPJ do beneficiário (quem recebe) não passa na validação (confira os números).');
        $payer = $this->cnpj($data['payer_cnpj'] ?? null, 'O CNPJ do pagador não passa na validação (confira os números).');
        $company = $payer ? $this->companies->companyFor($payer) : null;

        if ($company && ! $by->isGestor()) {
            throw new DomainException('Boleto em nome de outra empresa do grupo ('.$company->name.'): quem define é o gestor. Cadastre sem o pagador e avise o gestor.');
        }

        $supplier = $this->supplierFor($data, $payee);

        return DB::transaction(function () use ($reading, $amount, $due, $data, $payee, $payer, $company, $supplier, $reactivate, $by, $withOccurrences) {
            [$payable, $adopted, $before] = $company ? [null, false, null] : $this->payableFor($supplier, $amount, $due, $data['document_number'] ?? null, $by);
            $fields = [
                'supplier_id' => $supplier->id, 'barcode' => $reading['barcode'] ?? null, 'digitable_line' => $reading['line'] ?? null, 'bank_code' => $reading['bank'] ?? null,
                'amount_cents' => $amount, 'due_date' => $due, 'document_number' => mb_substr(trim((string) ($data['document_number'] ?? '')), 0, 40) ?: null,
                'payee_name' => mb_substr(trim((string) ($data['payee_name'] ?? '')), 0, 80) ?: null, 'payee_cnpj' => $payee, 'payer_cnpj' => $payer,
                'payable_id' => $payable?->id, 'adopted_payable' => $before, 'group_company_id' => $company?->id, 'created_by' => $by->id,
                'created_role' => PurchaseSettings::role($by), 'notes' => mb_substr(trim((string) ($data['notes'] ?? '')), 0, 300) ?: null,
            ];

            if ($reactivate) {
                $reactivate->update([...$fields, 'status' => BankSlip::OPEN, 'canceled_role' => null, 'released_reason' => null, 'released_on' => null,
                    'bank_checked_on' => null, 'paid_on' => null, 'paid_cents' => null, 'paid_by' => null, 'paid_by_party' => null]);
                $slip = $reactivate;
                AuditLog::record($slip, 'purchase.slip_reopened', 'Cadastrado de novo');
            } else {
                $slip = BankSlip::create($fields);
                AuditLog::record($slip, 'purchase.slip_created', null, ['valor' => $amount, 'vencimento' => $due, 'conta_adotada' => $adopted]);
            }

            $auto = $this->autoLink($slip, $by);

            return [
                'slip' => $slip->fresh(), 'reading' => $reading, 'auto' => $auto, 'adopted' => $adopted,
                'occurrences' => $withOccurrences ? app(Occurrences::class)->forSlip($slip) : [],
            ];
        });
    }

    /**
     * Vários boletos de uma vez (uma linha digitável por linha), do mesmo fornecedor. As ocorrências são calculadas uma
     * única vez no fim.
     *
     * @return list<array{line: string, status: string, slip_id: ?int, linked?: bool, serious?: int, amount_cents?: ?int, due_date?: ?string, error?: string}>
     */
    public function createBatch(string $text, ?int $supplierId, ?string $supplierName, User $by): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $text) ?: []), fn ($l) => $l !== ''));

        if ($lines === []) {
            throw new DomainException('Cole ao menos uma linha digitável.');
        }

        if (count($lines) > 60) {
            throw new DomainException('No máximo 60 boletos por vez.');
        }

        if (! $supplierId && trim((string) $supplierName) === '') {
            throw new DomainException('Escolha o fornecedor.');
        }

        $results = [];

        foreach ($lines as $line) {
            try {
                $r = $this->create(['line' => $line, 'supplier_id' => $supplierId, 'supplier_name' => $supplierName], $by, false);
                $results[] = ['line' => $line, 'status' => 'criado', 'slip_id' => $r['slip']->id, 'linked' => $r['auto']['linked'], 'serious' => 0,
                    'amount_cents' => $r['reading']['amount_cents'] ?? null, 'due_date' => $r['reading']['due_date'] ?? null];
            } catch (DomainException $e) {
                $results[] = ['line' => $line, 'status' => 'erro', 'slip_id' => null, 'error' => $e->getMessage()];
            }
        }

        if (collect($results)->contains('status', 'criado')) {
            $all = app(Occurrences::class)->all();

            foreach ($results as &$result) {
                if ($result['status'] === 'criado') {
                    $result['serious'] = count(array_filter($all, fn ($o) => $o['severity'] === 'alta' && ! $o['accepted'] && in_array($result['slip_id'], $o['slips'], true)));
                }
            }
        }

        return $results;
    }

    /**
     * Informar ou corrigir quem recebe, quem paga, o documento impresso e o fornecedor de um boleto em aberto.
     *
     * @param  array<string, mixed>  $fields  payee_cnpj, payer_cnpj, payee_name, document_number, supplier_id (só as chaves enviadas mudam)
     * @return array{changed: bool, auto: ?array}
     */
    public function update(BankSlip $slip, array $fields, User $by): array
    {
        if (! $slip->isOpen()) {
            throw new DomainException('Só dá para corrigir boleto em aberto.');
        }

        $new = [];

        if (array_key_exists('payee_cnpj', $fields)) {
            $new['payee_cnpj'] = $this->cnpj($fields['payee_cnpj'], 'O CNPJ do beneficiário (quem recebe) não passa na validação (confira os números).');
        }

        if (array_key_exists('payer_cnpj', $fields)) {
            $new['payer_cnpj'] = $this->cnpj($fields['payer_cnpj'], 'O CNPJ do pagador não passa na validação (confira os números).');

            // Trocar o pagador para uma empresa do grupo tira o boleto do "A pagar" da oficina: é decisão do gestor.
            if ($new['payer_cnpj'] && ! $by->isGestor() && $this->companies->companyFor($new['payer_cnpj'])) {
                throw new DomainException('Boleto de outra empresa do grupo: quem define é o gestor.');
            }
        }

        if (array_key_exists('payee_name', $fields)) {
            $new['payee_name'] = mb_substr(trim((string) $fields['payee_name']), 0, 80) ?: null;
        }

        if (array_key_exists('document_number', $fields)) {
            $new['document_number'] = mb_substr(trim((string) $fields['document_number']), 0, 40) ?: null;
        }

        if (array_key_exists('supplier_id', $fields) && (int) $fields['supplier_id'] !== (int) $slip->supplier_id) {
            if (! Supplier::query()->whereKey((int) $fields['supplier_id'])->exists()) {
                throw new DomainException('Fornecedor não encontrado.');
            }

            if ($slip->links()->exists()) {
                throw new DomainException('Desfaça a ligação com as notas antes de trocar o fornecedor do boleto.');
            }

            $new['supplier_id'] = (int) $fields['supplier_id'];
        }

        $new = array_filter($new, fn ($value, $key) => $value !== $slip->{$key}, ARRAY_FILTER_USE_BOTH);

        if ($new === []) {
            return ['changed' => false, 'auto' => null];
        }

        DB::transaction(function () use ($slip, $new) {
            $slip->update($new);

            if (isset($new['supplier_id']) && $slip->payable && ! $slip->payable->isPaid()) {
                $slip->payable->update(['supplier_id' => $new['supplier_id']]);
            }

            AuditLog::record($slip, 'purchase.slip_corrected', null, $new);
        });

        $this->applyCompany($slip);

        return ['changed' => true, 'auto' => $slip->links()->exists() ? null : $this->autoLink($slip->fresh(), $by)];
    }

    /**
     * Liga o boleto a notas (ou parcelas). Recusa o que não pode ser verdade: nota de outro fornecedor, cancelada,
     * devolução/ajuste, parcela de outra nota, valor maior que o boleto ou que o saldo da nota.
     *
     * @param  list<array{invoice_id: mixed, amount: mixed, installment_id?: mixed}>  $items
     */
    public function link(BankSlip $slip, array $items, User $by, string $source = 'manual'): void
    {
        if (in_array($slip->status, [BankSlip::CANCELED, BankSlip::CONTESTED], true)) {
            throw new DomainException('Este boleto está '.$slip->status.'. Reabra-o antes de ligar a uma nota.');
        }

        if ($slip->isPaid() && ! $by->isGestor()) {
            throw new DomainException('Boleto já pago: só o gestor liga uma nota a ele (a ligação é a prova do que foi pago).');
        }

        if ($items === []) {
            throw new DomainException('Escolha ao menos uma nota.');
        }

        $tolerance = PurchaseSettings::toleranceCents();
        $amounts = array_map(fn ($i) => is_int($i['amount'] ?? null) ? $i['amount'] : FinanceInput::cents($i['amount'] ?? null, 'valor ligado'), $items);
        $ids = array_map(fn ($i) => (int) ($i['invoice_id'] ?? 0), $items);
        $others = (int) PurchaseLink::query()->where('bank_slip_id', $slip->id)->whereNotIn('purchase_invoice_id', $ids)->sum('amount_cents');
        $new = array_sum($amounts);

        if ($others + $new > $slip->amount_cents + $tolerance) {
            throw new DomainException('As notas ligadas somariam '.Money::brl($others + $new).', mais que o boleto ('.Money::brl($slip->amount_cents).'). Confira os valores.');
        }

        DB::transaction(function () use ($slip, $items, $amounts, $by, $source, $tolerance, $new, $ids) {
            $supplierOfInvoice = null;

            foreach ($items as $k => $item) {
                $invoice = PurchaseInvoice::find((int) ($item['invoice_id'] ?? 0)) ?? throw new DomainException('Nota não encontrada.');

                if (! $invoice->isActive()) {
                    throw new DomainException('A nota '.$invoice->number.' está cancelada: não dá para ligar boleto a ela.');
                }

                if (! $invoice->generatesCharge()) {
                    throw new DomainException('A nota '.$invoice->number.' é de '.($invoice->purpose === 'devolucao' ? 'devolução/crédito' : 'ajuste').': não gera cobrança e não pode ser ligada a boleto.');
                }

                if ($slip->supplier_id && $invoice->supplier_id !== $slip->supplier_id) {
                    throw new DomainException('A nota '.$invoice->number.' é de outro fornecedor. Boleto e nota precisam ser do mesmo fornecedor.');
                }

                if ($amounts[$k] <= 0) {
                    throw new DomainException('O valor ligado a cada nota precisa ser maior que zero.');
                }

                $installmentId = filled($item['installment_id'] ?? null) ? (int) $item['installment_id'] : null;

                if ($installmentId && ! PurchaseInvoiceInstallment::query()->whereKey($installmentId)->where('purchase_invoice_id', $invoice->id)->exists()) {
                    throw new DomainException('A parcela escolhida não pertence à nota '.$invoice->number.'.');
                }

                $already = (int) PurchaseLink::query()->where('purchase_invoice_id', $invoice->id)->where('bank_slip_id', '!=', $slip->id)->sum('amount_cents');
                $limit = max($invoice->total_cents, (int) $invoice->total_with_taxes_cents);

                if ($already + $amounts[$k] > $limit + $tolerance) {
                    throw new DomainException('A nota '.$invoice->number.' só tem '.Money::brl(max(0, $limit - $already)).' sem boleto. Confira o valor ligado.');
                }

                PurchaseLink::query()->updateOrCreate(
                    ['bank_slip_id' => $slip->id, 'purchase_invoice_id' => $invoice->id],
                    ['installment_id' => $installmentId, 'amount_cents' => $amounts[$k], 'source' => $source, 'created_by' => $by->id, 'created_role' => PurchaseSettings::role($by)],
                );
                $supplierOfInvoice ??= $invoice->supplier_id;
            }

            if (! $slip->supplier_id && $supplierOfInvoice) {
                $slip->update(['supplier_id' => $supplierOfInvoice]);
            }

            AuditLog::record($slip, 'purchase.slip_linked', null, ['origem' => $source, 'notas' => $ids, 'valor' => $new]);
        });

        $this->applyCompany($slip);       // sem o pagador impresso, a nota diz de quem é o boleto
    }

    public function unlink(BankSlip $slip, int $invoiceId, User $by): bool
    {
        if ($slip->isPaid()) {
            throw new DomainException('Este boleto já foi pago: a ligação com a nota não pode ser desfeita (ela é a prova do que foi pago).');
        }

        $deleted = PurchaseLink::query()->where('bank_slip_id', $slip->id)->where('purchase_invoice_id', $invoiceId)->delete() > 0;

        if ($deleted) {
            AuditLog::record($slip, 'purchase.slip_unlinked', null, ['nota' => $invoiceId]);
            $this->applyCompany($slip);
        }

        return $deleted;
    }

    /**
     * Liga sozinho quando só existe uma explicação forte (nota com 90 pontos ou mais e nenhuma outra a menos de 10).
     *
     * @return array{linked: bool, suggestion?: array, suggestions?: list<array>, refused?: string}
     */
    public function autoLink(BankSlip $slip, User $by): array
    {
        $suggestions = $this->suggestInvoices($slip);
        $best = $suggestions[0] ?? null;
        $second = $suggestions[1] ?? null;

        if ($best && $best['score'] >= 90 && (! $second || $second['score'] <= $best['score'] - 10)) {
            try {
                $this->link($slip, array_map(fn ($i) => ['invoice_id' => $i['invoice_id'], 'amount' => $i['amount_cents'], 'installment_id' => $i['installment_id']], $best['items']), $by, 'auto');

                return ['linked' => true, 'suggestion' => $best, 'suggestions' => array_slice($suggestions, 1)];
            } catch (DomainException $e) {
                return ['linked' => false, 'suggestions' => $suggestions, 'refused' => $e->getMessage()];      // a nota não comporta: ligação manual
            }
        }

        return ['linked' => false, 'suggestions' => $suggestions];
    }

    /**
     * Notas candidatas para o boleto, da mais para a menos provável (doc 09 §4):
     * 100 parcela com o mesmo valor e vencimento · 95 número do documento e valor batem · 90/70 cobre o saldo de todas as
     * parcelas (com/sem o número) · 85 valor de uma parcela, outro vencimento · 80 nota sem parcelas com o mesmo saldo ·
     * 75 o número bate mas o valor não · 60 soma de 2 a 5 notas do fornecedor (boleto agrupado).
     *
     * @return list<array{score: int, reason: string, items: list<array{invoice_id: int, number: string, amount_cents: int, installment_id: ?int}>, value_mismatch: bool, grouped: bool}>
     */
    public function suggestInvoices(BankSlip $slip): array
    {
        $tolerance = PurchaseSettings::toleranceCents();
        $candidates = $this->candidateInvoices($slip);
        $docs = array_map(fn ($d) => (string) (int) $d, preg_match_all('/\d+/', (string) $slip->document_number, $m) ? $m[0] : []);
        $suggestions = [];
        $add = function (array $items, int $score, string $reason, array $extra = []) use (&$suggestions) {
            $key = collect($items)->pluck('invoice_id')->sort()->implode('+');

            if (! isset($suggestions[$key]) || $suggestions[$key]['score'] < $score) {
                $suggestions[$key] = ['score' => $score, 'reason' => $reason, 'items' => $items, 'value_mismatch' => false, 'grouped' => false, ...$extra];
            }
        };
        $amount = $slip->amount_cents;
        $due = $slip->due_date->toDateString();

        foreach ($candidates as $c) {
            $invoice = $c['invoice'];
            $number = (string) (int) $invoice->number;
            $numberMatches = strlen($number) >= 3 && in_array($number, $docs, true);
            $item = fn (int $cents, ?int $installment = null) => [['invoice_id' => $invoice->id, 'number' => $invoice->number, 'amount_cents' => $cents, 'installment_id' => $installment]];

            foreach ($c['installments'] as $row) {
                $sameValue = abs($row['balance_cents'] - $amount) <= $tolerance;

                if ($sameValue && $row['installment']->due_date->toDateString() === $due) {
                    $add($item($amount, $row['installment']->id), 100, 'parcela da nota com o mesmo valor e vencimento');
                } elseif ($sameValue && $numberMatches) {
                    $add($item($amount, $row['installment']->id), 95, 'número do documento e valor batem; vencimento diferente da parcela');
                } elseif ($sameValue) {
                    $add($item($amount, $row['installment']->id), 85, 'valor igual ao de uma parcela, mas com outro vencimento');
                }
            }

            if (count($c['installments']) > 1 && abs($c['open'] - $amount) <= $tolerance) {
                $add($item($amount), $numberMatches ? 90 : 70, 'o boleto cobre o saldo da nota inteira (todas as parcelas juntas)');
            }

            $withTaxes = $c['open_with_taxes'] !== null && abs($c['open_with_taxes'] - $amount) <= $tolerance;

            if ($c['installments'] === [] && (abs($c['open'] - $amount) <= $tolerance || $withTaxes)) {
                $add($item($amount), $numberMatches ? 95 : 80, $numberMatches ? 'número do documento e valor batem com a nota'
                    : ($withTaxes ? 'valor igual ao total da nota com tributos por fora (sem parcelas informadas)' : 'valor igual ao saldo da nota (sem parcelas informadas)'));
            } elseif ($numberMatches && ! collect($c['installments'])->contains(fn ($row) => abs($row['balance_cents'] - $amount) <= $tolerance)) {
                $add($item(min($amount, $c['open'])), 75, 'o número do documento bate com a nota, mas o VALOR do boleto é diferente do esperado', ['value_mismatch' => true]);
            }
        }

        if ($suggestions === [] || collect($suggestions)->every(fn ($s) => $s['score'] < 90)) {
            foreach ($this->combinations($candidates, $amount, $tolerance) as $combo) {
                $add(array_map(fn ($c) => ['invoice_id' => $c['invoice']->id, 'number' => $c['invoice']->number, 'amount_cents' => $c['open'], 'installment_id' => null], $combo),
                    60, 'soma de '.count($combo).' notas do mesmo fornecedor (boleto agrupado)', ['grouped' => true]);
            }
        }

        $list = array_values($suggestions);
        usort($list, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $list;
    }

    /**
     * Pagamento com trava (gestor):
     * 1) antes do primeiro pagamento alguém confere no app do banco quem recebe (`bank_checked`), a não ser que o DDA do
     *    banco já tenha confirmado;
     * 2) ocorrência grave não aceita exige "pagar mesmo assim" com motivo (`release` + `reason`);
     * 3) valor pago diferente do boleto exige motivo (juros, multa, desconto);
     * 4) boleto de empresa do grupo exige dizer quem paga (a oficina: fica a receber da empresa; a própria empresa).
     *
     * @param  array{paid_on?: ?string, paid_amount?: mixed, release?: bool, reason?: ?string, bank_checked?: bool, paid_by_party?: ?string}  $data
     * @return array{released: bool}
     */
    public function pay(BankSlip $slip, array $data, User $by): array
    {
        if (! $by->isGestor()) {
            throw new DomainException('Pagar é do gestor: o atendimento lança o boleto, o gestor confere e paga.');
        }

        $this->applyCompany($slip);       // o pagador (ou a nota) pode ter passado a ser de uma empresa do grupo
        $slip->refresh();
        $today = PurchaseSettings::today();
        $paidOn = filled($data['paid_on'] ?? null) ? FinanceInput::date((string) $data['paid_on'], 'Data do pagamento inválida.') : $today;

        if ($paidOn > $today) {
            throw new DomainException('A data do pagamento não pode ser futura: registre no dia em que o dinheiro sair.');
        }

        match ($slip->status) {
            BankSlip::PAID => throw new DomainException('Este boleto já foi pago.'),
            BankSlip::CANCELED => throw new DomainException('Este boleto está cancelado.'),
            BankSlip::CONTESTED => throw new DomainException('Este boleto está contestado. Reabra-o antes de pagar.'),
            default => null,
        };

        $company = $slip->groupCompany;
        $party = $data['paid_by_party'] ?? null;

        if ($company && ! in_array($party, ['oficina', 'empresa'], true)) {
            throw new DomainException("Este boleto é da {$company->name}, não da oficina. Diga quem está pagando: a oficina (o valor fica a receber da {$company->name}) ou a própria {$company->name}.");
        }

        $cross = app(Dda::class)->cross($today);
        $high = array_values(array_filter(app(Occurrences::class)->all($today, $cross), fn ($o) => in_array($slip->id, $o['slips'], true) && $o['severity'] === 'alta'));
        $serious = array_values(array_filter($high, fn ($o) => ! $o['accepted']));
        $acceptedHigh = array_values(array_filter($high, fn ($o) => (bool) $o['accepted']));
        $ddaConfirmed = $slip->barcode && (app(Dda::class)->statusBySlip($cross)[$slip->id] ?? null) === 'confirmado';
        $bankUnchecked = ! $slip->bank_checked_on && ! ($data['bank_checked'] ?? false) && ! $ddaConfirmed;
        $reason = trim((string) ($data['reason'] ?? ''));
        $release = ($data['release'] ?? false) && PurchaseSettings::validReason($reason);
        $blocking = $serious;

        if ($bankUnchecked) {
            $supplier = $slip->supplier;
            array_unshift($blocking, [
                'key' => 'confira_recebedor:boleto:'.$slip->id, 'type' => 'confira_recebedor', 'severity' => 'alta', 'entity' => 'boleto', 'id' => $slip->id, 'slips' => [$slip->id],
                'title' => 'Confira quem recebe no app do banco', 'accepted' => null, 'amount_cents' => $slip->amount_cents,
                'detail' => 'Cole a linha digitável no app do banco e leia o nome e o CNPJ de quem recebe. Deve ser '.($supplier?->name ?? 'o fornecedor')
                    .($supplier?->document ? ' (CNPJ '.Document::format($supplier->document).')' : '').'. Se for outro nome ou outro CNPJ, não pague e ligue para o fornecedor.',
            ]);
        }

        if ($serious !== [] && ! $release) {
            throw new PaymentBlocked(($data['release'] ?? false) ? PurchaseSettings::MIN_REASON : 'Há problemas sérios neste boleto. Resolva ou libere o pagamento informando o motivo.', $blocking);
        }

        if ($bankUnchecked && ! $release) {
            throw new PaymentBlocked('Antes de pagar, confira no app do banco quem recebe este boleto.', $blocking);
        }

        $paidCents = filled($data['paid_amount'] ?? null) ? $this->amount($data['paid_amount']) : null;
        $differs = $paidCents !== null && abs($paidCents - $slip->amount_cents) > PurchaseSettings::toleranceCents();

        if ($differs && ! PurchaseSettings::validReason($reason)) {
            throw new DomainException('O valor pago ('.Money::brl($paidCents).') é diferente do boleto ('.Money::brl($slip->amount_cents).'). Explique o motivo (juros, multa, desconto). '.PurchaseSettings::MIN_REASON);
        }

        $parts = [];

        if ($release || $differs) {
            $parts[] = $reason;
        }

        foreach ($acceptedHigh as $o) {
            $parts[] = 'Aceito antes: '.$o['title'].' ('.$o['accepted']['reason'].')';
        }

        $record = mb_substr(implode(' | ', $parts), 0, 600) ?: null;

        DB::transaction(function () use ($slip, $record, $today, $data, $paidOn, $paidCents, $by, $company, $party, $serious, $acceptedHigh) {
            $slip->update([
                'status' => BankSlip::PAID, 'released_reason' => $record, 'released_on' => $record ? $today : null,
                'bank_checked_on' => $slip->bank_checked_on ?? (($data['bank_checked'] ?? false) ? $today : null),
                'paid_on' => $paidOn, 'paid_cents' => $paidCents ?? $slip->amount_cents, 'paid_by' => $by->id, 'paid_by_party' => $company ? $party : null,
            ]);

            if ($slip->payable && ! $slip->payable->isPaid()) {
                $slip->payable->update(['status' => Payable::STATUS_PAID, 'paid_on' => $paidOn, 'paid_by' => $by->id]);
                AuditLog::record($slip->payable, 'payable.paid', 'Pago pela conferência de compras', ['paid_on' => $paidOn]);
            }

            if ($company && $party === 'oficina') {
                // Se as peças destas notas já foram entregues à empresa (a receber pelo custo delas), o mesmo gasto não pode
                // virar a receber duas vezes.
                $byPart = DB::table('group_settlements')->where('source', 'peca')
                    ->whereIn('purchase_allocation_id', DB::table('purchase_allocations')->select('purchase_allocations.id')
                        ->join('purchase_invoice_items', 'purchase_invoice_items.id', '=', 'purchase_allocations.purchase_invoice_item_id')
                        ->whereIn('purchase_invoice_items.purchase_invoice_id', PurchaseLink::query()->where('bank_slip_id', $slip->id)->select('purchase_invoice_id')))
                    ->exists();

                if ($byPart) {
                    throw new DomainException('As peças das notas deste boleto já estão como "a receber" da empresa (entrega de peças). Desfaça o destino das peças ou escolha "a própria empresa paga", senão o mesmo gasto contaria duas vezes.');
                }

                $this->companies->settlementFromSlip($company, $slip, $paidCents ?? $slip->amount_cents, $paidOn, $by);
            }

            AuditLog::record($slip, $serious !== [] || $acceptedHigh !== [] ? 'purchase.slip_paid_released' : 'purchase.slip_paid', $record, [
                'valor' => $paidCents ?? $slip->amount_cents, 'conferiu_banco' => (bool) ($data['bank_checked'] ?? false), 'data' => $paidOn,
                ...($company ? ['empresa' => $company->name, 'quem_pagou' => $party] : []),
            ]);
        });

        return ['released' => $serious !== [] || $acceptedHigh !== []];
    }

    public function undoPayment(BankSlip $slip, User $by): void
    {
        if (! $by->isGestor()) {
            throw new DomainException('Desfazer o pagamento é do gestor.');
        }

        if (! $slip->isPaid()) {
            throw new DomainException('Este boleto não está pago.');
        }

        DB::transaction(function () use ($slip) {
            $this->companies->removeFromOrigin(slip: $slip);          // recusa se a outra empresa já devolveu parte
            $previous = $slip->released_reason;
            $slip->update(['status' => BankSlip::OPEN, 'released_reason' => null, 'released_on' => null, 'paid_on' => null, 'paid_cents' => null, 'paid_by' => null, 'paid_by_party' => null]);

            if ($slip->payable?->isPaid()) {
                $slip->payable->update(['status' => Payable::STATUS_OPEN, 'paid_on' => null, 'paid_by' => null]);
                AuditLog::record($slip->payable, 'payable.unpaid', 'Pagamento desfeito na conferência de compras');
            }

            AuditLog::record($slip, 'purchase.slip_payment_undone', null, ['motivo_anterior' => $previous]);
        });

        $this->applyCompany($slip);
    }

    /**
     * Cancela ou contesta. As ligações com notas somem junto (a nota volta a precisar de boleto) e a conta do "A pagar"
     * sai (a que o boleto criou é apagada; a que já existia e foi adotada volta a ser o que era). O atendimento só
     * cancela o próprio cadastro errado (boleto em aberto que ele mesmo lançou); contestar é do gestor.
     */
    public function cancel(BankSlip $slip, string $status, ?string $reason, User $by): void
    {
        $status = $status === BankSlip::CONTESTED ? BankSlip::CONTESTED : BankSlip::CANCELED;

        if (! $by->isGestor() && ($status !== BankSlip::CANCELED || ! $slip->isOpen() || (int) $slip->created_by !== $by->id || $slip->created_role !== 'atendimento')) {
            throw new DomainException('Só o gestor cancela ou contesta este boleto. O atendimento só desfaz um cadastro errado que ele mesmo fez.');
        }

        if ($slip->isPaid()) {
            throw new DomainException('Boleto já pago: não dá para cancelar.');
        }

        if (in_array($slip->status, [BankSlip::CANCELED, BankSlip::CONTESTED], true)) {
            throw new DomainException('Este boleto já está '.$slip->status.'.');
        }

        DB::transaction(function () use ($slip, $status, $reason, $by) {
            $slip->update(['status' => $status, 'canceled_role' => PurchaseSettings::role($by), 'notes' => filled($reason) ? mb_substr(trim($reason), 0, 300) : $slip->notes]);
            PurchaseLink::query()->where('bank_slip_id', $slip->id)->delete();
            $this->releasePayable($slip);
            AuditLog::record($slip, $status === BankSlip::CONTESTED ? 'purchase.slip_contested' : 'purchase.slip_canceled', $reason ?: null);
        });
    }

    /** Boleto contestado que o fornecedor confirmou, ou cancelado por engano: volta a ser cobrança em aberto (gestor). */
    public function reopen(BankSlip $slip, User $by): array
    {
        if (! $by->isGestor()) {
            throw new DomainException('Reabrir boleto é do gestor.');
        }

        if (! in_array($slip->status, [BankSlip::CANCELED, BankSlip::CONTESTED], true)) {
            throw new DomainException('Só boleto contestado ou cancelado pode ser reaberto.');
        }

        DB::transaction(function () use ($slip, $by) {
            $previous = $slip->status;
            $company = $slip->group_company_id ? $slip->groupCompany : null;
            [$payable, , $before] = $company ? [null, false, null] : $this->payableFor($slip->supplier, $slip->amount_cents, $slip->due_date->toDateString(), $slip->document_number, $by);
            $slip->update(['status' => BankSlip::OPEN, 'payable_id' => $payable?->id, 'adopted_payable' => $before, 'canceled_role' => null, 'bank_checked_on' => null]);
            AuditLog::record($slip, 'purchase.slip_reopened', 'Estava '.$previous);
        });

        $this->applyCompany($slip);       // a empresa pode ter sido cadastrada (ou desativada) enquanto o boleto estava fora

        return ['auto' => $this->autoLink($slip->fresh(), $by)];
    }

    /**
     * Decide de quem é o boleto em aberto: o CNPJ do pagador impresso manda; sem ele, vale a empresa para a qual as notas
     * ligadas foram emitidas. Boleto de empresa do grupo sai do "A pagar" da oficina; se voltar a ser da oficina, a conta
     * volta (adotada ou criada).
     */
    public function applyCompany(?BankSlip $slip): ?int
    {
        if (! $slip) {
            return null;
        }

        $slip->refresh();

        if (! $slip->isOpen()) {
            return $slip->group_company_id;
        }

        $target = null;

        if ($slip->payer_cnpj) {
            $target = $this->companies->companyFor($slip->payer_cnpj)?->id;
        } else {
            $owners = PurchaseInvoice::query()->whereIn('id', PurchaseLink::query()->where('bank_slip_id', $slip->id)->select('purchase_invoice_id'))
                ->pluck('recipient_cnpj')->map(fn ($cnpj) => $cnpj ? $this->companies->companyFor($cnpj)?->id : null)->unique()->values();

            if ($owners->count() === 1 && $owners[0] !== null) {
                $target = $owners[0];
            }
        }

        if ($target === $slip->group_company_id) {
            return $target;
        }

        DB::transaction(function () use ($slip, $target) {
            $before = $slip->group_company_id;

            if ($target !== null) {
                $this->releasePayable($slip);
                $slip->update(['group_company_id' => $target]);
            } else {
                [$payable, , $adopted] = $slip->payable ? [$slip->payable, false, $slip->adopted_payable]
                    : $this->payableFor($slip->supplier, $slip->amount_cents, $slip->due_date->toDateString(), $slip->document_number, $slip->creator);
                $slip->update(['group_company_id' => null, 'payable_id' => $payable->id, 'adopted_payable' => $adopted]);
            }

            AuditLog::record($slip, 'purchase.slip_company', null, ['de' => $before, 'para' => $target]);
        });

        return $target;
    }

    /**
     * A conta do "A pagar" deste boleto: se já existe uma conta em aberto do mesmo fornecedor, valor e vencimento (sem
     * outro boleto), o boleto a adota (não duplica a dívida); senão, cria.
     *
     * @return array{0: Payable, 1: bool, 2: ?array<string, mixed>} conta, se foi adotada e como ela era antes
     */
    private function payableFor(?Supplier $supplier, int $amount, string $due, ?string $document, ?User $by): array
    {
        if (! $supplier) {
            throw new DomainException('Escolha o fornecedor do boleto.');
        }

        $description = 'Boleto '.$supplier->name.(filled($document) ? ' doc '.mb_substr(trim($document), 0, 20) : '');
        $same = Payable::query()->open()->where('supplier_id', $supplier->id)->where('amount_cents', $amount)->where('due_date', $due)
            ->whereDoesntHave('bankSlip')->get();

        if ($same->count() === 1) {
            $payable = $same->first();
            $before = $payable->only(['description', 'document_number', 'notes']);
            $payable->update(['description' => mb_substr($description, 0, 200), 'notes' => trim(($payable->notes ? $payable->notes."\n" : '').'Conta já lançada, ligada ao boleto pela conferência de compras.')]);
            AuditLog::record($payable, 'payable.adopted_by_slip', null, ['antes' => $before]);

            return [$payable, true, $before];
        }

        $payable = Payable::create([
            'supplier_id' => $supplier->id, 'document_number' => filled($document) ? mb_substr(trim($document), 0, 60) : null,
            'description' => mb_substr($description, 0, 200), 'due_date' => $due, 'amount_cents' => $amount, 'status' => Payable::STATUS_OPEN,
            'notes' => 'Criado pela conferência de compras.', 'created_by' => $by?->id,
        ]);
        AuditLog::record($payable, 'payable.created', 'Boleto cadastrado na conferência de compras');

        return [$payable, false, null];
    }

    /** O boleto deixa de ser conta da oficina: a conta que ele criou é apagada; a que já existia volta a ser o que era. */
    private function releasePayable(BankSlip $slip): void
    {
        $payable = $slip->payable;
        $adopted = $slip->adopted_payable;
        $slip->update(['payable_id' => null, 'adopted_payable' => null]);
        $slip->unsetRelation('payable');

        if (! $payable || $payable->isPaid()) {
            return;
        }

        if ($adopted) {
            $payable->update($adopted);
            AuditLog::record($payable, 'payable.returned_from_slip', 'O boleto saiu da conferência: a conta voltou a ser o que era');
        } else {
            AuditLog::record($payable, 'payable.deleted', 'Boleto cancelado, contestado ou de outra empresa do grupo', ['supplier_id' => $payable->supplier_id, 'amount_cents' => $payable->amount_cents, 'due_date' => $payable->due_date->toDateString()]);
            $payable->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function supplierFor(array $data, ?string $payee): Supplier
    {
        if (filled($data['supplier_id'] ?? null)) {
            return Supplier::find((int) $data['supplier_id']) ?? throw new DomainException('Fornecedor não encontrado.');
        }

        $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($data['supplier_name'] ?? '')));

        if ($name !== '') {
            // O CNPJ digitado no boleto NUNCA muda um fornecedor que já existe (senão um boleto trocado "viraria" o
            // fornecedor); só serve de CNPJ quando o fornecedor é novo.
            return Supplier::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->orderBy('id')->first()
                ?? $this->suppliers->ensure($name, $payee);
        }

        if ($payee && ($byCnpj = Supplier::query()->where('document', $payee)->orderBy('id')->first())) {
            return $byCnpj;
        }

        throw new DomainException('Escolha o fornecedor do boleto (ou informe o nome de um novo). Sem fornecedor o sistema não consegue conferir nada.');
    }

    /** @return list<array{invoice: PurchaseInvoice, open: int, open_with_taxes: ?int, installments: list<array>}> */
    private function candidateInvoices(BankSlip $slip): array
    {
        $supplierId = $slip->supplier_id ?? ($slip->payee_cnpj ? Supplier::query()->where('document', $slip->payee_cnpj)->value('id') : null);
        $due = $slip->due_date;
        $invoices = PurchaseInvoice::query()->chargeable()
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->whereBetween('issue_date', [$due->subDays(180)->toDateString(), $due->addDays(7)->toDateString()])
            ->orderByDesc('issue_date')->get(['id', 'number', 'issue_date', 'total_cents', 'total_with_taxes_cents', 'supplier_id', 'status', 'purpose']);
        $out = [];

        foreach ($invoices as $invoice) {
            $linked = $this->invoices->linkedCents($invoice);
            $open = $invoice->total_cents - $linked;

            if ($open <= 0) {
                continue;
            }

            $out[] = [
                'invoice' => $invoice, 'open' => $open, 'open_with_taxes' => $invoice->total_with_taxes_cents ? $invoice->total_with_taxes_cents - $linked : null,
                'installments' => array_values(array_filter($this->invoices->installmentsWithBalance($invoice), fn ($i) => $i['balance_cents'] > 0)),
            ];
        }

        return $out;
    }

    /**
     * Combinações de 2 a 5 notas cujo saldo em aberto soma o valor do boleto (boleto agrupado/fatura), até 4.
     *
     * @param  list<array>  $candidates
     * @return list<list<array>>
     */
    private function combinations(array $candidates, int $target, int $tolerance): array
    {
        $base = array_slice($candidates, 0, 14);
        $found = [];
        $walk = function (int $start, array $chosen, int $sum) use (&$walk, &$found, $base, $target, $tolerance) {
            if (count($found) >= 4) {
                return;
            }

            if (count($chosen) >= 2 && abs($sum - $target) <= $tolerance) {
                $found[] = $chosen;

                return;
            }

            if (count($chosen) >= 5 || $sum > $target + $tolerance) {
                return;
            }

            for ($i = $start; $i < count($base); $i++) {
                $walk($i + 1, [...$chosen, $base[$i]], $sum + $base[$i]['open']);
            }
        };
        $walk(0, [], 0);

        return $found;
    }

    private function cnpj(mixed $value, string $message): ?string
    {
        $cnpj = Document::normalizeCnpj(is_string($value) ? $value : null) ?: null;

        if ($cnpj && ! Document::isValidCnpj($cnpj)) {
            throw new DomainException($message);
        }

        return $cnpj;
    }

    private function amount(mixed $value): int
    {
        $cents = is_int($value) ? $value : (Money::parseBr((string) $value) ?? 0);

        if ($cents > FinanceInput::MAX_CENTS) {
            throw new DomainException('Valor grande demais para um boleto de fornecedor: confira os números.');
        }

        if ($cents < 0) {
            throw new DomainException('O valor do boleto não pode ser negativo.');
        }

        return $cents;
    }
}
