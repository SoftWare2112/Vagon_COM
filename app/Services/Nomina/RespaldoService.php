<?php

namespace App\Services\Nomina;

use App\Exceptions\ReglaNegocioException;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * v12 — Respaldo de la base de datos antes de autorizar una nómina (como sugiere CONTPAQi).
 * Usa mysqldump; en Windows/XAMPP configura su ruta en .env:  MYSQLDUMP_PATH="C:\xampp\mysql\bin\mysqldump.exe"
 * Para restaurar:  mysql -u usuario -p base_de_datos < respaldo.sql
 */
class RespaldoService
{
    public const CARPETA = 'respaldos';

    /** @return array{archivo: string, tamano: int, fecha: string} */
    public function crear(string $motivo = 'manual'): array
    {
        $c = config('database.connections.'.config('database.default'));
        if (($c['driver'] ?? '') !== 'mysql' && ($c['driver'] ?? '') !== 'mariadb') {
            throw new ReglaNegocioException('Los respaldos automáticos solo están disponibles con MySQL.', 422, ['codigo' => 'SIN_MYSQL']);
        }
        $disco = Storage::disk('local');
        $disco->makeDirectory(self::CARPETA);
        $archivo = self::CARPETA.'/respaldo-'.now()->format('Ymd-His').'-'.preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($motivo)).'.sql';
        $destino = $disco->path($archivo);

        $r = Process::timeout(300)->env(['MYSQL_PWD' => (string) ($c['password'] ?? '')])->run([
            config('reportes.respaldo.mysqldump', 'mysqldump'), '--single-transaction', '--routines', '--no-tablespaces',
            '-h', (string) $c['host'], '-P', (string) $c['port'], '-u', (string) $c['username'], '--result-file='.$destino, (string) $c['database'],
        ]);
        if (! $r->successful() || ! is_file($destino) || filesize($destino) === 0) {
            @unlink($destino);
            throw new ReglaNegocioException('No se pudo crear el respaldo. Revisa MYSQLDUMP_PATH en .env. Detalle: '.mb_substr(trim($r->errorOutput()), 0, 200), 500, ['codigo' => 'RESPALDO_FALLO']);
        }
        AuditLog::registrar('RESPALDO', 'BaseDeDatos', null, null, ['archivo' => basename($archivo), 'motivo' => $motivo]);

        return ['archivo' => basename($archivo), 'tamano' => filesize($destino), 'fecha' => now()->format('d/m/Y H:i')];
    }

    /** @return list<array{archivo: string, tamano: int, fecha: string}> */
    public function listar(): array
    {
        $disco = Storage::disk('local');

        return collect($disco->files(self::CARPETA))->filter(fn ($f) => str_ends_with($f, '.sql'))
            ->sortDesc()->take(30)
            ->map(fn ($f) => ['archivo' => basename($f), 'tamano' => $disco->size($f), 'fecha' => date('d/m/Y H:i', $disco->lastModified($f))])->values()->all();
    }

    public function ruta(string $archivo): string
    {
        if (! preg_match('/^respaldo-[\w-]+\.sql$/', $archivo) || ! Storage::disk('local')->exists(self::CARPETA.'/'.$archivo)) {
            throw new ReglaNegocioException('El respaldo no existe.', 404);
        }

        return Storage::disk('local')->path(self::CARPETA.'/'.$archivo);
    }
}
