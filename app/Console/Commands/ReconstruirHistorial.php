<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Services\Nomina\NominaService;
use Illuminate\Console\Command;

/**
 * php artisan reportes:reconstruir-historial [--ejercicio=2026]
 * Verifica la huella de cada recibo autorizado (detecta cambios hechos directamente en la base de datos)
 * y vuelve a armar los acumulados anuales a partir de los recibos. No modifica ningún recibo.
 */
class ReconstruirHistorial extends Command
{
    protected $signature = 'reportes:reconstruir-historial {--ejercicio= : Solo un ejercicio}';

    protected $description = 'Verifica los recibos autorizados y reconstruye los acumulados anuales';

    public function handle(NominaService $nomina): int
    {
        $ejercicio = $this->option('ejercicio') ? (int) $this->option('ejercicio') : null;
        $r = $nomina->reconstruir(Empresa::actual(), $ejercicio);
        $this->info("Recibos revisados: {$r['recibos']} · acumulados reconstruidos: {$r['acumulados']}");
        if ($r['alterados']) {
            $this->error(count($r['alterados']).' recibo(s) cambiaron después de autorizarse (su huella no coincide):');
            $this->table(['Recibo', 'Empleado', 'Periodo'], array_map(fn ($a) => [$a['recibo_id'], $a['empleado'], $a['periodo']], $r['alterados']));

            return self::FAILURE;
        }
        $this->info('Todos los recibos autorizados están íntegros.');

        return self::SUCCESS;
    }
}
