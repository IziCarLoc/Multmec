<?php

namespace App\Services\Import;

use App\Enums\LossCause;
use App\Enums\PaymentMethod;
use App\Enums\SupplierKind;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Loss;
use App\Models\Payable;
use App\Models\ProviderPayment;
use App\Models\Receivable;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Finance\Receivables;
use App\Services\Registry\IziFleet;
use App\Support\Document;
use App\Support\LicensePlate;
use App\Support\Phone;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Traz os dados dos artefatos do claude.ai (o Financeiro e a OS v12) a partir do arquivo "Exportar tudo". Os dados
 * da oficina (config do artefato da OS) já estão na unidade Liberdade e não são lidos.
 *
 * - A prévia roda a importação inteira e desfaz no fim (o que ela mostra é exatamente o que o Importar grava).
 * - Pode rodar de novo sem duplicar: o id do documento no artefato fica em `legacy_id` ("coleção:id"). O que já
 *   veio antes não muda, só ganha a baixa (boleto pago, recebido, prestador pago) que o artefato tiver depois.
 * - Fornecedores e clientes são unidos pelo nome (sem diferença de maiúsculas) ou CNPJ; nome com "Izi" vai para o
 *   cliente Izi Car; carros pela placa. Boleto com "parcela x/y" vira parcela de um grupo.
 * - Valores do artefato vêm em reais (número): viram centavos aqui.
 */
final class ArtifactImport
{
    public const SECTIONS = [
        'fornecedores' => 'Fornecedores',
        'clientes' => 'Clientes',
        'veiculos' => 'Carros',
        'boletos' => 'A pagar (boletos)',
        'receber' => 'A receber',
        'pagamentosDouglas' => 'Prestador (Douglas)',
        'prejuizos' => 'Prejuízos',
        'os' => 'OS do v12',
    ];

    private const INSTALLMENT = '/\s*·?\s*parcela\s+(\d{1,2})\s*\/\s*(\d{1,2})\b/iu';

    /** @var array<string, array{label: string, new: int, existing: int, updated: int, cents: int, problems: list<string>}> */
    private array $report = [];

    /** @var array<string, Supplier> id do fornecedor no artefato → cadastro */
    private array $suppliers = [];

    private ?User $by = null;

    public function __construct(private Receivables $receivables) {}

    /**
     * Lê o arquivo e devolve o que vai acontecer, sem gravar nada.
     *
     * @return array{sha256: string, sections: array<string, array{label: string, new: int, existing: int, updated: int, cents: int, problems: list<string>}>}
     */
    public function preview(string $json, User $by): array
    {
        $collections = $this->read($json);

        DB::beginTransaction();

        try {
            return ['sha256' => hash('sha256', $json), 'sections' => $this->import($collections, $by)];
        } finally {
            DB::rollBack();
        }
    }

    /** Grava tudo numa transação só e devolve o mesmo resumo da prévia. */
    public function apply(string $json, User $by): array
    {
        $collections = $this->read($json);
        $sha256 = hash('sha256', $json);

        $sections = DB::transaction(function () use ($collections, $by, $sha256) {
            $sections = $this->import($collections, $by);
            AuditLog::record($by, 'artifact.imported', null, [
                'sha256' => $sha256,
                'novos' => array_map(fn ($s) => $s['new'], $sections),
                'atualizados' => array_map(fn ($s) => $s['updated'], $sections),
            ]);

            return $sections;
        });

        return ['sha256' => $sha256, 'sections' => $sections];
    }

    /**
     * Coleções do arquivo: aceita a exportação dos artefatos ({"artefatos": {"financeiro": {...}, "os": {...}}}),
     * as coleções soltas ({"boletos": {...}}) e cada coleção como objeto id → documento ou lista com "id".
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function read(string $json): array
    {
        try {
            $data = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $json) ?? $json, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new DomainException('O arquivo não é um JSON válido. Use o arquivo baixado em "Exportar tudo".');
        }

        if (! is_array($data)) {
            throw new DomainException('O arquivo não é a exportação dos artefatos.');
        }

        // "artefatos" agrupa por artefato (financeiro, os); "colecoes" já são as coleções.
        $sources = [$data, ...(is_array($data['colecoes'] ?? null) ? [$data['colecoes']] : [])];

        foreach (is_array($data['artefatos'] ?? null) ? $data['artefatos'] : [] as $artifact) {
            if (is_array($artifact)) {
                $sources[] = $artifact;
            }
        }

        $known = ['fornecedores', 'boletos', 'receber', 'pagamentosDouglas', 'prejuizos', 'clientes', 'os', 'config'];
        $collections = [];

        foreach ($sources as $source) {
            foreach ($known as $name) {
                if (! is_array($source[$name] ?? null)) {
                    continue;
                }

                foreach ($source[$name] as $key => $doc) {
                    if (! is_array($doc)) {
                        continue;
                    }

                    $id = (string) ($doc['id'] ?? $doc['_id'] ?? (is_string($key) ? $key : ''));

                    if ($id !== '') {
                        $collections[$name][$id] = $doc;
                    }
                }
            }
        }

        if ($collections === []) {
            throw new DomainException('Não achei boletos, a receber, fornecedores nem OS no arquivo. Use o arquivo baixado em "Exportar tudo".');
        }

        return $collections;
    }

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $collections
     * @return array<string, array{label: string, new: int, existing: int, updated: int, cents: int, problems: list<string>}>
     */
    private function import(array $collections, User $by): array
    {
        $this->by = $by;
        $this->suppliers = [];
        $this->report = array_map(fn ($label) => ['label' => $label, 'new' => 0, 'existing' => 0, 'updated' => 0, 'cents' => 0, 'problems' => []], self::SECTIONS);

        foreach ($collections['fornecedores'] ?? [] as $id => $doc) {
            $this->supplier((string) $id, $doc);
        }

        foreach ($collections['clientes'] ?? [] as $doc) {
            $this->customer((string) ($doc['nome'] ?? $doc['cliente'] ?? ''), (string) ($doc['telefone'] ?? $doc['whatsapp'] ?? ''), $doc);
        }

        foreach ($collections['boletos'] ?? [] as $id => $doc) {
            $this->payable((string) $id, $doc);
        }

        foreach ($collections['receber'] ?? [] as $id => $doc) {
            $this->receivable((string) $id, $doc);
        }

        foreach ($collections['pagamentosDouglas'] ?? [] as $id => $doc) {
            $this->providerPayment((string) $id, $doc);
        }

        foreach ($collections['prejuizos'] ?? [] as $id => $doc) {
            $this->loss((string) $id, $doc);
        }

        if (($orders = count($collections['os'] ?? [])) > 0) {
            $this->report['os']['problems'][] = $orders.' OS do v12 no arquivo: elas continuam no artefato (a importação das OS antigas ainda não existe).';
        }

        return $this->report;
    }

    /** @param  array<string, mixed>  $doc */
    private function supplier(string $id, array $doc): void
    {
        $name = $this->clean($doc['nome'] ?? '');

        if (mb_strlen($name) < 2) {
            $this->problem('fornecedores', 'Fornecedor sem nome (id '.$id.').');

            return;
        }

        $document = Document::clean((string) ($doc['cnpj'] ?? $doc['documento'] ?? ''));
        $phone = Phone::digits((string) ($doc['telefone'] ?? ''));
        $notes = collect([
            filled($doc['contato'] ?? null) ? 'Contato: '.$this->clean($doc['contato']) : null,
            $document !== '' && ! Document::isValid($document) ? 'CNPJ no artefato: '.$doc['cnpj'] : null,
            $phone !== '' && ! Phone::isValid($phone) ? 'Telefone no artefato: '.$doc['telefone'] : null,
            $this->clean($doc['obs'] ?? '') ?: null,
        ])->filter()->implode("\n");

        $supplier = ($document !== '' ? Supplier::query()->where('document', $document)->first() : null)
            ?? Supplier::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->orderBy('id')->first();

        $fields = [
            'document' => Document::isValid($document) ? $document : null,
            'whatsapp' => Phone::isValid($phone) ? $phone : null,
            'payment_terms' => Str::limit($this->clean($doc['pagamento'] ?? ''), 120, '') ?: null,
            'notes' => $notes ?: null,
        ];

        if ($supplier === null) {
            $supplier = Supplier::create(['name' => Str::limit($name, 120, ''), 'kind' => SupplierKind::Pecas, 'active' => true, ...$fields]);
            AuditLog::record($supplier, 'supplier.created', 'Importado do artefato');
            $this->count('fornecedores', 'new');
        } else {
            // Já cadastrado: só completa o que está em branco.
            $missing = array_filter($fields, fn ($value, $field) => $value !== null && blank($supplier->{$field}), ARRAY_FILTER_USE_BOTH);
            $supplier->fill($missing)->save();
            $this->count('fornecedores', $missing === [] ? 'existing' : 'updated');
        }

        $this->suppliers[$id] = $supplier;
    }

    /** @param  array<string, mixed>  $doc */
    private function payable(string $id, array $doc): void
    {
        $legacy = 'boletos:'.$id;
        $paidOn = $this->paidOn($doc);

        if ($payable = Payable::query()->where('legacy_id', $legacy)->first()) {
            if ($paidOn !== null && ! $payable->isPaid()) {
                $payable->update(['status' => Payable::STATUS_PAID, 'paid_on' => $paidOn, 'paid_by' => $this->by->id]);
                AuditLog::record($payable, 'payable.paid', 'Baixa vinda do artefato', ['paid_on' => $paidOn]);
                $this->count('boletos', 'updated');
            } else {
                $this->count('boletos', 'existing');
            }

            return;
        }

        $label = 'Boleto '.($this->clean($doc['doc'] ?? '') ?: $id);
        $supplier = $this->suppliers[(string) ($doc['fornecedorId'] ?? '')] ?? null;
        $amount = $this->cents($doc['valor'] ?? null);
        $dueDate = $this->date($doc['vencimento'] ?? null);

        $error = match (true) {
            $supplier === null => 'o fornecedor dele não está no arquivo',
            $amount <= 0 => 'sem valor',
            $dueDate === null => 'sem vencimento',
            default => null,
        };

        if ($error !== null) {
            $this->problem('boletos', $label.': '.$error.'. Lance à mão.');

            return;
        }

        $texts = [$doc['doc'] ?? '', $doc['desc'] ?? '', $doc['obs'] ?? ''];
        $installment = collect($texts)->map(fn ($t) => preg_match(self::INSTALLMENT, (string) $t, $m) ? [(int) $m[1], (int) $m[2]] : null)->filter()->first();
        $installment = $installment && $installment[0] >= 1 && $installment[0] <= $installment[1] ? $installment : null;
        $document = Str::limit($this->stripInstallment($doc['doc'] ?? ''), 60, '');

        $payable = Payable::create([
            'legacy_id' => $legacy,
            'supplier_id' => $supplier->id,
            'document_number' => $document ?: null,
            'description' => Str::limit($this->stripInstallment($doc['desc'] ?? ''), 200, '') ?: null,
            'issue_date' => $this->date($doc['emissao'] ?? null),
            'due_date' => $dueDate,
            'amount_cents' => $amount,
            'notes' => $this->clean($doc['obs'] ?? '') ?: null,
            // Parcelas do mesmo fornecedor e documento caem no mesmo grupo (o id do grupo sai deles, então a
            // parcela que chegar numa importação seguinte entra no grupo certo).
            'installment_group' => $installment ? $this->uuid($supplier->id.'|'.(preg_replace('/\/\d+$/', '', $document) ?: $id)) : null,
            'installment_number' => $installment[0] ?? null,
            'installment_total' => $installment[1] ?? null,
            'status' => $paidOn ? Payable::STATUS_PAID : Payable::STATUS_OPEN,
            'paid_on' => $paidOn,
            'paid_by' => $paidOn ? $this->by->id : null,
            'created_by' => $this->by->id,
        ]);
        AuditLog::record($payable, 'payable.created', 'Importado do artefato');
        $this->count('boletos', 'new', $amount);
    }

    /** @param  array<string, mixed>  $doc */
    private function receivable(string $id, array $doc): void
    {
        $legacy = 'receber:'.$id;
        $received = ($doc['status'] ?? '') === 'recebido' || filled($doc['recebidoEm'] ?? null);
        $receivedOn = $this->date($doc['recebidoEm'] ?? null) ?? $this->date($doc['vencimento'] ?? null);

        if ($receivable = Receivable::query()->where('legacy_id', $legacy)->first()) {
            if ($received && $receivedOn && ! $receivable->isWrittenOff() && $receivable->balanceCents() > 0) {
                $this->receivables->receiveRemaining($receivable, $receivedOn, $this->by);
                $this->count('receber', 'updated');
            } else {
                $this->count('receber', 'existing');
            }

            return;
        }

        $label = 'OS '.($this->clean($doc['os'] ?? '') ?: '—').' ('.($this->clean($doc['cliente'] ?? '') ?: 'sem cliente').')';
        $total = $this->cents($doc['valor'] ?? null);
        $dueDate = $this->date($doc['vencimento'] ?? null) ?? $this->date($doc['dataManutencao'] ?? null);
        $name = $this->clean($doc['cliente'] ?? '');

        $error = match (true) {
            $name === '' => 'sem cliente',
            $total <= 0 => 'sem valor',
            $dueDate === null => 'sem vencimento',
            default => null,
        };

        if ($error !== null) {
            $this->problem('receber', $label.': '.$error.'. Lance à mão.');

            return;
        }

        $customer = $this->customer($name, (string) ($doc['telefone'] ?? ''));
        $plate = LicensePlate::clean((string) ($doc['placa'] ?? ''));
        $vehicle = $this->vehicle($plate, $this->clean($doc['modelo'] ?? ''), $customer);
        $broughtPart = (bool) ($doc['clienteTrouxePeca'] ?? false);
        $method = PaymentMethod::tryFrom(Str::lower((string) ($doc['formaPagamento'] ?? '')));

        $receivable = Receivable::create([
            'legacy_id' => $legacy,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle?->id,
            'order_number' => Str::limit($this->clean($doc['os'] ?? ''), 20, '') ?: null,
            'service_date' => $this->date($doc['dataManutencao'] ?? null),
            'plate' => $plate !== '' ? Str::limit($plate, 10, '') : null,
            'vehicle_model' => Str::limit($this->clean($doc['modelo'] ?? ''), 80, '') ?: null,
            'parts_cost_cents' => $broughtPart ? 0 : $this->cents($doc['custoPeca'] ?? null),
            'labor_cents' => $this->cents($doc['valorMo'] ?? null),
            'freight_cents' => $this->cents($doc['valorFrete'] ?? null),
            'total_cents' => $total,
            'customer_brought_part' => $broughtPart,
            'received_by_matrix' => (bool) ($doc['recebimentoMatriz'] ?? false),
            'payment_method' => $method?->value,
            'due_date' => $dueDate,
            'notes' => collect([
                $this->clean($doc['obs'] ?? '') ?: null,
                mb_strtolower($customer->name) !== mb_strtolower($name) ? 'Cliente no artefato: '.$name : null,
                $plate !== '' && $vehicle === null ? 'Placa no artefato não parece válida.' : null,
            ])->filter()->implode("\n") ?: null,
            'created_by' => $this->by->id,
        ]);
        AuditLog::record($receivable, 'receivable.created', 'Importado do artefato');

        $this->payments($receivable, $doc, $received ? $receivedOn : null, $label);
        $this->count('receber', 'new', $total);
    }

    /**
     * Pagamentos do lançamento: a lista do artefato (pagamentos parciais) ou, se não houver, o recebimento inteiro.
     *
     * @param  array<string, mixed>  $doc
     */
    private function payments(Receivable $receivable, array $doc, ?string $receivedOn, string $label): void
    {
        $partials = collect(is_array($doc['pagamentos'] ?? null) ? $doc['pagamentos'] : [])
            ->filter(fn ($p) => is_array($p))
            ->map(fn ($p) => [$this->date($p['data'] ?? $p['em'] ?? $p['pagoEm'] ?? null), $this->cents($p['valor'] ?? null)])
            ->filter(fn ($p) => $p[0] !== null && $p[1] > 0);

        foreach ($partials as [$date, $cents]) {
            $balance = $receivable->fresh()->balanceCents();

            if ($balance <= 0) {
                $this->problem('receber', $label.': pagamento de '.$date.' passa do valor e ficou de fora.');

                continue;
            }

            $this->receivables->addPayment($receivable, $date, min($cents, $balance), $this->by);
        }

        if ($receivedOn !== null && $receivable->fresh()->balanceCents() > 0) {
            $this->receivables->receiveRemaining($receivable, $receivedOn, $this->by);
        }
    }

    /** @param  array<string, mixed>  $doc */
    private function providerPayment(string $id, array $doc): void
    {
        $legacy = 'pagamentosDouglas:'.$id;
        $paidOn = $this->paidOn($doc);

        if ($payment = ProviderPayment::query()->where('legacy_id', $legacy)->first()) {
            if ($paidOn !== null && ! $payment->isPaid()) {
                $payment->update(['paid_on' => $paidOn, 'paid_by' => $this->by->id]);
                AuditLog::record($payment, 'provider_payment.paid', 'Baixa vinda do artefato', ['paid_on' => $paidOn]);
                $this->count('pagamentosDouglas', 'updated');
            } else {
                $this->count('pagamentosDouglas', 'existing');
            }

            return;
        }

        $amount = $this->cents($doc['valor'] ?? null);
        $dueDate = $this->date($doc['vencimento'] ?? null);

        if ($amount <= 0 || $dueDate === null) {
            $this->problem('pagamentosDouglas', 'OS '.($this->clean($doc['os'] ?? '') ?: '—').': '.($amount <= 0 ? 'sem valor' : 'sem vencimento').'. Lance à mão.');

            return;
        }

        $payment = ProviderPayment::create([
            'legacy_id' => $legacy,
            'supplier_id' => $this->douglas()->id,
            'order_number' => Str::limit($this->clean($doc['os'] ?? ''), 20, '') ?: null,
            'description' => Str::limit($this->clean($doc['descricao'] ?? ''), 200, '') ?: null,
            'plate' => Str::limit(LicensePlate::clean((string) ($doc['placa'] ?? '')), 10, '') ?: null,
            'amount_cents' => $amount,
            'due_date' => $dueDate,
            'paid_on' => $paidOn,
            'paid_by' => $paidOn ? $this->by->id : null,
            'notes' => $this->clean($doc['obs'] ?? '') ?: null,
            'created_by' => $this->by->id,
        ]);
        AuditLog::record($payment, 'provider_payment.created', 'Importado do artefato');
        $this->count('pagamentosDouglas', 'new', $amount);
    }

    /** @param  array<string, mixed>  $doc */
    private function loss(string $id, array $doc): void
    {
        $legacy = 'prejuizos:'.$id;

        if (Loss::query()->where('legacy_id', $legacy)->exists()) {
            $this->count('prejuizos', 'existing');

            return;
        }

        $amount = $this->cents($doc['valor'] ?? null);
        $date = $this->date($doc['data'] ?? $doc['dataOcorrencia'] ?? null);
        $item = $this->clean($doc['item'] ?? $doc['descricao'] ?? '');

        if ($amount <= 0 || $date === null || $item === '') {
            $this->problem('prejuizos', 'Prejuízo '.($item ?: $id).': falta '.($amount <= 0 ? 'o valor' : ($date === null ? 'a data' : 'o item')).'. Lance à mão.');

            return;
        }

        $loss = Loss::create([
            'legacy_id' => $legacy,
            'occurred_on' => $date,
            'amount_cents' => $amount,
            'item' => Str::limit($item, 200, ''),
            'cause' => (LossCause::tryFrom(Str::lower(Str::ascii((string) ($doc['causa'] ?? '')))) ?? LossCause::Outro)->value,
            'notes' => $this->clean($doc['obs'] ?? '') ?: null,
            'created_by' => $this->by->id,
        ]);
        AuditLog::record($loss, 'loss.created', 'Importado do artefato');
        $this->count('prejuizos', 'new', $amount);
    }

    /**
     * Cliente pelo nome (sem diferença de maiúsculas). Nome com "Izi" é a Izi Car. O artefato não guarda
     * WhatsApp na maioria dos lançamentos: o cliente entra sem ele e completa-se em Clientes e veículos.
     *
     * @param  array<string, mixed>  $doc
     */
    private function customer(string $name, string $phone, array $doc = []): Customer
    {
        $name = $this->clean($name);

        if (preg_match('/\bIZI/u', Str::upper(Str::ascii($name))) === 1) {
            return IziFleet::customer();
        }

        $phone = Phone::digits($phone);
        $customer = Customer::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->orderBy('id')->first()
            ?? (Phone::isValid($phone) ? Customer::query()->where('whatsapp', $phone)->first() : null);

        if ($customer !== null) {
            if ($doc !== []) {
                $this->count('clientes', 'existing');
            }

            return $customer;
        }

        $document = Document::clean((string) ($doc['documento'] ?? $doc['cpf'] ?? $doc['cnpj'] ?? $doc['cpfCnpj'] ?? ''));
        $customer = Customer::create([
            'name' => Str::limit($name, 120, ''),
            'whatsapp' => Phone::isValid($phone) ? $phone : null,
            'document' => Document::isValid($document) ? $document : null,
            'email' => filter_var($doc['email'] ?? null, FILTER_VALIDATE_EMAIL) ?: null,
            'address' => Str::limit($this->clean($doc['endereco'] ?? ''), 255, '') ?: null,
        ]);
        AuditLog::record($customer, 'customer.created', 'Importado do artefato');
        $this->count('clientes', 'new');

        return $customer;
    }

    /** Carro pela placa; placa nova entra no cadastro com o modelo e o cliente do lançamento. */
    private function vehicle(string $plate, string $model, Customer $customer): ?Vehicle
    {
        if (! Vehicle::isValidPlate($plate)) {
            return null;
        }

        if ($vehicle = Vehicle::byPlate($plate)) {
            return $vehicle;
        }

        $vehicle = Vehicle::create([
            'plate' => $plate,
            'brand_model' => Str::limit($model, 80, '') ?: null,
            'customer_id' => $customer->id,
            'is_izi_fleet' => (bool) $customer->is_izi_fleet,
        ]);
        $this->count('veiculos', 'new');

        return $vehicle;
    }

    /** O prestador dos "pagamentos Douglas": o cadastro com esse nome ou um novo, de serviço. */
    private function douglas(): Supplier
    {
        $supplier = Supplier::query()->whereRaw('lower(name) = ?', ['douglas'])->orderBy('id')->first();

        if ($supplier === null) {
            $supplier = Supplier::create(['name' => 'Douglas', 'kind' => SupplierKind::Servico, 'notes' => 'Eletricista / ar-condicionado (importado do artefato do Financeiro).', 'active' => true]);
            AuditLog::record($supplier, 'supplier.created', 'Importado do artefato');
            $this->count('fornecedores', 'new');
        } elseif (! $supplier->kind->providesService()) {
            $supplier->update(['kind' => SupplierKind::Ambos]);
        }

        return $supplier;
    }

    /** @param  array<string, mixed>  $doc */
    private function paidOn(array $doc): ?string
    {
        $paid = ($doc['status'] ?? '') === 'pago' || filled($doc['pagoEm'] ?? null);

        return $paid ? ($this->date($doc['pagoEm'] ?? null) ?? $this->date($doc['vencimento'] ?? null)) : null;
    }

    /** Reais do artefato (número ou texto "1234.56") em centavos. */
    private function cents(mixed $value): int
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', preg_replace('/[^\d,.-]/', '', $value) ?? '');
        }

        return is_numeric($value) ? max(0, (int) round(((float) $value) * 100)) : 0;
    }

    private function date(mixed $value): ?string
    {
        $value = substr(trim((string) $value), 0, 10);

        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }

    private function clean(mixed $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
    }

    private function stripInstallment(mixed $text): string
    {
        return $this->clean(preg_replace(self::INSTALLMENT, '', (string) $text) ?? '');
    }

    /**
     * UUID fixo a partir de um texto (o mesmo texto dá sempre o mesmo grupo de parcelas). Versão 5 de verdade: a
     * coluna `uuid` do MariaDB recusa um UUID com os bits de versão e variante fora do padrão.
     */
    private function uuid(string $seed): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_OID, 'multmec-artefato|'.$seed)->toString();
    }

    private function count(string $section, string $what, int $cents = 0): void
    {
        $this->report[$section][$what]++;
        $this->report[$section]['cents'] += $cents;
    }

    private function problem(string $section, string $message): void
    {
        $this->report[$section]['problems'][] = $message;
    }
}
