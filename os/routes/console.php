<?php

use App\Models\SystemHeartbeat;
use Illuminate\Support\Facades\Schedule;

/*
 * Agendador (cron da Hostinger a cada minuto: `php artisan schedule:run`).
 */

// Batimento: a Saúde do sistema e o `sistema:conferir-deploy` sabem se o cron está vivo.
Schedule::call(fn () => SystemHeartbeat::beat(SystemHeartbeat::SCHEDULER))->everyMinute()->name('batimento');
