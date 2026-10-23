<?php

namespace App\Enums;

/**
 * Etapas da OS, na ordem do caminho normal. Quem pode levar a OS de uma etapa para a outra, e o que
 * precisa estar preenchido, fica em App\Services\Orders\StageMachine e StageGate.
 */
enum Stage: string
{
    case Aberta = 'aberta';
    case EmVistoria = 'em_vistoria';
    case EmDiagnostico = 'em_diagnostico';
    case AguardandoCotacao = 'aguardando_cotacao';
    case AguardandoAprovacao = 'aguardando_aprovacao';
    case Aprovada = 'aprovada';
    case Recusada = 'recusada';
    case AguardandoPecas = 'aguardando_pecas';
    case EmExecucao = 'em_execucao';
    case EmConferencia = 'em_conferencia';
    case ProntaRetirada = 'pronta_retirada';
    case Entregue = 'entregue';
    case Cancelada = 'cancelada';

    public function label(): string
    {
        return match ($this) {
            self::Aberta => 'Aberta',
            self::EmVistoria => 'Em vistoria',
            self::EmDiagnostico => 'Em diagnóstico',
            self::AguardandoCotacao => 'Em orçamento',
            self::AguardandoAprovacao => 'Aguardando aprovação',
            self::Aprovada => 'Aprovada',
            self::Recusada => 'Recusada',
            self::AguardandoPecas => 'Aguardando peças',
            self::EmExecucao => 'Em execução',
            self::EmConferencia => 'Em conferência',
            self::ProntaRetirada => 'Pronta para retirada',
            self::Entregue => 'Entregue',
            self::Cancelada => 'Cancelada',
        };
    }

    /**
     * Classes do selo da etapa (classes inteiras: o Tailwind só compila o que lê). Poucas cores: cinza
     * (parada), azul (na oficina), âmbar (esperando alguém), verde (pronta) e vermelho (recusa/cancelamento).
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Aberta => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
            self::EmVistoria, self::EmDiagnostico => 'bg-brand-50 text-brand-700 ring-1 ring-brand-200 dark:bg-brand-900/30 dark:text-brand-200 dark:ring-brand-800',
            self::AguardandoCotacao, self::AguardandoAprovacao, self::AguardandoPecas => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
            self::Aprovada, self::EmExecucao, self::EmConferencia => 'bg-brand-100 text-brand-800 dark:bg-brand-900/50 dark:text-brand-200',
            self::ProntaRetirada => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
            self::Entregue => 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
            self::Recusada, self::Cancelada => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-200',
        };
    }

    /** Final: a OS não muda mais de etapa (Entregue: defeito depois vira retorno em garantia). */
    public function isFinal(): bool
    {
        return $this === self::Entregue || $this === self::Cancelada;
    }

    /** Posição no caminho normal (para "voltar etapa" e para ordenar o quadro). */
    public function position(): int
    {
        return array_search($this, self::path(), true);
    }

    /**
     * O caminho normal, na ordem. Recusada fica fora (é um desvio: devolve o carro); Cancelada também.
     *
     * @return list<self>
     */
    public static function path(): array
    {
        return [
            self::Aberta, self::EmVistoria, self::EmDiagnostico, self::AguardandoCotacao, self::AguardandoAprovacao,
            self::Aprovada, self::AguardandoPecas, self::EmExecucao, self::EmConferencia, self::ProntaRetirada, self::Entregue,
        ];
    }

    /**
     * Colunas do quadro: tudo o que ainda está na oficina.
     *
     * @return list<self>
     */
    public static function board(): array
    {
        return [
            self::Aberta, self::EmVistoria, self::EmDiagnostico, self::AguardandoCotacao, self::AguardandoAprovacao,
            self::Aprovada, self::AguardandoPecas, self::EmExecucao, self::EmConferencia, self::ProntaRetirada, self::Recusada,
        ];
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return array_map(fn (self $stage) => $stage->value, self::board());
    }
}
