<?php

namespace App\Enums;

/**
 * Categoria de uma conta do "A pagar" que não é boleto de peça (o plano de contas enxuto do docs/03). A conta fixa
 * leva a categoria para a conta que gera. Na cascata do mês: folha, fixo e outros descontam; imposto não (o imposto
 * da cascata é o % do faturamento); sócios é o que já foi retirado. Conta sem categoria é de fornecedor de peças
 * (o custo dela já entra pela OS).
 */
enum ExpenseCategory: string
{
    case Folha = 'folha';
    case Fixo = 'fixo';
    case Imposto = 'imposto';
    case Socios = 'socios';
    case Outros = 'outros';

    public function label(): string
    {
        return match ($this) {
            self::Folha => 'Folha e comissões',
            self::Fixo => 'Custos fixos (aluguel, energia, sistema, contador…)',
            self::Imposto => 'Impostos e taxas',
            self::Socios => 'Pró-labore e retirada dos sócios',
            self::Outros => 'Outros',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Folha => 'Folha',
            self::Fixo => 'Fixo',
            self::Imposto => 'Imposto',
            self::Socios => 'Sócios',
            self::Outros => 'Outros',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $category) => [$category->value => $category->label()])->all();
    }
}
