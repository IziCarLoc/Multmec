<?php

use App\Livewire\Concerns\RunsDomainActions;
use App\Services\Import\ArtifactImport;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Importar dos artefatos')] class extends Component
{
    use RunsDomainActions;
    use WithFileUploads;

    public $file;

    /** @var array{sha256: string, sections: array<string, array<string, mixed>>}|null */
    public ?array $preview = null;

    /** @var array{sha256: string, sections: array<string, array<string, mixed>>}|null */
    public ?array $result = null;

    public function updatedFile(ArtifactImport $import): void
    {
        $this->result = null;
        $this->validate(['file' => 'required|file|max:10240'], ['file.max' => 'O arquivo passa de 10 MB.']);

        $this->preview = $this->attempt(fn () => $import->preview($this->contents(), auth()->user()), 'file');
    }

    public function apply(ArtifactImport $import): void
    {
        if (! $this->file || ! $this->preview) {
            return;
        }

        $result = $this->attempt(fn () => $import->apply($this->contents(), auth()->user()), 'file');

        if ($result) {
            $this->reset('file', 'preview');
            $this->result = $result;
            $this->dispatch('toast', message: 'Dados dos artefatos importados.', type: 'success');
        }
    }

    private function contents(): string
    {
        return (string) file_get_contents($this->file->getRealPath());
    }
};
?>

<div class="max-w-3xl space-y-4">
    <a href="{{ route('settings') }}" wire:navigate class="text-sm text-brand-600 hover:underline"><i class="fas fa-arrow-left"></i> Ajustes</a>
    <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide">Importar dos artefatos</h1>

    <div class="{{ $cardClass }} p-4 space-y-3 text-sm text-gray-700 dark:text-gray-200">
        <p>Traz para o sistema o que está nos artefatos do claude.ai: fornecedores, boletos a pagar, A receber (com os recebimentos), pagamentos do Douglas e prejuízos.</p>
        <ol class="list-decimal pl-5 space-y-1">
            <li>Escolha o arquivo da exportação (.json).</li>
            <li>Confira a prévia: nada é gravado até tocar em <strong>Importar</strong>.</li>
        </ol>
        <p class="text-gray-500">Pode importar de novo um arquivo mais novo: o que já veio não se repete, e boleto pago, recebimento ou prestador pago depois no artefato ganham a baixa aqui. Clientes entram pelo nome (sem WhatsApp, que o artefato não guardava): complete em Clientes e veículos.</p>
    </div>

    <div class="{{ $cardClass }} p-4 space-y-3">
        <label class="{{ $labelClass }}" for="file">Arquivo da exportação</label>
        <input id="file" type="file" wire:model="file" accept=".json,application/json" class="block w-full text-sm" data-test="arquivo">
        <div wire:loading wire:target="file" class="text-sm text-gray-500">Lendo o arquivo…</div>
        @error('file') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

        @foreach (array_filter([['Prévia', $preview], ['Importado', $result]], fn ($pair) => $pair[1] !== null) as [$title, $report])
            <div class="space-y-2" data-test="{{ $title === 'Prévia' ? 'previa' : 'resultado' }}">
                <h2 class="font-semibold">{{ $title }}</h2>
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-700 text-sm">
                    @foreach ($report['sections'] as $key => $section)
                        @continue($section['new'] + $section['existing'] + $section['updated'] === 0 && $section['problems'] === [])
                        <div class="p-3 space-y-1" wire:key="{{ $title }}-{{ $key }}">
                            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                <span class="font-medium flex-1 min-w-0">{{ $section['label'] }}</span>
                                @if ($section['new'] > 0)
                                    <span>{{ $section['new'] }} {{ $title === 'Prévia' ? 'a trazer' : 'trazido(s)' }} @if ($section['cents'] > 0) · {{ \App\Support\Money::brl($section['cents']) }} @endif</span>
                                @endif
                                @if ($section['updated'] > 0)
                                    <span class="text-brand-700 dark:text-brand-300">{{ $section['updated'] }} {{ $key === 'fornecedores' ? 'completado(s)' : 'com baixa nova' }}</span>
                                @endif
                                @if ($section['existing'] > 0)
                                    <span class="text-gray-500">{{ $section['existing'] }} já no sistema</span>
                                @endif
                            </div>
                            @if ($section['problems'] !== [])
                                <ul class="list-disc pl-5 text-red-700 dark:text-red-300">
                                    @foreach ($section['problems'] as $problem)<li>{{ $problem }}</li>@endforeach
                                </ul>
                            @endif
                        </div>
                    @endforeach
                </div>
                <p class="text-xs text-gray-500 break-all">SHA-256 do arquivo: {{ $report['sha256'] }}</p>
            </div>
        @endforeach

        @if ($preview)
            <div class="flex justify-end"><button type="button" wire:click="apply" wire:loading.attr="disabled" class="{{ $btnPrimary }}" data-test="importar"><i class="fas fa-file-import"></i> Importar</button></div>
        @endif
    </div>
</div>
