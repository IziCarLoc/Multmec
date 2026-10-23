<?php

namespace App\Services\Purchases;

use App\Models\Unit;
use App\Models\User;
use App\Support\Document;
use App\Support\Settings;
use Carbon\CarbonImmutable;

/**
 * Parâmetros da conferência de compras (Ajustes > Conferência de compras, padrões em config/oficina.php
 * `purchases`), o CNPJ da oficina (o das unidades ativas, Ajustes > Oficina e numeração) e as regrinhas comuns:
 * o motivo escrito de quem libera ou aceita algo grave e o perfil de quem fez (gestor ou atendimento).
 */
final class PurchaseSettings
{
    public const MIN_REASON = 'Explique em pelo menos 10 letras (ex.: "confirmei por telefone com a Maria" ou "nota chega amanhã").';

    /** O que o gestor pode mudar em Ajustes > Conferência de compras: chave => [rótulo, mínimo, máximo]. */
    public const EDITABLE = [
        'allocation_days' => ['Dias para dar destino às peças de uma nota', 1, 365],
        'tolerance_cents' => ['Diferença aceita no valor, em centavos', 0, 1000],
        'price_alert_pct' => ['Alerta de preço acima da mediana (%)', 1, 500],
        'invoice_without_slip_days' => ['Avisar parcela sem boleto com quantos dias de antecedência', 0, 60],
        'xml_check_cents' => ['Boleto a partir de (centavos) pede a consulta do gestor no portal quando o XML veio do atendimento', 0, 100_000_000],
        'group_return_days' => ['Prazo para a empresa do grupo devolver o que a oficina pagou (dias)', 1, 365],
    ];

    public static function toleranceCents(): int
    {
        return max(0, (int) Settings::get('purchases.tolerance_cents'));
    }

    public static function allocationDays(): int
    {
        return max(1, (int) Settings::get('purchases.allocation_days'));
    }

    public static function priceAlertPct(): int
    {
        return max(1, (int) Settings::get('purchases.price_alert_pct'));
    }

    public static function invoiceWithoutSlipDays(): int
    {
        return max(0, (int) Settings::get('purchases.invoice_without_slip_days'));
    }

    public static function xmlCheckCents(): int
    {
        return max(0, (int) Settings::get('purchases.xml_check_cents'));
    }

    public static function groupReturnDays(): int
    {
        return max(1, (int) Settings::get('purchases.group_return_days'));
    }

    /** OS a partir deste dia entram no aviso "OS com custo de peça e nenhuma nota" (vazio = 1º dia do mês). */
    public static function auditSince(string $today): string
    {
        $value = (string) Settings::get('purchases.audit_since');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : substr($today, 0, 7).'-01';
    }

    /**
     * CNPJs da própria oficina: os das unidades ativas (Ajustes > Oficina e numeração).
     *
     * @return list<string>
     */
    public static function workshopCnpjs(): array
    {
        return Unit::query()->where('active', true)->whereNotNull('cnpj')->pluck('cnpj')
            ->map(fn ($cnpj) => Document::normalizeCnpj($cnpj))
            ->filter(fn ($cnpj) => Document::isValidCnpj($cnpj))
            ->unique()->values()->all();
    }

    /** Hoje, no fuso da aplicação (as datas da conferência são só-data). */
    public static function today(): string
    {
        return CarbonImmutable::today()->toDateString();
    }

    /** Motivo escrito de quem libera ou aceita algo grave: pelo menos 10 letras e não "aaaaaaaaaa". */
    public static function validReason(?string $reason): bool
    {
        $text = trim((string) $reason);
        $compact = (string) preg_replace('/\s+/u', '', $text);

        return mb_strlen($text) >= 10 && preg_match('/^(.)\1*$/u', $compact) !== 1;
    }

    /** Perfil de quem fez, como fica gravado (o gestor é quem paga e libera; o atendimento lança). */
    public static function role(?User $user): string
    {
        return $user?->isGestor() ? 'gestor' : 'atendimento';
    }
}
