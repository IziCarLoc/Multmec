<?php

namespace App\Http\Middleware;

use App\Support\QueryProfile;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Registra os pedidos que passam de SLOW_REQUEST_MS (padrão 1 s) em storage/logs/lento-AAAA-MM-DD.log:
 * a tela (rota ou componente Livewire e o método chamado), o tempo, quantas consultas e as 5 mais
 * lentas. Serve para achar no servidor o que está devagar sem adivinhar. Nada de valores: só nomes
 * de tela, de método e o SQL com "?".
 */
class LogSlowRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $limit = (int) config('logging.slow_request_ms');

        if ($limit <= 0) {
            return $next($request);
        }

        $profile = app(QueryProfile::class);
        $profile->reset();
        $start = microtime(true);

        $response = $next($request);

        $ms = (int) round((microtime(true) - $start) * 1000);
        // No servidor, conta também o tempo para subir o Laravel (sem OPcache ou sem cache de config ele pesa).
        $boot = PHP_SAPI !== 'cli' && defined('LARAVEL_START') ? (int) round(($start - LARAVEL_START) * 1000) : 0;

        if ($ms + $boot >= $limit) {
            try {
                Log::channel('lento')->warning('Pedido lento', [
                    'ms' => $ms + $boot,
                    'subir_laravel_ms' => $boot,
                    'consultas' => $profile->count(),
                    'ms_em_consultas' => (int) round($profile->time()),
                    'tela' => $request->route()?->getName() ?? $request->path(),
                    'livewire' => $this->livewireCalls($request),
                    'metodo_http' => $request->method(),
                    'status' => $response->getStatusCode(),
                    'usuario' => $request->user()?->getAuthIdentifier(),
                    'consultas_mais_lentas' => $profile->slowest(),
                ]);
            } catch (Throwable) {
                // O log de lentidão nunca derruba a tela.
            }
        }

        return $response;
    }

    /**
     * Componentes e métodos de um pedido do Livewire ("pages::auth.receivables.index savePay").
     *
     * @return list<string>
     */
    private function livewireCalls(Request $request): array
    {
        $components = $request->input('components');

        if (! is_array($components)) {
            return [];
        }

        $calls = [];

        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }

            $snapshot = json_decode((string) ($component['snapshot'] ?? ''), true);
            $name = is_array($snapshot) ? (string) ($snapshot['memo']['name'] ?? '?') : '?';
            $methods = collect($component['calls'] ?? [])->map(fn ($call) => is_array($call) ? ($call['method'] ?? null) : null)->filter()->values()->all();
            $updates = is_array($component['updates'] ?? null) ? array_slice(array_keys($component['updates']), 0, 5) : [];

            $calls[] = trim($name.' '.implode(',', $methods !== [] ? $methods : array_map(fn ($property) => 'alterou '.$property, $updates)));
        }

        return $calls;
    }
}
