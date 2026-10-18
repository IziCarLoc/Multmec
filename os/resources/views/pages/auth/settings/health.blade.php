<?php

use App\Services\Media\HostingProbe;
use App\Support\DeployCheck;
use App\Support\SystemHealth;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Saúde do sistema')] class extends Component
{
    use WithFileUploads;

    /** Arquivo do teste de envio (apagado logo depois de conferido). */
    public $testFile;

    public ?array $uploadResult = null;

    public ?string $stampedSample = null;

    public ?array $usage = null;

    public function verifyUpload(?string $clientSha, int $clientSize, int $clientMs): void
    {
        if (! $this->testFile) {
            $this->uploadResult = ['ok' => false, 'message' => 'O arquivo não chegou ao servidor.'];

            return;
        }

        $path = $this->testFile->getRealPath();
        $serverSha = hash_file('sha256', $path);
        $size = filesize($path);
        $this->testFile->delete();
        $this->testFile = null;

        $sameHash = $clientSha !== null && hash_equals($serverSha, strtolower($clientSha));

        $this->uploadResult = [
            'ok' => $sameHash && $size === $clientSize,
            'size' => SystemHealth::humanBytes($size),
            'seconds' => round($clientMs / 1000, 1),
            'server_sha' => $serverSha,
            'client_sha' => $clientSha,
            'message' => $clientSha === null
                ? 'O navegador não calculou o hash (sem https ou navegador antigo): o envio de fotos vai recusar.'
                : ($sameHash ? 'O arquivo chegou inteiro: o hash do celular é igual ao do servidor.' : 'O hash do servidor é diferente do celular: o arquivo chegou alterado.'),
        ];
    }

    public function stampTest(HostingProbe $probe): void
    {
        $this->stampedSample = $probe->stampedSample();
    }

    public function measureStorage(): void
    {
        $usage = SystemHealth::storageUsage();
        $this->usage = [
            'bytes' => SystemHealth::humanBytes($usage['bytes']),
            'files' => number_format($usage['files'], 0, ',', '.'),
            'free' => SystemHealth::humanBytes($usage['free']),
            'total' => SystemHealth::humanBytes($usage['total']),
        ];
    }

    public function with(): array
    {
        return [
            'checks' => SystemHealth::checks(),
            'outdated' => DeployCheck::outdatedPackages(),
        ];
    }
};
?>

<div class="space-y-5">
    <div class="flex flex-wrap items-center gap-3">
        <a href="{{ route('settings') }}" class="text-sm text-brand-600 hover:underline"><i class="fas fa-arrow-left"></i> Ajustes</a>
        <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide w-full">Saúde do sistema</h1>
    </div>

    <div class="{{ $cardClass }} overflow-hidden">
        <div class="p-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="font-display font-bold text-xl uppercase">Servidor</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400">Tudo verde = a hospedagem aguenta fotos, vídeos de até 20 MB e o PDF da OS.</p>
        </div>
        <ul class="divide-y divide-gray-100 dark:divide-gray-700">
            @foreach ($checks as $check)
                <li class="p-4 flex items-start gap-3" data-test="checagem">
                    <i class="fas {{ $check['ok'] ? 'fa-circle-check text-green-500' : 'fa-circle-exclamation text-red-500' }} text-lg mt-0.5"></i>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-sm">{{ $check['label'] }}</p>
                        <p class="text-sm text-gray-600 dark:text-gray-300 break-words">{{ $check['value'] }}</p>
                        @if ($check['hint'])
                            <p class="text-sm text-red-600 dark:text-red-400 mt-1">{{ $check['hint'] }}</p>
                        @endif
                    </div>
                </li>
            @endforeach
            <li class="p-4 flex items-start gap-3">
                <i class="fas {{ $outdated === [] ? 'fa-circle-check text-green-500' : 'fa-circle-exclamation text-red-500' }} text-lg mt-0.5"></i>
                <div>
                    <p class="font-semibold text-sm">Pacotes do servidor (vendor × composer.lock)</p>
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ $outdated === [] ? 'em dia' : count($outdated).' diferente(s): rode composer install --no-dev --optimize-autoloader' }}</p>
                </div>
            </li>
        </ul>
    </div>

    {{-- Teste de envio: mede o tempo e confere o hash do celular contra o do servidor. --}}
    <div class="{{ $cardClass }} p-4 space-y-3"
         x-data="{
            busy: false,
            progress: 0,
            async send(event) {
                const file = event.target.files[0];
                event.target.value = '';
                if (! file) return;
                if (file.size > 20 * 1024 * 1024) {
                    window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Escolha um arquivo de até 20 MB.', type: 'error' } }));
                    return;
                }
                this.busy = true; this.progress = 0;
                const sha = await window.sha256File(file);
                const started = Date.now();
                $wire.upload('testFile', file,
                    () => { $wire.verifyUpload(sha, file.size, Date.now() - started).finally(() => { this.busy = false }) },
                    () => { this.busy = false; window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'O envio falhou: o servidor recusou o arquivo (limite de tamanho do PHP?).', type: 'error' } })) },
                    (e) => { this.progress = e.detail.progress },
                );
            },
         }">
        <h2 class="font-display font-bold text-xl uppercase">Teste de envio (vídeo de até 20 MB)</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400">Escolha um vídeo ou uma foto do celular. O arquivo é conferido e apagado em seguida.</p>
        <label class="{{ $btnPrimary }} cursor-pointer" :class="busy && 'opacity-50 pointer-events-none'">
            <i class="fas fa-upload"></i> <span x-text="busy ? 'Enviando… ' + progress + '%' : 'Escolher arquivo'"></span>
            <input type="file" class="hidden" accept="video/*,image/*" @change="send($event)">
        </label>
        @if ($uploadResult)
            <div @class(['rounded-lg p-3 text-sm', 'bg-green-50 text-green-900 dark:bg-green-900/30 dark:text-green-100' => $uploadResult['ok'], 'bg-red-50 text-red-900 dark:bg-red-900/30 dark:text-red-100' => ! $uploadResult['ok']]) data-test="resultado-envio">
                <p class="font-semibold">{{ $uploadResult['message'] }}</p>
                @isset($uploadResult['size'])
                    <p>{{ $uploadResult['size'] }} em {{ str_replace('.', ',', (string) $uploadResult['seconds']) }} s.</p>
                    <p class="font-mono text-xs break-all mt-1">servidor: {{ $uploadResult['server_sha'] }}</p>
                @endisset
            </div>
        @endif
    </div>

    <div class="{{ $cardClass }} p-4 space-y-3">
        <h2 class="font-display font-bold text-xl uppercase">Teste do carimbo da foto</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400">Gera uma foto de exemplo com o carimbo (fonte, acentos e cores do v12) usando o servidor.</p>
        <button type="button" wire:click="stampTest" class="{{ $btnSecondary }}"><i class="fas fa-stamp"></i> Gerar foto carimbada</button>
        @if ($stampedSample)
            <img src="{{ $stampedSample }}" alt="Foto de teste com carimbo" class="w-full max-w-2xl rounded-lg border border-gray-200 dark:border-gray-700">
        @endif
    </div>

    <div class="{{ $cardClass }} p-4 space-y-3">
        <h2 class="font-display font-bold text-xl uppercase">Teste do PDF com 30 fotos</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400">Abre um PDF com 30 fotos, do tamanho de uma OS grande. O nome do arquivo mostra o tempo e a memória usados.</p>
        <a href="{{ route('settings.health.pdf') }}" target="_blank" rel="noopener" class="{{ $btnSecondary }}"><i class="fas fa-file-pdf"></i> Gerar PDF de teste</a>
    </div>

    <div class="{{ $cardClass }} p-4 space-y-3">
        <h2 class="font-display font-bold text-xl uppercase">Armazenamento</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400">Fotos, vídeos, assinaturas e PDFs guardados. A Hostinger limita o espaço e a quantidade de arquivos.</p>
        <button type="button" wire:click="measureStorage" class="{{ $btnSecondary }}"><i class="fas fa-hard-drive"></i> Medir agora</button>
        @if ($usage)
            <dl class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm" data-test="armazenamento">
                <div><dt class="text-gray-500">Ocupado pelo sistema</dt><dd class="font-semibold">{{ $usage['bytes'] }}</dd></div>
                <div><dt class="text-gray-500">Arquivos</dt><dd class="font-semibold">{{ $usage['files'] }}</dd></div>
                <div><dt class="text-gray-500">Livre no disco</dt><dd class="font-semibold">{{ $usage['free'] }}</dd></div>
                <div><dt class="text-gray-500">Tamanho do disco</dt><dd class="font-semibold">{{ $usage['total'] }}</dd></div>
            </dl>
        @endif
    </div>
</div>
