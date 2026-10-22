<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
use App\Support\Money;
use App\Support\QueryProfile;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(QueryProfile::class);
    }

    public function boot(): void
    {
        Carbon::setLocale('pt_BR');
        CarbonImmutable::setLocale('pt_BR');

        // Senha mínima: 8 caracteres. Quem cria a senha inicial é o comando ou o gestor (aleatória).
        Password::defaults(fn () => Password::min(8));

        $this->registerAuthorization();

        // Log de pedidos lentos: conta as consultas de cada pedido (só quantas e as mais lentas).
        if ((int) config('logging.slow_request_ms') > 0) {
            DB::listen(fn (QueryExecuted $query) => $this->app->make(QueryProfile::class)->add($query));
        }

        // `$money($centavos)` em todas as telas, partials e PDFs ("R$ 1.234,56").
        View::share('money', fn ($cents) => Money::brl($cents));
        // Classes dos campos e botões, as mesmas em todas as telas (não redefinir no @php da tela).
        View::share('inputClass', 'w-full px-3 py-2.5 text-base sm:text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-brand-500 focus:border-brand-500 disabled:bg-gray-100 disabled:text-gray-600 dark:disabled:bg-gray-800 dark:disabled:text-gray-400 disabled:cursor-not-allowed');
        View::share('labelClass', 'block mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400');
        View::share('btnPrimary', 'inline-flex items-center justify-center gap-2 min-h-11 px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white font-semibold disabled:opacity-50');
        View::share('btnSecondary', 'inline-flex items-center justify-center gap-2 min-h-11 px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 font-semibold hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50');
        View::share('cardClass', 'bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm');
    }

    /**
     * Três perfis fixos. O gestor passa em tudo; cada tela pede o perfil que a usa.
     *  - gestor: ajustes, usuários, saúde do sistema, aprovações acima da alçada;
     *  - atendimento: recepção, cotação, orçamento, aceite, entrega;
     *  - oficina: vistoria técnica, diagnóstico, execução, conferência (mecânico).
     */
    private function registerAuthorization(): void
    {
        Gate::define('gestor', fn (User $user) => $user->isGestor());
        Gate::define('atendimento', fn (User $user) => $user->hasRole(Role::Atendimento));
        Gate::define('oficina', fn (User $user) => $user->hasRole(Role::Mecanico));
    }
}
