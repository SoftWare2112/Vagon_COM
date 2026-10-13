<?php

namespace App\Services\Etl;

use App\Enums\EstatusCarga;
use App\Enums\ModoSincronizacion;
use App\Exceptions\ArchivoDuplicadoException;
use App\Exceptions\ReglaNegocioException;
use App\Models\Ajuste;
use App\Models\AuditLog;
use App\Models\Carga;
use App\Models\CargaFila;
use App\Models\Columna;
use App\Models\Periodo;
use App\Models\User;
use App\Services\Etl\Dto\Mensaje;
use App\Services\Etl\Dto\ResultadoProcesamiento;
use App\Services\Etl\Sync\EstrategiaFactory;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Orquesta el ETL completo (patrón Facade/Service Layer):
 *   subir()          -> extraer + transformar + validar + staging   (no toca datos reales)
 *   previsualizar()  -> impacto de cada modo
 *   aplicar()        -> transacción + lock + estrategia elegida
 */
class CargaService
{
    public function __construct(
        private readonly ExcelExtractor $extractor,
        private readonly EstrategiaFactory $estrategias,
    ) {}

    /**
     * @param  string|null  $hoja  hoja elegida por el usuario (libros con varias hojas); null = automática
     */
    public function subir(UploadedFile $archivo, User $usuario, int $anio, ?array $periodoManual = null, ?string $hoja = null): Carga
    {
        $sha = hash_file('sha256', $archivo->getRealPath());
        $catalogo = Columna::paraEtl();   // sistema + columnas que RH agregó

        // 1) Extraer la hoja correcta (las demás hojas del libro se ignoran)
        [$hojaExtraida, $avisosHoja, $hojas] = $this->extractor->extraer($archivo->getRealPath(), $catalogo, $hoja);

        if ($hojaExtraida === null) {
            $res = new ResultadoProcesamiento(null, null, [], $avisosHoja);
            $huella = null;
        } else {
            // Un mismo libro puede traer varias quincenas en hojas distintas: el duplicado es archivo + hoja
            $huella = $this->huella($sha, $hojaExtraida->nombre);
            $this->verificarDuplicado($huella, null);

            $procesador = new ProcesadorHoja(
                catalogo: $catalogo,
                filasEsperadas: (int) Ajuste::valor('filas_esperadas', config('reportes.etl.filas_esperadas')),
                maxFaltas: (int) config('reportes.etl.max_faltas'),
            );
            $r = $procesador->procesar($hojaExtraida, $anio, $periodoManual);
            $res = new ResultadoProcesamiento($r->mapa, $r->periodo, $r->filas, [...$avisosHoja, ...$r->mensajes]);
        }

        $contenidoHash = $res->tieneErrores() ? null : $res->contenidoHash();
        if ($contenidoHash) {
            $this->verificarDuplicado(null, $contenidoHash);
        }

        $ruta = $archivo->store(config('reportes.etl.carpeta'), config('reportes.etl.disco'));

        $carga = DB::transaction(function () use ($archivo, $usuario, $sha, $contenidoHash, $res, $ruta, $hojaExtraida, $hojas, $huella) {
            $carga = Carga::create([
                'user_id' => $usuario->id,
                'nombre_original' => mb_substr($archivo->getClientOriginalName(), 0, 255),
                'ruta_archivo' => $ruta,
                'sha256' => $sha,
                'contenido_hash' => $contenidoHash,
                'estatus' => $res->tieneErrores() ? EstatusCarga::ConErrores : EstatusCarga::Lista,
                'encabezados' => [
                    'hoja' => $hojaExtraida?->nombre,
                    'hojas' => $hojas,          // para que el usuario elija otra si hay varias
                    'huella' => $huella,
                    'mapa' => $res->mapa?->toArray(),
                    'periodo' => $res->periodo ? [
                        'inicio' => $res->periodo->inicio->format('Y-m-d'),
                        'fin' => $res->periodo->fin->format('Y-m-d'),
                        'etiqueta' => $res->periodo->etiqueta,
                    ] : null,
                ],
                'filas_total' => count($res->filas),
                'num_errores' => count($res->errores()),
                'num_advertencias' => count($res->advertencias()),
            ]);

            if (! $res->tieneErrores()) {
                foreach (array_chunk($res->filas, 200) as $lote) {
                    CargaFila::insert(array_map(fn ($f) => [
                        'carga_id' => $carga->id,
                        'fila_excel' => $f['fila'],
                        'nombre_normalizado' => $f['datos']['nombre_normalizado'],
                        'fecha_ingreso' => $f['datos']['fecha_ingreso'],
                        'datos' => json_encode($f['datos']),
                    ], $lote));
                }
            }

            foreach (array_chunk($res->mensajes, 200) as $lote) {
                $carga->mensajes()->createMany(array_map(fn (Mensaje $m) => $this->mensajeAFila($m), $lote));
            }

            return $carga;
        });

        AuditLog::registrar('CARGA_SUBIDA', 'Carga', $carga->id, null, ['archivo' => $carga->nombre_original, 'estatus' => $carga->estatus->value]);

        return $carga;
    }

    public function previsualizar(Carga $carga): array
    {
        $this->exigirLista($carga);
        $filas = $carga->filas()->get();
        $p = $carga->encabezados['periodo'];
        $periodo = Periodo::whereDate('fecha_inicio', $p['inicio'])->whereDate('fecha_fin', $p['fin'])->first(); // null = quincena nueva

        $impacto = [];
        foreach (ModoSincronizacion::cases() as $modo) {
            $impacto[$modo->value] = $this->estrategias->para($modo)->previsualizar($filas, $periodo);
        }

        return [
            'periodo' => $carga->encabezados['periodo'],
            'quincena_nueva' => $periodo === null,
            'columnas_ausentes' => $carga->encabezados['mapa']['ausentes'] ?? [],
            'modo_predeterminado' => Ajuste::valor('modo_etl_predeterminado'),
            'impacto' => $impacto,
        ];
    }

    public function aplicar(Carga $carga, ModoSincronizacion $modo, User $usuario): Carga
    {
        // Lock global: dos usuarios no pueden aplicar cargas al mismo tiempo.
        return Cache::lock('etl:aplicar', 120)->block(15, function () use ($carga, $modo, $usuario) {
            try {
                $resultado = DB::transaction(function () use ($carga, $modo, $usuario) {
                    $carga = Carga::whereKey($carga->id)->lockForUpdate()->firstOrFail();
                    $this->exigirLista($carga);

                    $p = $carga->encabezados['periodo'];
                    $periodo = Periodo::firstOrCreate(
                        ['fecha_inicio' => $p['inicio'], 'fecha_fin' => $p['fin']],
                        ['etiqueta' => $p['etiqueta']]
                    );

                    $resumen = $this->estrategias->para($modo)->aplicar($carga, $carga->filas()->get(), $periodo, $usuario->id);

                    $carga->update([
                        'periodo_id' => $periodo->id,
                        'modo' => $modo,
                        'estatus' => EstatusCarga::Aplicada,
                        'sha256_aplicado' => $carga->encabezados['huella'],  // archivo + hoja; UNIQUE en BD
                        'contenido_hash_aplicado' => $carga->contenido_hash, // UNIQUE en BD
                        'resumen' => $resumen,
                        'aplicada_at' => now(),
                    ]);

                    return $carga;
                });
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'aplicado')) {   // violación de UNIQUE: alguien aplicó el mismo archivo
                    $this->verificarDuplicado($carga->encabezados['huella'] ?? null, $carga->contenido_hash);
                }
                $carga->update(['estatus' => EstatusCarga::Fallida]);
                throw $e;
            }

            AuditLog::registrar('CARGA_ETL', 'Carga', $resultado->id, null, ['modo' => $modo->value, 'resumen' => $resultado->resumen]);

            return $resultado;
        });
    }

    public function descartar(Carga $carga): void
    {
        if ($carga->estatus === EstatusCarga::Aplicada) {
            throw new ReglaNegocioException('Una carga aplicada no se puede descartar.');
        }
        $carga->update(['estatus' => EstatusCarga::Descartada]);
        $carga->filas()->delete();
    }

    private function huella(string $sha, string $hoja): string
    {
        return hash('sha256', $sha.'|'.$hoja);
    }

    private function verificarDuplicado(?string $huella, ?string $contenido): void
    {
        if ($huella === null && $contenido === null) {
            return;
        }

        $previa = Carga::with('usuario')
            ->where('estatus', EstatusCarga::Aplicada)
            ->where(fn ($q) => $q
                ->when($huella, fn ($q) => $q->orWhere('sha256_aplicado', $huella))
                ->when($contenido, fn ($q) => $q->orWhere('contenido_hash_aplicado', $contenido)))
            ->first();

        if ($previa) {
            throw new ArchivoDuplicadoException($previa->aplicada_at->format('d/m/Y H:i'), $previa->usuario->name);
        }
    }

    private function exigirLista(Carga $carga): void
    {
        if ($carga->estatus !== EstatusCarga::Lista) {
            throw new ReglaNegocioException("Esta carga no se puede procesar (estatus: {$carga->estatus->value}).");
        }
    }

    private function mensajeAFila(Mensaje $m): array
    {
        $a = $m->toArray();

        return [
            'nivel' => $a['nivel'], 'codigo' => $a['codigo'], 'mensaje' => mb_substr($a['mensaje'], 0, 500),
            'fila' => $a['fila'], 'columna' => $a['columna'] ? mb_substr($a['columna'], 0, 60) : null,
            'celda' => $a['celda'], 'valor_recibido' => $a['valor_recibido'],
        ];
    }
}
