<?php

use App\Livewire\Concerns\RunsDomainActions;
use App\Models\Budget;
use App\Models\PublicLink;
use App\Services\Budgets\Budgets;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Orçamento para o cliente, pelo link (sem login). Abrir não muda nada (a prévia do WhatsApp também abre o
 * link): só o botão grava a resposta, com nome, CPF, IP, aparelho e a hora do servidor. Uma resposta só.
 */
new #[Layout('layouts.guest')] #[Title('Orçamento')] class extends Component
{
    use RunsDomainActions;

    #[Locked]
    public string $token = '';

    /** @var list<int> */
    public array $approvedItems = [];

    public bool $nonOriginal = false;

    public string $signerName = '';

    public string $signerDocument = '';

    public bool $refusing = false;

    public string $reason = '';

    public ?string $answered = null;

    public function mount(string $token, Budgets $budgets): void
    {
        $this->token = $token;
        $link = $this->link();
        abort_unless($link, 404);

        $budgets->viewed($link);
        $this->approvedItems = collect($link->budget->snapshot['itens'])->pluck('id')->all();
    }

    private function link(): ?PublicLink
    {
        return app(Budgets::class)->findLink($this->token);
    }

    public function approve(Budgets $budgets): void
    {
        $link = $this->link();
        abort_unless($link, 404);

        $done = $this->attempt(fn () => $budgets->answerByLink($link, true, [
            'items' => array_map('intval', $this->approvedItems),
            'non_original' => $this->nonOriginal,
            'signer_name' => $this->signerName,
            'signer_document' => $this->signerDocument,
        ], request()->ip(), request()->userAgent()), 'signerName');

        if ($done) {
            $this->answered = 'aprovado';
        }
    }

    public function refuse(Budgets $budgets): void
    {
        $link = $this->link();
        abort_unless($link, 404);

        $done = $this->attempt(fn () => $budgets->answerByLink($link, false, [
            'reason' => $this->reason,
            'signer_name' => $this->signerName,
            'signer_document' => $this->signerDocument,
        ], request()->ip(), request()->userAgent()), 'reason');

        if ($done) {
            $this->answered = 'recusado';
        }
    }
};
?>

@php
    $link = $this->link();
    $budget = $link->budget;
    $s = $budget->snapshot;
    $open = $link->isUsable() && $budget->isPending() && $answered === null;
    $selected = collect($s['itens'])->whereIn('id', array_map('intval', $approvedItems));
    $needsAuthorization = $selected->contains(fn ($i) => $i['nao_original']);
@endphp

<div class="min-h-screen bg-gray-100 dark:bg-gray-900 px-4 py-6">
    <div class="mx-auto max-w-2xl space-y-4">
        <div class="flex items-center gap-3 text-white">
            <img src="{{ asset('images/logo.png') }}" alt="" class="size-12 rounded-full bg-white">
            <div>
                <p class="font-display font-extrabold text-2xl uppercase tracking-wide leading-none text-gray-900 dark:text-white">{{ $s['oficina']['nome'] }}</p>
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ $budget->title() }} · {{ $s['os'] }}</p>
            </div>
        </div>

        <div class="{{ $cardClass }} p-4 space-y-1">
            <p class="text-lg font-semibold">{{ $s['veiculo']['descricao'] }} · placa {{ $s['veiculo']['placa'] }}</p>
            <p class="text-sm text-gray-600 dark:text-gray-300">Cliente: {{ $s['cliente'] }}</p>
            @if ($s['diagnostico'])
                <p class="text-sm pt-2"><strong>Diagnóstico:</strong> {{ $s['diagnostico'] }}</p>
            @endif
        </div>

        @if ($answered === 'aprovado' || ($budget->status === Budget::STATUS_APPROVED && $answered === null))
            <div class="rounded-xl border border-emerald-300 bg-emerald-50 dark:bg-emerald-900/30 p-4 text-emerald-900 dark:text-emerald-100" data-test="respondido">
                <p class="font-semibold"><i class="fas fa-circle-check"></i> Orçamento aprovado{{ $budget->decided_at ? ' em '.$budget->decided_at->format('d/m/Y H:i') : '' }}. Obrigado!</p>
                <p class="text-sm">A oficina já foi avisada e segue com o serviço aprovado.</p>
            </div>
        @elseif ($answered === 'recusado' || $budget->status === Budget::STATUS_REFUSED)
            <div class="rounded-xl border border-gray-300 bg-white dark:bg-gray-800 p-4" data-test="respondido">
                <p class="font-semibold">Resposta registrada: orçamento recusado. A oficina vai entrar em contato para combinar a retirada do carro.</p>
            </div>
        @elseif (! $open)
            <div class="rounded-xl border border-amber-300 bg-amber-50 dark:bg-amber-900/30 p-4" data-test="link-encerrado">
                <p class="font-semibold">Este link não vale mais{{ $link->expires_at->isPast() ? ' (a validade do orçamento venceu)' : '' }}. Fale com a oficina{{ $s['oficina']['telefone'] ? ' pelo '.$s['oficina']['telefone'] : '' }}.</p>
            </div>
        @endif

        <div class="{{ $cardClass }} divide-y divide-gray-100 dark:divide-gray-700">
            @foreach ($s['itens'] as $item)
                <label class="flex items-start gap-3 p-4 {{ $open ? 'cursor-pointer' : '' }}" wire:key="i-{{ $item['id'] }}">
                    @if ($open)
                        <input type="checkbox" wire:model.live="approvedItems" value="{{ $item['id'] }}" class="mt-1 size-5 rounded border-gray-300 text-brand-600" data-test="item-{{ $item['id'] }}">
                    @endif
                    <span class="flex-1 min-w-0">
                        <span class="font-semibold">{{ $item['descricao'] }}</span>
                        <span class="block text-xs text-gray-500">
                            {{ $item['tipo'] === 'peca' ? 'Peça · '.Budgets::quantity($item['quantidade']).' un' : 'Serviço' }}
                            @if ($item['origem']) · {{ $item['origem'] }} @endif
                            @if ($item['marca']) · {{ $item['marca'] }} @endif
                            @if ($item['prioridade'] === 'recomendado') · <span class="text-amber-700 dark:text-amber-300 font-semibold">recomendado</span> @else · necessário @endif
                        </span>
                    </span>
                    <span class="font-semibold whitespace-nowrap">{{ Money::brl($item['total']) }}</span>
                </label>
            @endforeach
            <div class="p-4 space-y-1 text-sm">
                <div class="flex justify-between"><span>Peças</span><span>{{ Money::brl($s['pecas']) }}</span></div>
                <div class="flex justify-between"><span>Mão de obra</span><span>{{ Money::brl($s['mao_de_obra']) }}</span></div>
                <div class="flex justify-between text-lg font-bold"><span>Total</span><span>{{ Money::brl($s['total']) }}</span></div>
                @if ($open && $selected->count() !== count($s['itens']))
                    <div class="flex justify-between font-semibold text-brand-700 dark:text-brand-300"><span>Você está aprovando</span><span data-test="total-aprovando">{{ Money::brl((int) $selected->sum('total')) }}</span></div>
                @endif
            </div>
        </div>

        <div class="{{ $cardClass }} p-4 text-sm space-y-1">
            <p><strong>Pagamento:</strong> {{ $s['pagamento'] ?: 'a combinar' }}</p>
            <p><strong>Previsão de entrega:</strong> {{ $s['previsao'] ? CarbonImmutable::parse($s['previsao'])->format('d/m/Y') : 'a combinar' }}</p>
            <p><strong>Validade:</strong> até {{ $link->expires_at->format('d/m/Y') }}</p>
            @if ($s['observacoes']) <p><strong>Observações:</strong> {{ $s['observacoes'] }}</p> @endif
            <details class="pt-2">
                <summary class="cursor-pointer text-brand-600">Garantia e autorização</summary>
                <p class="mt-2 whitespace-pre-line text-xs text-gray-700 dark:text-gray-200">{{ $s['garantia'] }}</p>
                <p class="mt-2 whitespace-pre-line text-xs text-gray-700 dark:text-gray-200">{{ $s['texto_aceite'] }}</p>
            </details>
        </div>

        @if ($open)
            <div class="{{ $cardClass }} p-4 space-y-3" data-test="responder">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="{{ $labelClass }}" for="signerName">Seu nome</label>
                        <input id="signerName" type="text" wire:model="signerName" maxlength="120" autocomplete="name" class="{{ $inputClass }}" data-test="nome">
                        @error('signerName') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="signerDocument">CPF ou CNPJ{{ $refusing ? ' (opcional)' : '' }}</label>
                        <x-document-input id="signerDocument" model="signerDocument" />
                    </div>
                </div>

                @if ($refusing)
                    <div>
                        <label class="{{ $labelClass }}" for="reason">Por que não vai fazer? (ajuda a oficina)</label>
                        <textarea id="reason" wire:model="reason" rows="2" class="{{ $inputClass }}" data-test="motivo"></textarea>
                        @error('reason') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex flex-wrap justify-end gap-2">
                        <button type="button" wire:click="$set('refusing', false)" class="{{ $btnSecondary }}">Voltar</button>
                        <button type="button" wire:click="refuse" class="{{ $btnPrimary }} bg-red-600 hover:bg-red-700" data-test="confirmar-recusa">Confirmar: não vou fazer</button>
                    </div>
                @else
                    @if ($needsAuthorization)
                        <label class="flex items-center gap-3 min-h-11 rounded-lg bg-amber-50 dark:bg-amber-900/30 px-3 text-sm">
                            <input type="checkbox" wire:model="nonOriginal" class="size-5 rounded border-gray-300 text-brand-600" data-test="autoriza">
                            Autorizo a peça não original (paralela ou usada) indicada acima.
                        </label>
                    @endif
                    <p class="text-xs text-gray-500">Ao aprovar, você autoriza os itens marcados, nas condições acima. Ficam registrados o seu nome, o documento, a hora e o aparelho.</p>
                    <div class="flex flex-wrap justify-end gap-2">
                        <button type="button" wire:click="$set('refusing', true)" class="{{ $btnSecondary }}" data-test="recusar">Não vou fazer</button>
                        <button type="button" wire:click="approve" class="{{ $btnPrimary }}" data-test="aprovar"><i class="fas fa-check"></i> Aprovar {{ Money::brl((int) $selected->sum('total')) }}</button>
                    </div>
                @endif
            </div>
        @endif

        <p class="text-center text-xs text-gray-500">SHA-256 do orçamento: {{ substr($budget->snapshot_sha256, 0, 16) }}…</p>
    </div>
</div>
