<?php

use App\Livewire\Concerns\FinanceAccess;
use App\Livewire\Concerns\RunsDomainActions;
use App\Models\Receivable;
use App\Services\Finance\Delinquency;
use App\Services\Finance\Receivables;
use App\Support\Phone;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Inadimplentes: cada cliente com algum lançamento vencido no "A receber", com o valor integral em aberto dele
 * (todas as parcelas e meses). Tocar no cliente mostra os vencidos, com "Lançar como prejuízo".
 */
new #[Title('Inadimplentes')] class extends Component
{
    use FinanceAccess;
    use RunsDomainActions;

    #[Url(as: 'busca', except: '')]
    public string $search = '';

    /** Cliente aberto na lista (chave do grupo: id do cliente, "0" sem cliente). */
    public string $expanded = '';

    #[Computed]
    public function debtors()
    {
        return app(Delinquency::class)->customers();
    }

    #[Computed]
    public function rows()
    {
        $term = mb_strtolower(trim($this->search));

        return $term === '' ? $this->debtors : $this->debtors->filter(fn (array $row) => str_contains(mb_strtolower($row['name']), $term))->values();
    }

    public function toggle(string $key): void
    {
        $this->expanded = $this->expanded === $key ? '' : $key;
    }

    public function writeOff(int $id, Receivables $service): void
    {
        if ($this->attempt(fn () => $service->writeOff(Receivable::findOrFail($id), auth()->user()))) {
            unset($this->debtors, $this->rows);
            $this->dispatch('toast', message: 'Baixa lançada como prejuízo.', type: 'success');
        }
    }
};
?>

@php
    $pill = 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap';
    $all = $this->debtors;
@endphp
<div class="space-y-4">
    <x-finance-nav />

    <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide flex items-center gap-2">
        <span class="w-1 h-6 rounded bg-brand-600"></span>Inadimplentes
    </h1>

    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
        <x-finance-tile label="Total inadimplente" :value="$money($all->sum('open_cents'))" note="em aberto de quem tem vencido" :tone="$all->isNotEmpty() ? 'bad' : 'plain'" data-test="total-inadimplente" />
        <x-finance-tile label="Só o vencido" :value="$money($all->sum('overdue_cents'))" :note="$all->sum('overdue_count').' lançamento(s) vencido(s)'" />
        <x-finance-tile label="Clientes inadimplentes" :value="(string) $all->count()" note="com algum vencimento em atraso" />
    </div>

    <div class="relative">
        <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
        <input type="search" wire:model.live.debounce.400ms="search" placeholder="Buscar cliente" class="{{ $inputClass }} pl-9" data-test="busca">
    </div>

    <div class="space-y-2">
        @forelse ($this->rows as $row)
            @php($open = $expanded === $row['key'])
            <div class="{{ $cardClass }} border-l-4 border-l-stripe overflow-hidden" wire:key="c-{{ $row['key'] }}">
                <button type="button" wire:click="toggle('{{ $row['key'] }}')" class="w-full text-left p-3 flex flex-wrap items-start gap-x-3 gap-y-1 hover:bg-gray-50 dark:hover:bg-gray-700/40" aria-expanded="{{ $open ? 'true' : 'false' }}" data-test="inadimplente-{{ $row['key'] }}">
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold flex flex-wrap items-center gap-1">
                            <span class="break-words">{{ $row['name'] }}</span>
                            <span class="{{ $pill }} bg-red-50 text-red-700 dark:bg-red-900/40 dark:text-red-300">{{ $row['days_late'] }} dia(s) em atraso</span>
                        </p>
                        <p class="text-sm text-gray-600 dark:text-gray-300">{{ $row['customer']?->whatsappFormatted() ?: 'sem telefone' }} · {{ $row['overdue_count'] }} vencido(s) ({{ $money($row['overdue_cents']) }})</p>
                    </div>
                    <div class="text-right">
                        <p class="font-display font-bold text-lg">{{ $money($row['open_cents']) }}</p>
                        <p class="text-xs text-gray-500">em aberto <i class="fas {{ $open ? 'fa-chevron-up' : 'fa-chevron-down' }} ml-1"></i></p>
                    </div>
                </button>
                @if ($open)
                    <div class="border-t border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/30 p-3 space-y-2" data-test="vencidos-{{ $row['key'] }}">
                        <div class="flex flex-wrap gap-2">
                            @if ($link = Phone::whatsappLink($row['customer']?->whatsapp))
                                <a href="{{ $link }}" target="_blank" rel="noopener" class="{{ $btnSecondary }}"><i class="fab fa-whatsapp"></i> WhatsApp</a>
                            @endif
                            @if ($row['customer'])
                                <a href="{{ route('finance.receivables', ['cliente' => $row['customer']->id, 'mes' => 'todos']) }}" wire:navigate class="{{ $btnSecondary }}"><i class="fas fa-list"></i> Todos os lançamentos</a>
                            @endif
                        </div>
                        @foreach ($row['overdue'] as $receivable)
                            <div class="rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-3 flex flex-wrap items-center gap-2" wire:key="v-{{ $receivable->id }}">
                                <a href="{{ route('finance.receivables', ['abrir' => $receivable->id]) }}" wire:navigate class="min-w-0 flex-1 hover:underline">
                                    <p class="font-semibold">{{ $receivable->reference() }} @if ($receivable->paid_cents > 0) <span class="{{ $pill }} bg-gray-100 dark:bg-gray-700">parcial</span> @endif</p>
                                    <p class="text-sm text-gray-500">{{ trim($receivable->plate.' '.$receivable->vehicle_model) ?: '—' }} · venc. {{ $receivable->due_date->format('d/m/Y') }}</p>
                                </a>
                                <span class="font-display font-bold text-lg">{{ $money($receivable->balanceCents()) }}</span>
                                <button type="button" wire:click="writeOff({{ $receivable->id }})" wire:confirm="Dar baixa no saldo em aberto desta OS e lançar como prejuízo (inadimplência)? O valor sai do &quot;a receber&quot; e passa a somar como prejuízo." class="{{ $btnSecondary }} text-red-700 w-full sm:w-auto" data-test="baixar-{{ $receivable->id }}">Lançar como prejuízo</button>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @empty
            <p class="{{ $cardClass }} p-6 text-center text-gray-600 dark:text-gray-300">{{ $all->isEmpty() ? 'Nenhum cliente inadimplente no momento.' : 'Nenhum cliente com esse filtro.' }}</p>
        @endforelse
    </div>

    <p class="text-sm text-gray-600 dark:text-gray-300">Mostra o valor integral em aberto de cada cliente com algum lançamento vencido em "A receber" (parcial também conta) — somando todas as parcelas e meses, sem separação por período. Recebimento pela matriz não entra. Toque em um cliente para ver os lançamentos vencidos dele.</p>
</div>
