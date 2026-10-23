<?php

use App\Enums\CreditKind;
use App\Livewire\Concerns\RunsDomainActions;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Services\Finance\Credit;
use App\Services\Registry\IziFleet;
use App\Support\Document;
use App\Support\Phone;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Clientes e veículos')] class extends Component
{
    use RunsDomainActions;

    #[Url(as: 'aba', except: 'veiculos')]
    public string $tab = 'veiculos';

    #[Url(as: 'busca', except: '')]
    public string $search = '';

    /** 'vehicle' ou 'customer' com o id em edição. */
    public ?string $editing = null;

    public ?int $editId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    #[Computed]
    public function vehicles()
    {
        return Vehicle::query()->with('customer')->withCount('serviceOrders')
            ->when(trim($this->search) !== '', fn ($q) => $q->search($this->search)->orWhereHas('customer', fn ($c) => $c->search($this->search)))
            ->orderBy('plate')->limit(100)->get();
    }

    #[Computed]
    public function customers()
    {
        return Customer::query()->withCount(['vehicles', 'serviceOrders'])
            ->when(trim($this->search) !== '', fn ($q) => $q->search($this->search))
            ->orderByDesc('is_izi_fleet')->orderBy('name')->limit(100)->get();
    }

    public function editVehicle(int $id): void
    {
        $vehicle = Vehicle::findOrFail($id);
        $this->resetValidation();
        $this->editing = 'vehicle';
        $this->editId = $id;
        $this->form = [
            'brand_model' => (string) $vehicle->brand_model,
            'year' => (string) $vehicle->year,
            'color' => (string) $vehicle->color,
            'chassis' => (string) $vehicle->chassis,
            'engine' => (string) $vehicle->engine,
            'is_izi_fleet' => $vehicle->is_izi_fleet,
            'investor_name' => (string) $vehicle->investor_name,
            'investor_whatsapp' => $vehicle->investorWhatsappFormatted(),
            'notes' => (string) $vehicle->notes,
        ];
    }

    public function editCustomer(int $id): void
    {
        $customer = Customer::findOrFail($id);
        $this->resetValidation();
        $this->editing = 'customer';
        $this->editId = $id;
        $this->form = [
            'name' => $customer->name,
            'whatsapp' => $customer->whatsappFormatted(),
            'phone2' => Phone::format($customer->phone2),
            'document' => $customer->documentFormatted(),
            'email' => (string) $customer->email,
            'address' => (string) $customer->address,
            'notes' => (string) $customer->notes,
            // Crédito a prazo: só o gestor muda (Finance\Credit::updateTerms).
            'credit_kind' => $customer->credit_kind->value,
            'credit_days' => (string) $customer->credit_days,
            'credit_limit' => $customer->credit_limit_cents > 0 ? number_format($customer->credit_limit_cents / 100, 2, ',', '.') : '',
        ];
    }

    public function close(): void
    {
        $this->editing = null;
    }

    public function save(): void
    {
        $phone = fn ($attr, $value, $fail) => filled($value) && ! Phone::isValid($value) ? $fail('Telefone com DDD, por exemplo (55) 99999-9999.') : null;

        if ($this->editing === 'vehicle') {
            $this->validate(['form.investor_whatsapp' => ['nullable', $phone], 'form.chassis' => 'nullable|max:17'], ['form.chassis.max' => 'O chassi tem 17 caracteres.']);
            $vehicle = Vehicle::findOrFail($this->editId);
            $data = collect($this->form)->only(['brand_model', 'year', 'color', 'chassis', 'engine', 'investor_name', 'notes'])->map(fn ($v) => trim((string) $v) ?: null)->all();
            $data['investor_whatsapp'] = Phone::digits($this->form['investor_whatsapp'] ?? '') ?: null;
            $data['is_izi_fleet'] = (bool) ($this->form['is_izi_fleet'] ?? false);

            // Entrou na frota: o cliente passa a ser a Izi Car.
            if ($data['is_izi_fleet'] && ! $vehicle->is_izi_fleet) {
                $data['customer_id'] = IziFleet::customer()->id;
            }

            $vehicle->update($data);
            AuditLog::record($vehicle, 'vehicle.updated');
        } else {
            $this->validate([
                'form.name' => 'required|min:2|max:120',
                'form.whatsapp' => ['nullable', $phone],
                'form.phone2' => ['nullable', $phone],
                'form.email' => 'nullable|email|max:120',
                'form.document' => ['nullable', fn ($attr, $value, $fail) => filled($value) && ! Document::isValid($value) ? $fail('CPF ou CNPJ inválido.') : null],
            ], ['form.name.required' => 'Informe o nome.', 'form.email.email' => 'Esse e-mail não parece certo.']);

            $customer = Customer::findOrFail($this->editId);
            $terms = ['kind' => $this->form['credit_kind'] ?? $customer->credit_kind->value, 'days' => $this->form['credit_days'] ?? $customer->credit_days, 'limit' => (string) ($this->form['credit_limit'] ?? '')];

            if (! $this->attempt(fn () => app(Credit::class)->updateTerms($customer, $terms, auth()->user()), 'form.credit_limit')) {
                return;
            }

            $customer->update([
                'name' => trim($this->form['name']),
                'whatsapp' => Phone::digits($this->form['whatsapp'] ?? '') ?: null,
                'phone2' => Phone::digits($this->form['phone2'] ?? '') ?: null,
                'document' => Document::clean($this->form['document'] ?? '') ?: null,
                'email' => trim((string) ($this->form['email'] ?? '')) ?: null,
                'address' => mb_substr(trim((string) ($this->form['address'] ?? '')), 0, 255) ?: null,
                'notes' => trim((string) ($this->form['notes'] ?? '')) ?: null,
            ]);
            AuditLog::record($customer, 'customer.updated');
        }

        $this->editing = null;
        unset($this->vehicles, $this->customers);
        $this->dispatch('toast', message: 'Cadastro salvo.', type: 'success');
    }
};
?>

<div class="space-y-4">
    <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide flex items-center gap-2">
        <span class="w-1 h-6 rounded bg-brand-600"></span>Clientes e veículos
    </h1>
    <p class="text-sm text-gray-600 dark:text-gray-300">Carros e clientes entram sozinhos ao abrir a OS. Aqui se corrige e completa o cadastro.
        @can('gestor') A frota da Izi vem do Gestão em <a href="{{ route('settings.izi-fleet') }}" wire:navigate class="text-brand-600 hover:underline">Ajustes › Frota Izi</a>. @endcan
    </p>

    <div class="flex flex-wrap gap-3 items-center">
        <div class="flex gap-1 border-b border-gray-200 dark:border-gray-700">
            @foreach (['veiculos' => 'Veículos', 'clientes' => 'Clientes'] as $key => $label)
                <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['px-4 min-h-11 font-display font-semibold uppercase tracking-wide border-b-[3px]', 'border-brand-600 text-brand-700 dark:text-brand-300' => $tab === $key, 'border-transparent text-gray-500' => $tab !== $key])>{{ $label }}</button>
            @endforeach
        </div>
        <div class="relative flex-1 min-w-[14rem]">
            <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ $tab === 'veiculos' ? 'Placa, modelo, investidor ou cliente' : 'Nome, telefone ou CPF/CNPJ' }}" class="{{ $inputClass }} pl-9" data-test="busca">
        </div>
    </div>

    <div class="{{ $cardClass }} divide-y divide-gray-100 dark:divide-gray-700">
        @if ($tab === 'veiculos')
            @forelse ($this->vehicles as $vehicle)
                <button type="button" wire:click="editVehicle({{ $vehicle->id }})" class="w-full text-left p-3 flex items-center gap-3 hover:bg-gray-50 dark:hover:bg-gray-700/40" wire:key="v-{{ $vehicle->id }}">
                    <x-plate :plate="$vehicle->plate" />
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold truncate">{{ $vehicle->description() ?: 'Modelo não informado' }} @if ($vehicle->is_izi_fleet) <span class="text-xs font-semibold uppercase text-gray-500">· Izi</span> @endif</p>
                        <p class="text-sm text-gray-500 truncate">{{ $vehicle->customer?->name ?? 'sem cliente' }}{{ $vehicle->investor_name ? ' · investidor '.$vehicle->investor_name : '' }}</p>
                    </div>
                    <span class="text-xs text-gray-500 whitespace-nowrap">{{ $vehicle->service_orders_count }} OS</span>
                </button>
            @empty
                <p class="p-6 text-center text-gray-600 dark:text-gray-300">Nenhum veículo encontrado.</p>
            @endforelse
        @else
            @forelse ($this->customers as $customer)
                <button type="button" wire:click="editCustomer({{ $customer->id }})" class="w-full text-left p-3 flex items-center gap-3 hover:bg-gray-50 dark:hover:bg-gray-700/40" wire:key="c-{{ $customer->id }}">
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold truncate">{{ $customer->name }} @if ($customer->is_izi_fleet) <span class="text-xs font-semibold uppercase text-gray-500">· frota Izi</span> @endif</p>
                        <p class="text-sm text-gray-500 truncate">{{ $customer->whatsappFormatted() ?: 'sem WhatsApp' }}{{ $customer->document ? ' · '.$customer->documentFormatted() : '' }}</p>
                    </div>
                    <span class="text-xs text-gray-500 whitespace-nowrap">{{ $customer->vehicles_count }} carro(s) · {{ $customer->service_orders_count }} OS</span>
                </button>
            @empty
                <p class="p-6 text-center text-gray-600 dark:text-gray-300">Nenhum cliente encontrado.</p>
            @endforelse
        @endif
    </div>

    @if ($editing === 'vehicle')
        <x-modal close="close" form="save" size="lg" scroll data-test="janela-veiculo">
            <h2 class="font-display font-bold text-xl uppercase">Veículo {{ \App\Models\Vehicle::find($editId)?->plateFormatted() }}</h2>
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="sm:col-span-2"><label class="{{ $labelClass }}">Marca e modelo</label><input type="text" wire:model="form.brand_model" maxlength="80" class="{{ $inputClass }}"></div>
                <div><label class="{{ $labelClass }}">Ano</label><input type="text" wire:model="form.year" maxlength="9" class="{{ $inputClass }}"></div>
                <div><label class="{{ $labelClass }}">Cor</label><input type="text" wire:model="form.color" maxlength="30" class="{{ $inputClass }}"></div>
                <div><label class="{{ $labelClass }}">Chassi</label><input type="text" wire:model="form.chassis" maxlength="17" class="{{ $inputClass }} uppercase font-mono">@error('form.chassis') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror</div>
                <div><label class="{{ $labelClass }}">Motor</label><input type="text" wire:model="form.engine" maxlength="40" placeholder="Ex.: 1.0 8V flex" class="{{ $inputClass }}"></div>
                <label class="sm:col-span-2 flex items-center gap-3 min-h-11"><input type="checkbox" wire:model.live="form.is_izi_fleet" class="size-5 rounded border-gray-300 text-brand-600"> Frota Izi Car</label>
                @if ($form['is_izi_fleet'] ?? false)
                    <div><label class="{{ $labelClass }}">Investidor</label><input type="text" wire:model="form.investor_name" maxlength="120" class="{{ $inputClass }}"></div>
                    <div><label class="{{ $labelClass }}">WhatsApp do investidor</label><input type="tel" wire:model="form.investor_whatsapp" class="{{ $inputClass }}">@error('form.investor_whatsapp') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror</div>
                @endif
                <div class="sm:col-span-2"><label class="{{ $labelClass }}">Observações</label><textarea wire:model="form.notes" rows="2" class="{{ $inputClass }}"></textarea></div>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" wire:click="close" class="{{ $btnSecondary }}">Cancelar</button>
                <button type="submit" class="{{ $btnPrimary }}"><i class="fas fa-check"></i> Salvar</button>
            </div>
        </x-modal>
    @elseif ($editing === 'customer')
        <x-modal close="close" form="save" size="lg" scroll data-test="janela-cliente">
            <h2 class="font-display font-bold text-xl uppercase">Cliente</h2>
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="sm:col-span-2"><label class="{{ $labelClass }}">Nome</label><input type="text" wire:model="form.name" maxlength="120" class="{{ $inputClass }}">@error('form.name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror</div>
                <div><label class="{{ $labelClass }}">WhatsApp</label><input type="tel" wire:model="form.whatsapp" class="{{ $inputClass }}">@error('form.whatsapp') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror</div>
                <div><label class="{{ $labelClass }}">Outro telefone</label><input type="tel" wire:model="form.phone2" class="{{ $inputClass }}">@error('form.phone2') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror</div>
                <div><label class="{{ $labelClass }}">CPF ou CNPJ</label><x-document-input model="form.document" />@error('form.document') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror</div>
                <div><label class="{{ $labelClass }}">E-mail</label><input type="email" wire:model="form.email" class="{{ $inputClass }}">@error('form.email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror</div>
                <div class="sm:col-span-2"><label class="{{ $labelClass }}">Endereço</label><input type="text" wire:model="form.address" maxlength="255" placeholder="Rua, número, bairro…" class="{{ $inputClass }}" data-test="cliente-endereco"></div>
                <div class="sm:col-span-2"><label class="{{ $labelClass }}">Observações</label><textarea wire:model="form.notes" rows="2" class="{{ $inputClass }}"></textarea></div>
                <fieldset class="sm:col-span-2 rounded-lg border border-gray-200 dark:border-gray-700 p-3 space-y-3" data-test="cliente-credito">
                    <legend class="px-1 text-sm font-semibold">Crédito a prazo</legend>
                    @can('gestor')
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div class="sm:col-span-3"><label class="{{ $labelClass }}" for="c-tipo">Tipo</label>
                                <select id="c-tipo" wire:model="form.credit_kind" class="{{ $inputClass }}" data-test="cliente-tipo">
                                    @foreach (CreditKind::options() as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div><label class="{{ $labelClass }}" for="c-prazo">Prazo (dias)</label><input id="c-prazo" type="number" min="0" max="180" inputmode="numeric" wire:model="form.credit_days" class="{{ $inputClass }}" data-test="cliente-prazo"></div>
                            <div class="sm:col-span-2"><label class="{{ $labelClass }}" for="c-limite">Limite de crédito (R$)</label><input id="c-limite" type="text" inputmode="decimal" wire:model="form.credit_limit" placeholder="sem crédito" class="{{ $inputClass }}" data-test="cliente-limite"></div>
                        </div>
                        <p class="text-xs text-gray-500">Prazo 0 = paga na retirada. Limite = o máximo em aberto; vazio = sem crédito (a prazo só com a liberação do gestor).</p>
                    @else
                        <p class="text-sm" data-test="cliente-credito-leitura">{{ CreditKind::tryFrom((string) ($form['credit_kind'] ?? ''))?->label() ?? '—' }} · prazo {{ (int) ($form['credit_days'] ?? 0) > 0 ? $form['credit_days'].' dias' : 'na retirada' }} · limite {{ ($form['credit_limit'] ?? '') !== '' ? 'R$ '.$form['credit_limit'] : 'sem crédito' }}</p>
                        <p class="text-xs text-gray-500">Tipo, prazo e limite são definidos pelo gestor.</p>
                    @endcan
                    @error('form.credit_limit') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                </fieldset>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" wire:click="close" class="{{ $btnSecondary }}">Cancelar</button>
                <button type="submit" class="{{ $btnPrimary }}"><i class="fas fa-check"></i> Salvar</button>
            </div>
        </x-modal>
    @endif
</div>
