<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// v11: se retiró la tarea programada reportes:purgar-historial (el historial de importaciones de Excel ya no existe).

// v12: respaldo diario de la base de datos (requiere el programador de tareas: php artisan schedule:run cada minuto)
Illuminate\Support\Facades\Schedule::command('reportes:respaldo --motivo=diario')->dailyAt('02:30');
