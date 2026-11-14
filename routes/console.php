<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// v9: vacía la papelera del historial de importaciones (más de 30 días). Requiere el programador de tareas:
//   Windows: Programador de tareas -> cada minuto: php artisan schedule:run   |   Linux: cron * * * * *
Schedule::command('reportes:purgar-historial')->dailyAt('03:15');
