<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Conta desativada sai na hora (mesmo com "manter conectado" no celular). Senha inicial ou trocada
 * pelo gestor: a pessoa só usa o sistema depois de criar a própria senha.
 */
class EnsureAccountIsUsable
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if (! $user->active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', 'Seu acesso foi desativado. Fale com o gestor.');
        }

        if ($user->must_change_password && ! $request->routeIs('profile', 'logout') && ! $request->is('livewire*')) {
            return redirect()->route('profile');
        }

        return $next($request);
    }
}
