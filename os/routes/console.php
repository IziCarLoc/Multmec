<?php

use App\Models\SystemHeartbeat;
use App\Services\Execution\WorkLog;
use App\Services\Finance\RecurringBills;
use Illuminate\Support\Facades\Schedule;

/*
 * Agendador (cron da Hostinger a cada minuto: `php artisan schedule:run`).
 */

// Batimento: a Saúde do sistema e o `sistema:conferir-deploy` sabem se o cron está vivo.
Schedule::call(fn () => SystemHeartbeat::beat(SystemHeartbeat::SCHEDULER))->everyMinute()->name('batimento');

// Apontamento esquecido aberto: fechado no fim do dia e marcado para o gestor conferir.
Schedule::call(fn () => app(WorkLog::class)->closeForgotten())->dailyAt('22:00')->name('apontamentos-esquecidos');

// Contas fixas: gera no "A pagar" as contas do mês atual e do próximo que faltam (nunca duplica).
Schedule::call(fn () => app(RecurringBills::class)->generateDue())->dailyAt('05:17')->name('contas-fixas');
