<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.guest')] #[Title('Acessar')] class extends Component
{
    public string $email = '';

    public string $password = '';

    // No celular da oficina e no do mecânico o login fica guardado: entra uma vez e pronto.
    public bool $remember = true;

    public function login()
    {
        // O teclado do celular põe espaço no fim e letra maiúscula no começo.
        $this->email = Str::lower(trim($this->email));

        $this->validate([
            'email' => 'required|email',
            'password' => 'required',
        ], [
            'email.required' => 'Informe o e-mail.',
            'email.email' => 'Esse e-mail não parece certo.',
            'password.required' => 'Informe a senha.',
        ]);

        // Só as tentativas erradas contam: 5 por e-mail em 5 minutos e 30 por endereço (IP).
        $emailKey = 'login:email:'.$this->email.'|'.request()->ip();
        $ipKey = 'login:ip:'.request()->ip();

        if (RateLimiter::tooManyAttempts($emailKey, 5) || RateLimiter::tooManyAttempts($ipKey, 30)) {
            $seconds = max(RateLimiter::availableIn($emailKey), RateLimiter::availableIn($ipKey));
            $wait = $seconds >= 90 ? ceil($seconds / 60).' minutos' : $seconds.' segundos';
            $this->addError('email', 'Muitas tentativas erradas. Tente de novo em '.$wait.'.');

            return;
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password, 'active' => true], $this->remember)) {
            RateLimiter::hit($emailKey, 300);
            RateLimiter::hit($ipKey, 300);
            $this->addError('email', 'E-mail ou senha não conferem (ou o acesso está desativado).');

            return;
        }

        RateLimiter::clear($emailKey);
        session()->regenerate();
        Auth::user()->forceFill(['last_login_at' => now()])->save();

        return $this->redirectIntended(route('dashboard'));
    }
};
?>

<div class="min-h-screen flex items-center justify-center p-4" style="padding-top: max(1rem, env(safe-area-inset-top))">
    <div class="w-full max-w-sm">
        <div class="text-center mb-6">
            <img src="{{ asset('images/logo.png') }}" alt="Multmec" class="size-24 mx-auto rounded-full ring-4 ring-plate-2 shadow-lg">
            <h1 class="mt-3 font-display font-extrabold text-3xl uppercase tracking-wide text-plate-ink">Multmec</h1>
            <p class="text-plate-muted text-sm">Ordens de serviço · Liberdade</p>
        </div>

        <form wire:submit="login" class="bg-white dark:bg-gray-800 rounded-xl shadow-xl p-5 space-y-4 border-t-4 border-stripe">
            @if (session('status'))
                <p class="text-sm rounded-lg bg-amber-50 text-amber-800 px-3 py-2">{{ session('status') }}</p>
            @endif

            <div>
                <label for="email" class="{{ $labelClass }}">E-mail</label>
                <input id="email" type="email" wire:model="email" autocomplete="username" inputmode="email" autocapitalize="none" class="{{ $inputClass }}" required>
                @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="password" class="{{ $labelClass }}">Senha</label>
                <input id="password" type="password" wire:model="password" autocomplete="current-password" class="{{ $inputClass }}" required>
                @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input type="checkbox" wire:model="remember" class="size-5 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                Manter conectado neste aparelho
            </label>

            <button type="submit" class="{{ $btnPrimary }} w-full" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="login">Entrar</span>
                <span wire:loading wire:target="login">Entrando…</span>
            </button>

            <p class="text-xs text-gray-500 dark:text-gray-400 text-center">Esqueceu a senha? Peça ao gestor para trocar.</p>
        </form>
    </div>
</div>
