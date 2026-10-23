<?php

use App\Enums\CreditKind;
use App\Livewire\Concerns\FinanceAccess;
use App\Livewire\Concerns\RunsDomainActions;
use App\Models\Customer;
use App\Services\Finance\Credit;
use App\Services\Finance\MoneyRules;
use App\Support\Money;
use App\Support\Phone;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Crédito a prazo (Izi Car, frotas, revendas): a carteira com a situação de cada cliente (em dia, atenção, TRAVADO),
 * as faixas de atraso, "Recebi" (quita o saldo anterior e depois o mais antigo), extrato e texto de cobrança para
 * copiar. Tipo, prazo, limite, saldo anterior, liberação e a regra da trava são do gestor.
 */
new #[Title('Crédito a prazo')] class extends Component
{
    use FinanceAccess;
    use RunsDomainActions;

    #[Url(as: 'busca', except: '')]
    public string $search = '';

    /** Janela aberta: receber, extrato, saldo, liberar, credito. */
    public string $dialog = '';

    public ?int $customerId = null;

    public string $amount = '';

    public string $date = '';

    public string $reason = '';

    public string $kind = 'frota';

    public string $days = '0';

    public string $limit = '';

    /** Gestor: procurar cliente fora da carteira para dar crédito. */
    public string $addSearch = '';

    public string $lockDays = '';

    public string $warningPct = '';

    public function mount(MoneyRules $rules): void
    {
        $values = $rules->values();
        $this->lockDays = (string) $values['credit_lock_days'];
        $this->warningPct = rtrim(rtrim(number_format($values['credit_warning_pct'], 2, ',', ''), '0'), ',');
    }

    #[Computed]
    public function portfolio()
    {
        return app(Credit::class)->portfolio();
    }

    #[Computed]
    public function rows()
    {
        $term = mb_strtolower(trim($this->search));

        return $term === '' ? $this->portfolio : $this->portfolio->filter(fn (array $s) => str_contains(mb_strtolower($s['customer']->name), $term))->values();
    }

    #[Computed]
    public function customer(): ?Customer
    {
        return $this->customerId ? Customer::find($this->customerId) : null;
    }

    #[Computed]
    public function statement(): array
    {
        return $this->customer && $this->dialog === 'extrato' ? array_slice(app(Credit::class)->statement($this->customer), -60) : [];
    }

    #[Computed]
    public function collectionText(): string
    {
        return $this->customer && $this->dialog === 'extrato' ? app(Credit::class)->collectionText($this->customer) : '';
    }

    #[Computed]
    public function addResults()
    {
        $term = trim($this->addSearch);

        return mb_strlen($term) < 2 ? collect() : Customer::query()->search($term)->orderBy('name')->limit(6)->get();
    }

    public function open(string $dialog, int $customerId): void
    {
        if (! in_array($dialog, ['receber', 'extrato', 'saldo', 'liberar', 'credito'], true)) {
            return;
        }

        $customer = Customer::findOrFail($customerId);
        $this->resetValidation();
        $this->customerId = $customer->id;
        $this->dialog = $dialog;
        $this->amount = '';
        $this->reason = '';
        $this->date = today()->toDateString();
        $this->kind = $customer->credit_kind === CreditKind::Avulso ? CreditKind::Frota->value : $customer->credit_kind->value;
        $this->days = (string) $customer->credit_days;
        $this->limit = $customer->credit_limit_cents > 0 ? number_format($customer->credit_limit_cents / 100, 2, ',', '.') : '';
        $this->addSearch = '';
        unset($this->customer, $this->statement, $this->collectionText);
    }

    public function close(): void
    {
        $this->dialog = '';
    }

    public function receive(Credit $credit): void
    {
        $applied = $this->attempt(fn () => $credit->receive($this->customer, (string) $this->amount, $this->date, auth()->user()), 'dialog');

        if ($applied) {
            $this->done('Recebimento registrado: quitou '.count($applied).' lançamento(s), do mais antigo para o mais novo.');
        }
    }

    public function saveOpening(Credit $credit): void
    {
        if ($this->attempt(fn () => $credit->setOpeningBalance($this->customer, (string) $this->amount, $this->date, auth()->user()), 'dialog')) {
            $this->done('Saldo anterior lançado no A receber do cliente.');
        }
    }

    public function release(Credit $credit): void
    {
        if ($this->attempt(fn () => $credit->release($this->customer, $this->date, $this->reason, auth()->user()), 'dialog')) {
            $this->done('Liberado para comprar a prazo até '.\Carbon\CarbonImmutable::parse($this->date)->format('d/m/Y').'.');
        }
    }

    public function revoke(Credit $credit): void
    {
        if ($this->attempt(fn () => $credit->revokeRelease($this->customer, auth()->user()), 'dialog')) {
            $this->done('Liberação retirada.');
        }
    }

    public function saveTerms(Credit $credit): void
    {
        if ($this->attempt(fn () => $credit->updateTerms($this->customer, ['kind' => $this->kind, 'days' => $this->days, 'limit' => (string) $this->limit], auth()->user()), 'dialog')) {
            $this->done('Crédito do cliente salvo.');
        }
    }

    public function saveRules(MoneyRules $rules): void
    {
        if ($this->attempt(fn () => $rules->saveCredit($this->lockDays, $this->warningPct, auth()->user()), 'rules')) {
            unset($this->portfolio, $this->rows);
            $this->dispatch('toast', message: 'Regra da trava salva.', type: 'success');
        }
    }

    private function done(string $message): void
    {
        $this->dialog = '';
        unset($this->portfolio, $this->rows, $this->customer, $this->statement, $this->collectionText);
        $this->dispatch('toast', message: $message, type: 'success');
    }
};
?>

@php
    $gestor = auth()->user()->can('gestor');
    $all = $this->portfolio;
    $pill = 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap';
    $badge = fn (string $status) => match ($status) {
        Credit::LOCKED => ['TRAVADO', 'bg-stripe text-white'],
        Credit::ATTENTION => ['atenção', 'bg-brand-50 text-brand-800 ring-1 ring-brand-200 dark:bg-brand-900/40 dark:text-brand-200 dark:ring-brand-800'],
        default => ['em dia', 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200'],
    };
    $bandColor = ['a_vencer' => 'bg-gray-300 dark:bg-gray-500', 'ate_7' => 'bg-brand-300', 'de_8_a_15' => 'bg-brand-600', 'de_16_a_30' => 'bg-stripe', 'mais_30' => 'bg-stripe-dark'];
@endphp
<div class="space-y-4">
    <x-finance-nav />

    <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide flex items-center gap-2">
        <span class="w-1 h-6 rounded bg-brand-600"></span>Crédito a prazo
    </h1>
    <p class="text-sm text-gray-600 dark:text-gray-300" data-test="regra-trava">Cliente a prazo trava ao passar do limite ou de {{ $lockDays }} dias de atraso. Travado, ou sem limite de crédito, só leva o carro pagando à vista, a não ser que o gestor libere com o motivo.</p>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-2">
        <x-finance-tile label="A prazo em aberto" :value="$money((int) $all->sum('exposure'))" :note="$all->where('exposure', '>', 0)->count().' cliente(s) devendo'" tone="brand" data-test="total-a-prazo" />
        <x-finance-tile label="Vencido" :value="$money((int) $all->sum('overdue'))" note="dos clientes a prazo" :tone="$all->sum('overdue') > 0 ? 'bad' : 'plain'" data-test="total-vencido-prazo" />
        <x-finance-tile label="Travados" :value="(string) $all->where('status', Credit::LOCKED)->count()" note="só à vista" :tone="$all->where('status', Credit::LOCKED)->isNotEmpty() ? 'bad' : 'plain'" data-test="total-travados" />
        <x-finance-tile label="Na carteira" :value="(string) $all->count()" note="clientes com prazo ou limite" />
    </div>

    <div class="flex flex-wrap gap-2">
        <div class="relative flex-1 min-w-[12rem]">
            <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Buscar na carteira" class="{{ $inputClass }} pl-9" data-test="busca">
        </div>
    </div>

    <div class="space-y-3">
        @forelse ($this->rows as $s)
            @php
                $c = $s['customer'];
                [$badgeText, $badgeClass] = $badge($s['status']);
                $bandsTotal = max(1, array_sum($s['bands']));
            @endphp
            <section @class([$cardClass, 'p-4 space-y-3', 'border-l-4 border-l-stripe' => $s['status'] === Credit::LOCKED]) wire:key="c-{{ $c->id }}" data-test="cliente-{{ $c->id }}">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="font-display font-bold text-xl uppercase break-words min-w-0">{{ $c->name }}</h2>
                    <span class="{{ $pill }} {{ $badgeClass }}" data-test="situacao-{{ $c->id }}">{{ $badgeText }}</span>
                    @if ($s['released'])
                        <span class="{{ $pill }} bg-brand-600 text-white">liberado até {{ $c->credit_release_until->format('d/m') }}</span>
                    @endif
                    <span class="text-xs text-gray-500 sm:ml-auto">{{ $c->credit_kind->label() }}</span>
                </div>
                @if ($s['reason'] !== '')
                    <p @class(['text-sm', 'text-red-700 dark:text-red-300 font-semibold' => $s['status'] === Credit::LOCKED, 'text-gray-600 dark:text-gray-300' => $s['status'] !== Credit::LOCKED])>
                        {{ $s['status'] === Credit::LOCKED ? 'Só atender com pagamento à vista: '.$s['reason'].'.' : ucfirst($s['reason']).'.' }}
                    </p>
                @endif
                <dl class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                    <div><dt class="text-xs uppercase tracking-wide text-gray-500">Deve</dt><dd class="font-display font-bold text-lg">{{ $money($s['exposure']) }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-gray-500">Vencido</dt><dd @class(['font-display font-bold text-lg', 'text-red-700 dark:text-red-400' => $s['overdue'] > 0])>{{ $money($s['overdue']) }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-gray-500">Limite</dt><dd class="font-semibold">{{ $c->credit_limit_cents > 0 ? $money($c->credit_limit_cents).' ('.round(($s['usage'] ?? 0) * 100).'% usado)' : 'sem crédito' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-gray-500">Prazo</dt><dd class="font-semibold">{{ $c->credit_days > 0 ? $c->credit_days.' dias' : 'na retirada' }}</dd></div>
                </dl>
                @if ($s['exposure'] > 0)
                    <div class="space-y-1">
                        <div class="flex h-3 rounded-full overflow-hidden bg-gray-100 dark:bg-gray-700" role="img" aria-label="Valor em aberto por faixa de atraso">
                            @foreach ($s['bands'] as $band => $cents)
                                @if ($cents > 0)
                                    <div class="{{ $bandColor[$band] }}" style="width: {{ round($cents / $bandsTotal * 100, 1) }}%"></div>
                                @endif
                            @endforeach
                        </div>
                        <ul class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600 dark:text-gray-300">
                            @foreach ($s['bands'] as $band => $cents)
                                @if ($cents > 0)
                                    <li class="flex items-center gap-1"><span class="inline-block size-2.5 rounded-full {{ $bandColor[$band] }}"></span>{{ Credit::BANDS[$band] }}: <b>{{ $money($cents) }}</b></li>
                                @endif
                            @endforeach
                        </ul>
                    </div>
                @endif
                <div class="grid grid-cols-2 sm:flex sm:flex-wrap gap-2">
                    <button type="button" wire:click="open('receber', {{ $c->id }})" class="{{ $btnPrimary }}" @disabled($s['exposure'] <= 0) data-test="recebi-{{ $c->id }}"><i class="fas fa-hand-holding-dollar"></i> Recebi</button>
                    <button type="button" wire:click="open('extrato', {{ $c->id }})" class="{{ $btnSecondary }}" data-test="extrato-{{ $c->id }}"><i class="fas fa-list"></i> Extrato e cobrança</button>
                    @if ($gestor)
                        <button type="button" wire:click="open('credito', {{ $c->id }})" class="{{ $btnSecondary }}" data-test="editar-credito-{{ $c->id }}"><i class="fas fa-pen"></i> Prazo e limite</button>
                        <button type="button" wire:click="open('saldo', {{ $c->id }})" class="{{ $btnSecondary }}" data-test="saldo-anterior-{{ $c->id }}"><i class="fas fa-clock-rotate-left"></i> Saldo anterior</button>
                        <button type="button" wire:click="open('liberar', {{ $c->id }})" class="{{ $btnSecondary }}" data-test="liberar-{{ $c->id }}"><i class="fas fa-unlock"></i> Liberar</button>
                    @endif
                </div>
            </section>
        @empty
            <p class="{{ $cardClass }} p-6 text-center text-gray-600 dark:text-gray-300">
                {{ $all->isEmpty() ? 'Nenhum cliente a prazo ainda.'.($gestor ? ' Procure o cliente abaixo e defina tipo, prazo e limite.' : ' O gestor define tipo, prazo e limite.') : 'Nenhum cliente com esse nome na carteira.' }}
            </p>
        @endforelse
    </div>

    @if ($gestor)
        <section class="{{ $cardClass }} p-4 space-y-3" data-test="adicionar-cliente">
            <h2 class="font-display font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Dar crédito a um cliente</h2>
            <div class="relative">
                <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                <input type="search" wire:model.live.debounce.300ms="addSearch" placeholder="Nome, telefone ou CPF/CNPJ" class="{{ $inputClass }} pl-9" data-test="busca-cliente-credito">
            </div>
            @if ($this->addResults->isNotEmpty())
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($this->addResults as $result)
                        <button type="button" wire:click="open('credito', {{ $result->id }})" class="w-full text-left min-h-11 px-3 py-2 hover:bg-gray-50 dark:hover:bg-gray-700/40" wire:key="a-{{ $result->id }}">
                            <span class="font-semibold">{{ $result->name }}</span> <span class="text-sm text-gray-500">{{ $result->whatsappFormatted() }}</span>
                        </button>
                    @endforeach
                </div>
            @endif
        </section>

        <form wire:submit="saveRules" class="{{ $cardClass }} p-4 space-y-3" data-test="regras-credito">
            <h2 class="font-display font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Regra da trava</h2>
            @error('rules') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            <div class="grid gap-3 grid-cols-2">
                <div>
                    <label class="{{ $labelClass }}" for="lockDays">Travar após (dias de atraso)</label>
                    <input id="lockDays" type="number" min="0" max="365" inputmode="numeric" wire:model="lockDays" class="{{ $inputClass }}" data-test="dias-trava">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="warningPct">Atenção com o limite usado em (%)</label>
                    <input id="warningPct" type="text" inputmode="decimal" wire:model="warningPct" class="{{ $inputClass }}" data-test="aviso-limite">
                </div>
            </div>
            <div class="flex justify-end"><button type="submit" class="{{ $btnSecondary }}"><i class="fas fa-check"></i> Salvar regra</button></div>
        </form>
    @endif

    @if ($dialog !== '' && $this->customer)
        @php($c = $this->customer)
        @if ($dialog === 'receber')
            <x-modal close="close" form="receive" size="md" data-test="janela-receber-cliente">
                <h2 class="font-display font-bold text-xl uppercase">Recebi de {{ $c->name }}</h2>
                <p class="text-sm text-gray-600 dark:text-gray-300">O valor quita primeiro o saldo anterior e depois o vencimento mais antigo (cada pagamento entra no lançamento dele, no A receber).</p>
                @error('dialog') <p class="text-sm text-red-600" data-test="erro-janela">{{ $message }}</p> @enderror
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="{{ $labelClass }}" for="r-valor">Valor recebido (R$)</label>
                        <input id="r-valor" type="text" inputmode="decimal" wire:model="amount" placeholder="0,00" class="{{ $inputClass }} font-semibold" data-test="valor-recebido">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="r-data">Data</label>
                        <input id="r-data" type="date" wire:model="date" class="{{ $inputClass }}">
                    </div>
                </div>
                <div class="flex flex-wrap justify-end gap-2">
                    <button type="button" wire:click="close" class="{{ $btnSecondary }}">Fechar</button>
                    <button type="submit" class="{{ $btnPrimary }}" data-test="registrar-recebimento"><i class="fas fa-check"></i> Registrar</button>
                </div>
            </x-modal>
        @elseif ($dialog === 'extrato')
            <x-modal close="close" size="2xl" scroll data-test="janela-extrato">
                <h2 class="font-display font-bold text-xl uppercase">Extrato · {{ $c->name }}</h2>
                <div x-data="{ copied: false }" class="flex flex-wrap gap-2">
                    <button type="button" x-on:click="navigator.clipboard.writeText($refs.texto.textContent); copied = true; setTimeout(() => copied = false, 2000)" class="{{ $btnPrimary }}" data-test="copiar-cobranca"><i class="fas fa-copy"></i> <span x-text="copied ? 'Copiado: cole no WhatsApp' : 'Copiar cobrança'"></span></button>
                    @if ($link = Phone::whatsappLink($c->whatsapp, $this->collectionText))
                        <a href="{{ $link }}" target="_blank" rel="noopener" class="{{ $btnSecondary }}"><i class="fab fa-whatsapp"></i> Abrir no WhatsApp</a>
                    @endif
                </div>
                <pre x-ref="texto" class="rounded-lg bg-gray-50 dark:bg-gray-900/40 border border-gray-200 dark:border-gray-700 p-3 text-sm whitespace-pre-wrap break-words font-sans" data-test="texto-cobranca">{{ $this->collectionText }}</pre>
                <h3 class="font-display font-bold uppercase tracking-wide text-gray-500">Movimentação</h3>
                @if ($this->statement === [])
                    <p class="text-sm text-gray-500">Sem movimentação.</p>
                @else
                    <div class="divide-y divide-gray-100 dark:divide-gray-700 text-sm" data-test="extrato">
                        @foreach ($this->statement as $i => $row)
                            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 py-2" wire:key="e-{{ $i }}">
                                <span class="w-20 shrink-0 text-gray-500">{{ \Carbon\CarbonImmutable::parse($row['date'])->format('d/m/Y') }}</span>
                                <span class="min-w-0 flex-1 break-words">{{ $row['description'] }}</span>
                                <span @class(['whitespace-nowrap font-semibold', 'text-gray-500' => $row['credit'] > 0])>{{ $row['debit'] > 0 ? $money($row['debit']) : '− '.$money($row['credit']) }}</span>
                                <span class="w-full sm:w-28 text-right text-xs text-gray-500 whitespace-nowrap">saldo {{ $money($row['balance']) }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
                <div class="flex flex-wrap justify-end gap-2">
                    <a href="{{ route('finance.receivables', ['cliente' => $c->id, 'mes' => 'todos', 'situacao' => 'todos']) }}" wire:navigate class="{{ $btnSecondary }}"><i class="fas fa-list"></i> Ver no A receber</a>
                    <button type="button" wire:click="close" class="{{ $btnSecondary }}">Fechar</button>
                </div>
            </x-modal>
        @elseif ($dialog === 'saldo' && $gestor)
            <x-modal close="close" form="saveOpening" size="md" data-test="janela-saldo-anterior">
                <h2 class="font-display font-bold text-xl uppercase">Saldo anterior · {{ $c->name }}</h2>
                <p class="text-sm text-gray-600 dark:text-gray-300">Quanto esse cliente já devia antes de começar a usar o sistema. Entra no A receber dele (é quitado primeiro nos recebimentos) e não conta como faturamento. Lançar de novo corrige o valor.</p>
                @error('dialog') <p class="text-sm text-red-600" data-test="erro-janela">{{ $message }}</p> @enderror
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="{{ $labelClass }}" for="s-valor">Valor devido (R$)</label>
                        <input id="s-valor" type="text" inputmode="decimal" wire:model="amount" placeholder="0,00" class="{{ $inputClass }} font-semibold" data-test="valor-saldo">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="s-data">Desde</label>
                        <input id="s-data" type="date" wire:model="date" class="{{ $inputClass }}">
                    </div>
                </div>
                <div class="flex flex-wrap justify-end gap-2">
                    <button type="button" wire:click="close" class="{{ $btnSecondary }}">Fechar</button>
                    <button type="submit" class="{{ $btnPrimary }}" data-test="salvar-saldo"><i class="fas fa-check"></i> Salvar</button>
                </div>
            </x-modal>
        @elseif ($dialog === 'liberar' && $gestor)
            <x-modal close="close" form="release" size="md" data-test="janela-liberar">
                <h2 class="font-display font-bold text-xl uppercase">Liberar a prazo · {{ $c->name }}</h2>
                <p class="text-sm text-gray-600 dark:text-gray-300">Até a data escolhida, a entrega e o A receber aceitam lançar a prazo para este cliente mesmo travado, sem limite ou passando do limite. O motivo fica na trilha de auditoria.</p>
                @if ($c->releaseActive())
                    <p class="rounded-lg bg-brand-50 dark:bg-brand-900/30 p-3 text-sm">Liberado até {{ $c->credit_release_until->format('d/m/Y') }}: {{ $c->credit_release_reason }}</p>
                @endif
                @error('dialog') <p class="text-sm text-red-600" data-test="erro-janela">{{ $message }}</p> @enderror
                <div class="grid gap-3">
                    <div>
                        <label class="{{ $labelClass }}" for="l-ate">Liberado até</label>
                        <input id="l-ate" type="date" wire:model="date" class="{{ $inputClass }}" data-test="liberado-ate">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="l-motivo">Motivo</label>
                        <textarea id="l-motivo" wire:model="reason" rows="2" maxlength="200" placeholder="Ex.: combinou pagar o vencido na sexta" class="{{ $inputClass }}" data-test="motivo-liberacao"></textarea>
                    </div>
                </div>
                <div class="flex flex-wrap justify-end gap-2">
                    @if ($c->releaseActive())
                        <button type="button" wire:click="revoke" class="{{ $btnSecondary }} sm:mr-auto">Retirar a liberação</button>
                    @endif
                    <button type="button" wire:click="close" class="{{ $btnSecondary }}">Fechar</button>
                    <button type="submit" class="{{ $btnPrimary }}" data-test="salvar-liberacao"><i class="fas fa-unlock"></i> Liberar</button>
                </div>
            </x-modal>
        @elseif ($dialog === 'credito' && $gestor)
            <x-modal close="close" form="saveTerms" size="md" data-test="janela-credito">
                <h2 class="font-display font-bold text-xl uppercase">Crédito · {{ $c->name }}</h2>
                @error('dialog') <p class="text-sm text-red-600" data-test="erro-janela">{{ $message }}</p> @enderror
                <div class="grid gap-3 grid-cols-2">
                    <div class="col-span-2">
                        <label class="{{ $labelClass }}" for="t-tipo">Tipo</label>
                        <select id="t-tipo" wire:model="kind" class="{{ $inputClass }}" data-test="tipo-cliente">
                            @foreach (CreditKind::options() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="t-prazo">Prazo (dias)</label>
                        <input id="t-prazo" type="number" min="0" max="180" inputmode="numeric" wire:model="days" class="{{ $inputClass }}" data-test="prazo-dias">
                        <p class="mt-1 text-xs text-gray-500">0 = paga na retirada.</p>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="t-limite">Limite (R$)</label>
                        <input id="t-limite" type="text" inputmode="decimal" wire:model="limit" placeholder="0,00" class="{{ $inputClass }}" data-test="limite-credito">
                        <p class="mt-1 text-xs text-gray-500">Máximo em aberto; vazio = sem crédito.</p>
                    </div>
                </div>
                <div class="flex flex-wrap justify-end gap-2">
                    <button type="button" wire:click="close" class="{{ $btnSecondary }}">Fechar</button>
                    <button type="submit" class="{{ $btnPrimary }}" data-test="salvar-credito"><i class="fas fa-check"></i> Salvar</button>
                </div>
            </x-modal>
        @endif
    @endif
</div>
