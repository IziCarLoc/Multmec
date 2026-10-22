<?php

namespace App\Enums;

/**
 * Tipo da foto (ou vídeo) da OS. O rótulo vai no carimbo ("OS 1190  ABC1D23  ENTRADA") e a cor da marca do
 * carimbo vem de App\Services\Media\PhotoStamper::KIND_COLORS.
 */
enum MediaKind: string
{
    case Entrada = 'entrada';
    case Painel = 'painel';
    case Avaria = 'avaria';
    case PecaVelha = 'peca_velha';
    case PecaNova = 'peca_nova';
    case Roda = 'roda';
    case Lacre = 'lacre';
    case Saida = 'saida';
    case NaoConformidade = 'nao_conformidade';
    case Video = 'video';
    case Geral = 'geral';

    /** Rótulo do carimbo, em maiúsculas. */
    public function stampLabel(): string
    {
        return match ($this) {
            self::Entrada => 'ENTRADA',
            self::Painel => 'PAINEL',
            self::Avaria => 'AVARIA',
            self::PecaVelha => 'PEÇA VELHA',
            self::PecaNova => 'PEÇA NOVA',
            self::Roda => 'RODA',
            self::Lacre => 'LACRE',
            self::Saida => 'SAÍDA',
            self::NaoConformidade => 'NÃO CONFORMIDADE',
            self::Video => 'VÍDEO',
            self::Geral => 'GERAL',
        };
    }

    public function label(): string
    {
        return mb_convert_case(mb_strtolower($this->stampLabel()), MB_CASE_TITLE);
    }
}
