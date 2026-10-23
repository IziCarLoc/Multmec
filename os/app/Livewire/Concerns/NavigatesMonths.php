<?php

namespace App\Livewire\Concerns;

use App\Services\Finance\Month;
use Livewire\Attributes\Url;

/**
 * Navegação por mês das listas do Financeiro (como no artefato): ‹ mês › , "Mês atual" e "Ver todos os meses".
 * `$month` vazio = mês corrente; "todos" = sem filtro de mês. Na tela: `<x-finance-month-nav :month="$month" />`.
 */
trait NavigatesMonths
{
    #[Url(as: 'mes', except: '')]
    public string $month = '';

    public function shiftMonth(int $months): void
    {
        $this->month = Month::shift($this->selectedMonth(), $months > 0 ? 1 : -1);
        $this->monthChanged();
    }

    public function setMonth(string $month): void
    {
        $this->month = $month === 'todos' || Month::isValid($month) ? $month : '';
        $this->monthChanged();
    }

    public function selectedMonth(): string
    {
        return Month::isValid($this->month) ? $this->month : Month::current();
    }

    public function allMonths(): bool
    {
        return $this->month === 'todos';
    }

    /** A tela limpa o que depende do mês (computeds). */
    protected function monthChanged(): void {}
}
