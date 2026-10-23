<?php

use App\Livewire\Concerns\FinanceAccess;
use App\Livewire\Concerns\RunsDomainActions;
use App\Models\GroupCompany;
use App\Services\Purchases\Dda;
use App\Support\Document;
use App\Support\Purchases\BoletoCode;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/*
 * DDA do banco (doc 09 §13, BETA), só do gestor: importar o arquivo (CNAB 240, CSV ou o texto copiado do internet
 * banking) e comparar com os boletos cadastrados. É a conferência independente de quem lança.
 */
new #[Title('DDA do banco')] class extends Component
{
    use FinanceAccess;
    use RunsDomainActions;
    use WithFileUploads;

    public $file = null;

    public string $content = '';

    public string $companyId = '';

    public array $result = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->isGestor(), 403);
    }

    #[Computed]
    public function cross(): array
    {
        return app(Dda::class)->cross();
    }

    #[Computed]
    public function companies()
    {
        return GroupCompany::query()->active()->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function missing()
    {
        return app(Dda::class)->companiesWithoutDda();
    }

    public function import(Dda $dda): void
    {
        $this->resetErrorBag();
        $this->validate(['file' => 'nullable|file|max:5120'], ['file.max' => 'O arquivo passa de 5 MB: não parece um DDA.']);
        $content = $this->file ? (string) file_get_contents($this->file->getRealPath()) : $this->content;
        $name = $this->file?->getClientOriginalName() ?: ($this->content !== '' ? 'texto colado' : null);

        if (trim($content) === '') {
            $this->addError('dda', 'Escolha o arquivo do DDA ou cole o texto.');

            return;
        }

        $result = $this->attempt(fn () => $dda->import($content, $name, ctype_digit($this->companyId) ? (int) $this->companyId : null, auth()->user()), 'dda');

        if ($result) {
            $this->result = $result;
            $this->reset('file', 'content');
            unset($this->cross, $this->missing);
            $this->dispatch('toast', message: $result['count'].' boleto(s) lido(s) do DDA, '.$result['new'].' novo(s).', type: 'success');
        }
    }
};
?>

@php
    $c = $this->cross;
    $names = [0 => 'Oficina'] + $this->companies->pluck('name', 'id')->all();
    $scopeName = fn ($scope) => $names[(int) $scope] ?? 'Empresa '.$scope;
@endphp
<div class="space-y-4">
    <x-finance-nav />
    <x-purchases-nav />

    <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide flex items-center gap-2">
        <span class="w-1 h-6 rounded bg-brand-600"></span>DDA do banco
        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold bg-gray-100 dark:bg-gray-700 normal-case tracking-normal">beta</span>
    </h1>
    <p class="text-sm text-gray-600 dark:text-gray-300">
        O DDA mostra os boletos emitidos contra o CNPJ da oficina (e das empresas do grupo). Importe o arquivo do banco toda semana: boleto no DDA que ninguém cadastrou,
        ou cadastrado diferente do que o banco mostra, vira ocorrência grave. Só o arquivo CNAB 240 recente, de fornecedor conferido, dispensa conferir no app do banco.
        É um recurso em teste: na dúvida, confira no app do banco.
    </p>

    @if ($this->missing->isNotEmpty())
        <p class="rounded-lg border border-amber-200 bg-amber-50 dark:bg-amber-900/30 dark:border-amber-800 p-3 text-sm text-amber-900 dark:text-amber-100" data-test="sem-dda">
            Ainda sem DDA importado: {{ $this->missing->implode(', ') }}.
        </p>
    @endif

    <form wire:submit="import" class="{{ $cardClass }} p-3 space-y-2" data-test="form-dda">
        <h2 class="font-display font-bold uppercase">Importar</h2>
        <div class="grid gap-2 sm:grid-cols-2">
            <div>
                <label class="{{ $labelClass }}" for="dda-file">Arquivo do DDA (CNAB 240 ou CSV)</label>
                <input id="dda-file" type="file" wire:model="file" accept=".txt,.ret,.rem,.csv,.cnab,text/plain,text/csv" class="block w-full text-sm" data-test="arquivo-dda">
                <div wire:loading wire:target="file" class="text-sm text-gray-500">Enviando…</div>
            </div>
            <div>
                <label class="{{ $labelClass }}" for="dda-empresa">De qual CNPJ (se o arquivo não disser)</label>
                <select id="dda-empresa" wire:model="companyId" class="{{ $inputClass }}">
                    <option value="">Oficina</option>
                    @foreach ($this->companies as $company)
                        <option value="{{ $company->id }}">{{ $company->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <textarea wire:model="content" rows="3" class="{{ $inputClass }} font-mono text-xs" placeholder="Ou cole aqui a lista de boletos do DDA copiada do internet banking" data-test="texto-dda"></textarea>
        @error('dda') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
        @error('file') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
        <div class="flex justify-end">
            <button type="submit" class="{{ $btnPrimary }}" wire:loading.attr="disabled" data-test="importar-dda"><i class="fas fa-file-import"></i> Importar</button>
        </div>
        @if ($result)
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-2 text-sm space-y-1" data-test="resultado-dda">
                <p>Formato {{ $result['format'] }}: {{ $result['count'] }} boleto(s), {{ $result['new'] }} novo(s). Batem: {{ $result['ok'] }} · diferentes: {{ $result['diverge'] }} · sem cadastro: {{ $result['without_registration'] }} · fora do DDA: {{ $result['outside'] }}.</p>
                @foreach ($result['warnings'] as $warning) <p class="text-amber-800 dark:text-amber-200">{{ $warning }}</p> @endforeach
            </div>
        @endif
    </form>

    @if ($c['imports'])
        <section class="space-y-1">
            <h2 class="font-display font-bold uppercase tracking-wide text-sm text-gray-500 dark:text-gray-400">Último arquivo de cada CNPJ</h2>
            @foreach ($c['imports'] as $import)
                <p class="text-sm" wire:key="imp-{{ $import->id }}">{{ $scopeName($import->scope) }}: {{ $import->imported_on->format('d/m/Y') }} ({{ $import->days === 0 ? 'hoje' : 'há '.$import->days.' dia(s)' }}) · {{ $import->format }} · {{ $import->titles_count }} boleto(s)@if ($import->days > Dda::VALID_DAYS) · <span class="text-red-700 dark:text-red-300">antigo: importe de novo</span> @endif</p>
            @endforeach
        </section>
    @endif

    @if ($c['without_registration'])
        <section class="space-y-2" data-test="dda-sem-cadastro">
            <h2 class="font-display font-bold uppercase tracking-wide text-sm text-red-700 dark:text-red-400">No DDA e ninguém cadastrou ({{ count($c['without_registration']) }})</h2>
            <div class="{{ $cardClass }} divide-y divide-gray-100 dark:divide-gray-700 overflow-hidden">
                @foreach ($c['without_registration'] as $row)
                    @php($t = $row['title'])
                    <div class="p-3 border-l-4 border-l-stripe space-y-1" wire:key="sc-{{ $t->id }}">
                        <p class="font-semibold flex flex-wrap gap-x-2"><span class="min-w-0 flex-1 break-words">{{ $t->payee_name ?: 'Beneficiário?' }}{{ $t->payee_cnpj ? ' · '.Document::format($t->payee_cnpj) : '' }}</span><span>{{ $money($t->amount_cents) }}</span></p>
                        <p class="text-sm text-gray-600 dark:text-gray-300">Vence {{ $t->due_date?->format('d/m/Y') }} · {{ $scopeName($row['scope']) }}{{ $t->document_number ? ' · doc. '.$t->document_number : '' }}</p>
                        @if ($t->barcode) <p class="font-mono text-xs break-all">{{ BoletoCode::formatLine(BoletoCode::barcodeToLine($t->barcode)) }}</p> @endif
                    </div>
                @endforeach
            </div>
            <p class="text-sm text-gray-600 dark:text-gray-300">Se o boleto é de verdade, cadastre em Boletos (com a nota). Se ninguém comprou nada, não pague e investigue.</p>
        </section>
    @endif

    @if ($c['diverge'])
        <section class="space-y-2" data-test="dda-diferentes">
            <h2 class="font-display font-bold uppercase tracking-wide text-sm text-red-700 dark:text-red-400">Cadastrado diferente do banco ({{ count($c['diverge']) }})</h2>
            <div class="{{ $cardClass }} divide-y divide-gray-100 dark:divide-gray-700 overflow-hidden">
                @foreach ($c['diverge'] as $row)
                    <a href="{{ route('finance.purchases.slips', ['boleto' => $row['slip']->id]) }}" wire:navigate class="block p-3 border-l-4 border-l-stripe hover:bg-gray-50 dark:hover:bg-gray-700/40" wire:key="dv-{{ $row['slip']->id }}">
                        <p class="font-semibold break-words">{{ $row['slip']->supplier?->name }} · {{ $money($row['slip']->amount_cents) }}</p>
                        <p class="text-sm text-gray-600 dark:text-gray-300 break-words">{{ implode('; ', $row['differences']) }}</p>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if ($c['outside'])
        <section class="space-y-2" data-test="dda-fora">
            <h2 class="font-display font-bold uppercase tracking-wide text-sm text-gray-500 dark:text-gray-400">Cadastrado e em aberto, mas o banco não mostra ({{ count($c['outside']) }})</h2>
            <div class="{{ $cardClass }} divide-y divide-gray-100 dark:divide-gray-700 overflow-hidden">
                @foreach ($c['outside'] as $row)
                    <a href="{{ route('finance.purchases.slips', ['boleto' => $row['slip']->id]) }}" wire:navigate class="block p-3 hover:bg-gray-50 dark:hover:bg-gray-700/40" wire:key="fo-{{ $row['slip']->id }}">
                        <p class="font-semibold break-words">{{ $row['slip']->supplier?->name }} · {{ $money($row['slip']->amount_cents) }} · vence {{ $row['slip']->due_date->format('d/m/Y') }}</p>
                        <p class="text-sm text-gray-600 dark:text-gray-300">Pode ser boleto falso (o banco não conhece) ou de outro CNPJ. Confira no app do banco.</p>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if ($c['ok'])
        <p class="text-sm text-gray-600 dark:text-gray-300" data-test="dda-batem">
            <i class="fas fa-check mr-1"></i>{{ count($c['ok']) }} boleto(s) cadastrado(s) batem com o DDA; {{ collect($c['ok'])->where('confirmed', true)->count() }} confirmado(s) (dispensam conferir no app do banco).
        </p>
    @endif
</div>
