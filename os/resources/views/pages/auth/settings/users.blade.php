<?php

use App\Enums\Role;
use App\Livewire\Concerns\RunsDomainActions;
use App\Models\User;
use App\Services\Users\UserManager;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Usuários')] class extends Component
{
    use RunsDomainActions;

    public bool $editing = false;

    public ?int $userId = null;

    public string $name = '';

    public string $shortName = '';

    public string $email = '';

    public string $role = 'mecanico';

    public bool $active = true;

    /** Senha provisória acabada de gerar: mostrada uma vez, numa janela. */
    public ?string $shownPassword = null;

    public ?string $shownFor = null;

    #[Computed]
    public function users()
    {
        return User::query()->orderByDesc('active')->orderBy('role')->orderBy('short_name')->get();
    }

    public function create(): void
    {
        $this->resetValidation();
        $this->reset('userId', 'name', 'shortName', 'email');
        $this->role = Role::Mecanico->value;
        $this->active = true;
        $this->editing = true;
    }

    public function edit(int $id): void
    {
        $user = User::findOrFail($id);
        $this->resetValidation();
        $this->userId = $user->id;
        $this->name = $user->name;
        $this->shortName = $user->short_name;
        $this->email = $user->email;
        $this->role = $user->role->value;
        $this->active = $user->active;
        $this->editing = true;
    }

    public function close(): void
    {
        $this->editing = false;
    }

    public function save(UserManager $users): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'shortName' => 'required|string|max:40',
            'email' => 'required|email|max:255',
            'role' => ['required', Rule::enum(Role::class)],
        ], [
            'name.required' => 'Informe o nome completo.',
            'shortName.required' => 'Informe o nome curto (aparece nas listas).',
            'email.required' => 'Informe o e-mail.',
            'email.email' => 'Esse e-mail não parece certo.',
        ]);

        $role = Role::from($this->role);

        if ($this->userId) {
            $done = $this->attempt(fn () => $users->update(User::findOrFail($this->userId), $this->name, $this->shortName, $this->email, $role, $this->active));

            if ($done) {
                $this->editing = false;
                $this->dispatch('toast', message: 'Usuário atualizado.', type: 'success');
            }

            return;
        }

        $created = $this->attempt(fn () => $users->create($this->name, $this->shortName, $this->email, $role));

        if ($created) {
            $this->editing = false;
            $this->shownFor = $created['user']->short_name;
            $this->shownPassword = $created['password'];
        }
    }

    public function resetPassword(int $id, UserManager $users): void
    {
        $user = User::findOrFail($id);
        $this->shownFor = $user->short_name;
        $this->shownPassword = $users->resetPassword($user);
    }

    public function closePassword(): void
    {
        $this->reset('shownPassword', 'shownFor');
    }
};
?>

<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-3">
        <a href="{{ route('settings') }}" class="text-sm text-brand-600 hover:underline"><i class="fas fa-arrow-left"></i> Ajustes</a>
        <h1 class="font-display font-extrabold text-3xl uppercase tracking-wide w-full sm:w-auto">Usuários</h1>
        <button type="button" wire:click="create" class="{{ $btnPrimary }} sm:ml-auto"><i class="fas fa-plus"></i> Novo usuário</button>
    </div>

    <div class="{{ $cardClass }} divide-y divide-gray-100 dark:divide-gray-700">
        @foreach ($this->users as $user)
            <div class="p-4 flex flex-wrap items-center gap-3" wire:key="user-{{ $user->id }}">
                <span class="size-10 inline-flex items-center justify-center rounded-full {{ $user->active ? 'bg-brand-600 text-white' : 'bg-gray-200 text-gray-500' }} font-semibold">{{ mb_strtoupper(mb_substr($user->short_name, 0, 1)) }}</span>
                <div class="min-w-0 flex-1">
                    <p class="font-semibold truncate">{{ $user->short_name }} <span class="font-normal text-gray-500">· {{ $user->name }}</span></p>
                    <p class="text-sm text-gray-500 dark:text-gray-400 truncate">
                        {{ $user->role->label() }} · {{ $user->email }}
                        @if (! $user->active) · <span class="text-red-600">desativado</span> @endif
                        @if ($user->must_change_password) · <span class="text-amber-600">senha provisória</span> @endif
                    </p>
                </div>
                <div class="flex gap-2">
                    <button type="button" wire:click="edit({{ $user->id }})" class="{{ $btnSecondary }}"><i class="fas fa-pen"></i> Editar</button>
                    <button type="button" wire:click="resetPassword({{ $user->id }})" wire:confirm="Gerar uma senha provisória nova para {{ $user->short_name }}? A senha atual deixa de valer." class="{{ $btnSecondary }}"><i class="fas fa-key"></i> Nova senha</button>
                </div>
            </div>
        @endforeach
    </div>

    @if ($editing)
        <x-modal close="close" form="save">
            <h2 class="font-display font-bold text-xl uppercase">{{ $userId ? 'Editar usuário' : 'Novo usuário' }}</h2>
            <div>
                <label class="{{ $labelClass }}" for="u-name">Nome completo</label>
                <input id="u-name" type="text" wire:model="name" class="{{ $inputClass }}">
                @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $labelClass }}" for="u-short">Nome curto (aparece nas listas e no histórico)</label>
                <input id="u-short" type="text" wire:model="shortName" placeholder="Ex.: Gabriel C." class="{{ $inputClass }}">
                @error('shortName') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $labelClass }}" for="u-email">E-mail (usado para entrar)</label>
                <input id="u-email" type="email" wire:model="email" autocapitalize="none" class="{{ $inputClass }}">
                @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $labelClass }}" for="u-role">Perfil</label>
                <select id="u-role" wire:model="role" class="{{ $inputClass }}">
                    @foreach (\App\Enums\Role::options() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            @if ($userId)
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="active" class="size-5 rounded border-gray-300 text-brand-600">
                    Acesso ativo
                </label>
            @endif
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" wire:click="close" class="{{ $btnSecondary }}">Cancelar</button>
                <button type="submit" class="{{ $btnPrimary }}">Salvar</button>
            </div>
        </x-modal>
    @endif

    @if ($shownPassword)
        <x-modal close="closePassword" data-test="senha-provisoria">
            <h2 class="font-display font-bold text-xl uppercase">Senha provisória</h2>
            <p class="text-sm">Passe esta senha para <strong>{{ $shownFor }}</strong>. Ela aparece só agora; no primeiro acesso a pessoa cria a própria senha.</p>
            <p class="font-mono text-2xl text-center tracking-widest bg-gray-100 dark:bg-gray-900 rounded-lg py-3 select-all">{{ $shownPassword }}</p>
            <div class="flex justify-end">
                <button type="button" wire:click="closePassword" class="{{ $btnPrimary }}">Anotei</button>
            </div>
        </x-modal>
    @endif
</div>
