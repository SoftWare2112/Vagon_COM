<?php

namespace App\Console\Commands;

use App\Models\Carga;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * v9 — Borra definitivamente lo que lleva más de N días en la papelera del historial de importaciones
 * (y su archivo original). Los datos de nómina y los libros NO se tocan.
 * Se programa a diario en routes/console.php.
 */
class PurgarHistorial extends Command
{
    protected $signature = 'reportes:purgar-historial {--dias= : Días en la papelera (por defecto config reportes.libros.dias_papelera)}';

    protected $description = 'Borra definitivamente las importaciones que llevan más de N días en la papelera del historial';

    public function handle(): int
    {
        $dias = (int) ($this->option('dias') ?: config('reportes.libros.dias_papelera', 30));
        $disco = Storage::disk(config('reportes.etl.disco', 'local'));
        $n = 0;
        Carga::onlyTrashed()->where('deleted_at', '<', now()->subDays($dias))->chunkById(100, function ($lote) use ($disco, &$n) {
            foreach ($lote as $carga) {
                if ($carga->ruta_archivo) {
                    $disco->delete($carga->ruta_archivo);
                }
                $carga->forceDelete();   // registros.carga_id y libros.carga_id quedan en null
                $n++;
            }
        });
        $this->info("Importaciones borradas definitivamente: {$n}");

        return self::SUCCESS;
    }
}
