<?php

namespace App\Console\Commands;

use App\Services\Nomina\RespaldoService;
use Illuminate\Console\Command;

/**
 * php artisan reportes:respaldo            Respaldo completo de la base de datos (storage/app/private/respaldos)
 * Programarlo diario: Programador de tareas de Windows → php artisan schedule:run cada minuto (ver routes/console.php).
 */
class Respaldo extends Command
{
    protected $signature = 'reportes:respaldo {--motivo=manual : Etiqueta del archivo}';

    protected $description = 'Crea un respaldo de la base de datos con mysqldump';

    public function handle(RespaldoService $respaldos): int
    {
        $r = $respaldos->crear((string) $this->option('motivo'));
        $this->info("Respaldo creado: {$r['archivo']} (".number_format($r['tamano'] / 1024, 1).' KB)');
        $this->line('Para restaurar: mysql -u USUARIO -p BASE_DE_DATOS < storage/app/private/respaldos/'.$r['archivo']);

        return self::SUCCESS;
    }
}
