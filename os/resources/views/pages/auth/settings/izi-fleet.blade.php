<?php

use App\Livewire\Concerns\RunsDomainActions;
use App\Models\Vehicle;
use App\Services\Registry\IziFleetImport;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Frota Izi')] class extends Component
{
    use RunsDomainActions;
    use WithFileUploads;

    public $file;

    /** @var array{rows: list<array<string, mixed>>, skipped: list<string>}|null */
    public ?array $preview = null;

    #[Computed]
    public function fleetCount(): int
    {
        return Vehicle::query()->where('is_izi_fleet', true)->count();
    }

    public function updatedFile(IziFleetImport $import): void
    {
        $this->validate(['file' => 'required|file|max:2048'], ['file.max' => 'O arquivo passa de 2 MB.']);

        $this->preview = $this->attempt(fn () => $import->preview((string) file_get_contents($this->file->getRealPath())), 'file');
    }

    public function apply(IziFleetImport $import): void
    {
        if (! $this->file) {
            return;
        }

        $count = $this->attempt(fn () => $import->apply((string) file_get_contents($this->file->getRealPath())), 'file');

        if ($count) {
            $this->reset('file', 'preview');
            unset($this->fleetCount);
            $this->dispatch('toast', message: $count.' carro(s) da frota Izi atualizados.', type: 'success');
        }
    }
};
?>

<div class="max-w-3xl space-y-4">
    <a href="{{ route('settings') }}" wire:navigate class="text-sm text-brand-600 hover:underline"><i class="fas fa-arrow-left"></i> Ajustes</a>
    <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide">Frota Izi</h1>

    <div class="{{ $cardClass }} p-4 space-y-3 text-sm text-gray-700 dark:text-gray-200">
        <p>Traz os carros da Izi Car do Gestão de Frota. Depois disso, a Giovana digita só a placa na Nova OS: o carro já vem com modelo, ano, chassi e investidor, e entra no cliente Izi Car.</p>
        <ol class="list-decimal pl-5 space-y-1">
            <li>No Gestão de Frota: <strong>Veículos → Exportar</strong> (baixa um arquivo .csv).</li>
            <li>Escolha o arquivo aqui. Confira a prévia e toque em <strong>Importar</strong>.</li>
        </ol>
        <p class="text-gray-500">Pode importar de novo quando entrar carro novo: o que já existe é atualizado, nada é duplicado. Carro com "Saída da Izi" fica de fora. O WhatsApp do investidor não vem do Gestão: complete em Clientes e veículos.</p>
        <p>Hoje há <strong>{{ $this->fleetCount }}</strong> carro(s) da frota cadastrados.</p>
    </div>

    <div class="{{ $cardClass }} p-4 space-y-3">
        <label class="{{ $labelClass }}" for="file">Arquivo CSV do Gestão</label>
        <input id="file" type="file" wire:model="file" accept=".csv,text/csv" class="block w-full text-sm" data-test="arquivo">
        <div wire:loading wire:target="file" class="text-sm text-gray-500">Lendo o arquivo…</div>
        @error('file') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

        @if ($preview)
            <p class="text-sm"><strong>{{ count($preview['rows']) }}</strong> carro(s): {{ collect($preview['rows'])->where('action', 'novo')->count() }} novo(s), {{ collect($preview['rows'])->where('action', 'atualizar')->count() }} para atualizar.</p>
            <div class="max-h-80 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-700 text-sm">
                @foreach ($preview['rows'] as $row)
                    <div class="p-2 flex flex-wrap items-center gap-2" wire:key="p-{{ $row['plate'] }}">
                        <x-plate :plate="$row['plate']" />
                        <span class="flex-1 min-w-0 truncate">{{ $row['brand_model'] }} {{ $row['year'] ? '· '.$row['year'] : '' }} {{ $row['investor_name'] ? '· '.$row['investor_name'] : '· frota própria' }}</span>
                        <span class="text-xs text-gray-500">{{ $row['action'] }}</span>
                    </div>
                @endforeach
            </div>
            @if ($preview['skipped'] !== [])
                <details class="text-sm"><summary class="cursor-pointer text-gray-600">{{ count($preview['skipped']) }} linha(s) ficam de fora</summary>
                    <ul class="mt-1 list-disc pl-5 text-gray-500">@foreach ($preview['skipped'] as $line)<li>{{ $line }}</li>@endforeach</ul>
                </details>
            @endif
            <div class="flex justify-end"><button type="button" wire:click="apply" class="{{ $btnPrimary }}" data-test="importar"><i class="fas fa-file-import"></i> Importar</button></div>
        @endif
    </div>
</div>
