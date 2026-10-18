<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Minha senha')] class extends Component
{
    public string $current = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function save(): void
    {
        $user = Auth::user();

        $this->validate([
            'current' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults(), 'different:current'],
        ], [
            'current.required' => 'Informe a senha que você usou para entrar.',
            'current.current_password' => 'A senha atual não confere.',
            'password.required' => 'Crie a nova senha.',
            'password.confirmed' => 'A confirmação não é igual à nova senha.',
            'password.min' => 'A nova senha precisa de pelo menos 8 caracteres.',
            'password.different' => 'A nova senha precisa ser diferente da atual.',
        ]);

        $user->forceFill([
            'password' => Hash::make($this->password),
            'must_change_password' => false,
        ])->save();

        // As outras sessões abertas com a senha antiga caem (celular perdido, senha que vazou).
        Auth::logoutOtherDevices($this->password);

        $this->reset('current', 'password', 'password_confirmation');
        session()->flash('toast', ['message' => 'Senha trocada.', 'type' => 'success']);
        $this->redirectRoute('dashboard');
    }
};
?>

<div class="max-w-md mx-auto space-y-4">
    @if (auth()->user()->must_change_password)
        <div class="rounded-xl bg-amber-50 dark:bg-amber-900/30 text-amber-900 dark:text-amber-100 border border-amber-200 dark:border-amber-800 p-4 text-sm">
            <p class="font-semibold">Bem-vindo, {{ auth()->user()->short_name }}!</p>
            <p>Antes de começar, crie a sua senha. A senha que você recebeu foi provisória.</p>
        </div>
    @endif

    <form wire:submit="save" class="{{ $cardClass }} p-5 space-y-4">
        <h1 class="font-display font-bold text-2xl uppercase tracking-wide">Trocar minha senha</h1>

        <div>
            <label for="current" class="{{ $labelClass }}">Senha atual (a que você usou para entrar)</label>
            <input id="current" type="password" wire:model="current" autocomplete="current-password" class="{{ $inputClass }}">
            @error('current') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password" class="{{ $labelClass }}">Nova senha (mínimo 8 caracteres)</label>
            <input id="password" type="password" wire:model="password" autocomplete="new-password" class="{{ $inputClass }}">
            @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password_confirmation" class="{{ $labelClass }}">Repita a nova senha</label>
            <input id="password_confirmation" type="password" wire:model="password_confirmation" autocomplete="new-password" class="{{ $inputClass }}">
        </div>

        <button type="submit" class="{{ $btnPrimary }} w-full">Salvar nova senha</button>
    </form>
</div>
