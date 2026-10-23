<?php

use App\Livewire\Concerns\RunsDomainActions;
use App\Services\Finance\Month;
use App\Services\Import\SpreadsheetImport;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/*
 * Importar a planilha CONTROLE SERVIÇOS (só o gestor): o CSV da aba SERVIÇOS vira lançamentos históricos do
 * "A receber". Prévia antes de gravar (a importação inteira desfeita no fim); data de corte para começar zerado;
 * importar de novo não duplica.
 */
new #[Title('Importar a planilha')] class extends Component
{
    use RunsDomainActions;
    use WithFileUploads;

    public $file;

    /** Tudo até esta data entra como já recebido. */
    public string $cutoff = '';

    /** @var array<string, mixed>|null */
    public ?array $preview = null;

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gestor'), 403);
    }

    public function mount(): void
    {
        $this->cutoff = now()->startOfMonth()->toDateString();
    }

    public function updatedFile(): void
    {
        $this->runPreview();
    }

    public function updatedCutoff(): void
    {
        if ($this->file) {
            $this->runPreview();
        }
    }

    public function apply(SpreadsheetImport $import): void
    {
        if (! $this->file || ! $this->preview) {
            return;
        }

        $result = $this->attempt(fn () => $import->apply($this->contents(), $this->cutoff, auth()->user()), 'file');

        if ($result) {
            $this->reset('file', 'preview');
            $this->result = $result;
            $this->dispatch('toast', message: 'Planilha importada: '.$result['new'].' serviço(s) no A receber.', type: 'success');
        }
    }

    private function runPreview(): void
    {
        $this->result = null;
        $this->preview = null;
        $this->validate(['file' => 'required|file|max:5120'], ['file.max' => 'O arquivo passa de 5 MB.', 'file.required' => 'Escolha o arquivo CSV.']);
        $this->preview = $this->attempt(fn () => app(SpreadsheetImport::class)->preview($this->contents(), $this->cutoff, auth()->user()), 'file');
    }

    private function contents(): string
    {
        return (string) file_get_contents($this->file->getRealPath());
    }
};
?>

<div class="max-w-3xl space-y-4">
    <a href="{{ route('settings') }}" wire:navigate class="text-sm text-brand-600 hover:underline"><i class="fas fa-arrow-left"></i> Ajustes</a>
    <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide">Importar a planilha</h1>

    <div class="{{ $cardClass }} p-4 space-y-3 text-sm text-gray-700 dark:text-gray-200">
        <p>Traz os serviços da planilha CONTROLE SERVIÇOS para o <a href="{{ route('finance.receivables') }}" wire:navigate class="text-brand-600 hover:underline">A receber</a>, como histórico: o faturamento dos meses passados aparece na Meta do mês e nos Relatórios.</p>
        <ol class="list-decimal pl-5 space-y-1">
            <li>Na planilha, abra a aba SERVIÇOS e use Arquivo &gt; Fazer download &gt; Valores separados por vírgula (.csv).</li>
            <li>Escolha a data de corte e o arquivo; confira a prévia: os totais por mês devem bater com a planilha. Nada é gravado até tocar em <strong>Importar</strong>.</li>
            <li>Depois, lance em <a href="{{ route('finance.credit') }}" wire:navigate class="text-brand-600 hover:underline">Crédito</a> o saldo anterior dos clientes que ainda devem.</li>
        </ol>
        <p class="text-gray-500">Pode importar de novo um arquivo mais novo: o serviço que já veio não se repete. Orçamentos da planilha não entram (não são serviço feito). Linhas com data digitada errada recebem a data das vizinhas e ficam anotadas.</p>
    </div>

    <div class="{{ $cardClass }} p-4 space-y-3">
        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <label class="{{ $labelClass }}" for="cutoff">Considerar recebidos os serviços até</label>
                <input id="cutoff" type="date" wire:model.live="cutoff" class="{{ $inputClass }}" data-test="data-corte">
                <p class="mt-1 text-xs text-gray-500">Tudo até essa data entra como já recebido (não entra no caixa), para o controle começar zerado.</p>
            </div>
            <div>
                <label class="{{ $labelClass }}" for="file">Arquivo CSV</label>
                <input id="file" type="file" wire:model="file" accept=".csv,text/csv" class="block w-full text-sm min-h-11" data-test="arquivo">
                <div wire:loading wire:target="file" class="text-sm text-gray-500">Lendo o arquivo…</div>
            </div>
        </div>
        @error('file') <p class="text-sm text-red-600" data-test="erro-arquivo">{{ $message }}</p> @enderror

        @foreach (array_filter([['Prévia', $preview], ['Importado', $result]], fn ($pair) => $pair[1] !== null) as [$title, $report])
            <div class="space-y-3" data-test="{{ $title === 'Prévia' ? 'previa' : 'resultado' }}">
                <h2 class="font-semibold">{{ $title }}</h2>
                <ul class="text-sm space-y-1">
                    <li>{{ $report['lines'] }} linha(s) lidas · {{ $report['budgets'] }} orçamento(s) ficam fora · {{ $report['estimated'] }} com a data corrigida pelas vizinhas.</li>
                    <li><b>{{ $report['new'] }}</b> serviço(s) {{ $title === 'Prévia' ? 'a trazer' : 'trazido(s)' }} ({{ $money($report['new_cents']) }}) · {{ $report['existing'] }} já estavam no sistema.</li>
                    <li>{{ $report['settled_by_cutoff'] }} quitado(s) pela data de corte · {{ $report['received_in_sheet'] }} marcado(s) RECEBIDO na planilha · ficam em aberto {{ $money($report['open_cents']) }}.</li>
                    @if ($report['customers_new'] > 0)
                        <li>{{ $report['customers_new'] }} cliente(s) novo(s) no cadastro (sem WhatsApp: complete em Clientes e veículos).</li>
                    @endif
                </ul>
                @if ($report['months'] !== [])
                    <div class="rounded-lg border border-gray-200 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-700 text-sm">
                        @foreach ($report['months'] as $month => $cents)
                            <div class="flex items-baseline gap-2 px-3 py-2" wire:key="{{ $title }}-{{ $month }}"><span>{{ Month::label($month) }}</span><span class="ml-auto font-semibold">{{ $money($cents) }}</span></div>
                        @endforeach
                    </div>
                @endif
                @if ($report['warnings'] !== [])
                    <ul class="list-disc pl-5 text-sm text-red-700 dark:text-red-300">
                        @foreach (array_slice($report['warnings'], 0, 15) as $warning)<li>{{ $warning }}</li>@endforeach
                    </ul>
                @endif
                <p class="text-xs text-gray-500 break-all">SHA-256 do arquivo: {{ $report['sha256'] }}</p>
            </div>
        @endforeach

        @if ($preview)
            <div class="flex justify-end"><button type="button" wire:click="apply" wire:loading.attr="disabled" class="{{ $btnPrimary }}" data-test="importar"><i class="fas fa-file-import"></i> Importar</button></div>
        @endif
    </div>
</div>
