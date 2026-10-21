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
use App\Services\Etl\Dto\Periodo as PeriodoDto;
use App\Services\Etl\Dto\ResultadoProcesamiento;
use App\Services\Etl\Sync\EstrategiaFactory;
use App\Support\Texto;
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
                diasMin: (int) config('reportes.etl.periodo_dias_min', 13),
                diasMax: (int) config('reportes.etl.periodo_dias_max', 16),
            );
            $r = $procesador->procesar($hojaExtraida, $anio, $periodoManual);
            $res = new ResultadoProcesamiento($r->mapa, $r->periodo, $r->filas, [...$avisosHoja, ...$r->mensajes], $r->desconocidas);
            if ($res->periodo) {
                $res = $res->conMensajes([...$this->validarPeriodoEnBd($res->periodo), ...$this->validarNominaEnBd($res)]);
            }
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
                    'originales' => $hojaExtraida ? array_map(fn ($t) => Texto::limpiar(is_scalar($t) ? (string) $t : ''), $hojaExtraida->encabezados) : [],
                    'periodo' => $res->periodo?->toArray(),          // incluye ejercicio y número (v5)
                    'desconocidas' => $res->desconocidas,            // columnas a asignar en el asistente
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
            'requiere_confirmacion' => $carga->mensajes()->where('nivel', Mensaje::ADVERTENCIA)->whereIn('codigo', Mensaje::CONFIRMAR)->exists(),
            'impacto' => $impacto,
        ];
    }

    /**
     * @param  bool  $confirmado  RH revisó las advertencias que piden confirmación (Mensaje::CONFIRMAR):
     *                            columnas que faltan, domingos que no caen en domingo, duración rara del periodo...
     */
    public function aplicar(Carga $carga, ModoSincronizacion $modo, User $usuario, bool $confirmado = false): Carga
    {
        if (! $confirmado && $carga->mensajes()->where('nivel', Mensaje::ADVERTENCIA)->whereIn('codigo', Mensaje::CONFIRMAR)->exists()) {
            throw new ReglaNegocioException('Antes de aplicar, revisa y confirma las advertencias marcadas (pueden significar datos que no se cargan o una quincena equivocada).', 422, ['codigo' => 'CONFIRMACION_REQUERIDA']);
        }

        // Lock global: dos usuarios no pueden aplicar cargas al mismo tiempo.
        return Cache::lock('etl:aplicar', 120)->block(15, function () use ($carga, $modo, $usuario) {
            try {
                $resultado = DB::transaction(function () use ($carga, $modo, $usuario) {
                    $carga = Carga::whereKey($carga->id)->lockForUpdate()->firstOrFail();
                    $this->exigirLista($carga);

                    $p = $carga->encabezados['periodo'];
                    $periodo = Periodo::whereDate('fecha_inicio', $p['inicio'])->whereDate('fecha_fin', $p['fin'])->first()
                        ?? Periodo::create([
                            'fecha_inicio' => $p['inicio'], 'fecha_fin' => $p['fin'], 'etiqueta' => $p['etiqueta'],
                            'ejercicio' => $p['ejercicio'] ?? null, 'numero' => $p['numero'] ?? null, 'tipo' => $p['tipo'] ?? null,
                        ]);
                    if ($periodo->numero === null && ($p['numero'] ?? null) !== null) {
                        // Quincena cargada antes de la v5 (sin número): se completa
                        $periodo->update(['ejercicio' => $p['ejercicio'], 'numero' => $p['numero'], 'tipo' => $p['tipo'] ?? null]);
                    }
                    // v6: estructura del archivo de RH (título + encabezados originales) para exportar e imprimir igual
                    $periodo->update(['formato' => $this->formatoDelArchivo($carga, $periodo)]);

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

    /**
     * El archivo debe coincidir con lo que ya está guardado: el periodo 17 de 2026 siempre son las
     * mismas fechas, y unas fechas siempre son el mismo periodo. Si no, es un error de captura.
     *
     * @return list<Mensaje>
     */
    private function validarPeriodoEnBd(PeriodoDto $p): array
    {
        $ini = $p->inicio->format('Y-m-d');
        $fin = $p->fin->format('Y-m-d');
        $fmt = fn ($d) => $d->format('d/m/Y');
        $mensajes = [];

        if ($p->numero !== null) {
            $mismoNumero = Periodo::where('ejercicio', $p->ejercicio)->where('numero', $p->numero)->first();
            if ($mismoNumero && ($mismoNumero->fecha_inicio->format('Y-m-d') !== $ini || $mismoNumero->fecha_fin->format('Y-m-d') !== $fin)) {
                $mensajes[] = Mensaje::error('PERIODO_FECHAS_DISTINTAS', "El periodo {$p->numero} de {$p->ejercicio} ya está registrado del {$fmt($mismoNumero->fecha_inicio)} al {$fmt($mismoNumero->fecha_fin)}, pero este archivo indica del {$fmt($p->inicio)} al {$fmt($p->fin)}. Corrige el encabezado o escribe esas mismas fechas en el asistente.");
            }
        }

        $mismasFechas = Periodo::whereDate('fecha_inicio', $ini)->whereDate('fecha_fin', $fin)->first();
        if ($mismasFechas && $p->numero !== null && $mismasFechas->numero !== null
            && [$mismasFechas->ejercicio, $mismasFechas->numero] !== [$p->ejercicio, $p->numero]) {
            $mensajes[] = Mensaje::error('PERIODO_NUMERO_DISTINTO', "Las fechas del {$fmt($p->inicio)} al {$fmt($p->fin)} ya corresponden al periodo {$mismasFechas->numero} de {$mismasFechas->ejercicio}, pero este archivo dice periodo {$p->numero} de {$p->ejercicio}. Revisa el bloque Ejercicio / Periodo del Excel.");
        }

        // v6: la quincena ya tiene datos -> RH confirma que quiere actualizarla
        // (evita que una quincena nueva con el encabezado sin cambiar pise la anterior)
        if ($mismasFechas && ($n = $mismasFechas->registros()->count()) > 0) {
            $ultima = Carga::with('usuario')->where('periodo_id', $mismasFechas->id)->where('estatus', EstatusCarga::Aplicada)->latest('aplicada_at')->first();
            $cuando = $ultima ? " (última carga el {$ultima->aplicada_at->format('d/m/Y H:i')} por {$ultima->usuario?->name})" : '';
            $mensajes[] = Mensaje::advertencia('PERIODO_YA_CARGADO', "La quincena {$mismasFechas->nombre()} ya tiene {$n} registro(s){$cuando}. Al aplicar se actualizarán. Si es una quincena nueva, revisa el Periodo y las fechas del encabezado.");
        }

        return $mensajes;
    }

    /**
     * v6: el número de nómina continúa entre quincenas (si la anterior terminó en 223, esta empieza en 224).
     *
     * @return list<Mensaje>
     */
    private function validarNominaEnBd(ResultadoProcesamiento $res): array
    {
        $numeros = array_filter(array_map(fn ($f) => $f['datos']['numero_nomina'] ?? null, $res->filas), fn ($n) => $n !== null);
        if ($numeros === [] || $res->periodo === null) {
            return [];
        }
        $anterior = Periodo::whereDate('fecha_fin', '<', $res->periodo->inicio->format('Y-m-d'))->orderByDesc('fecha_fin')->first();
        $ultimo = $anterior?->registros()->max('numero_nomina');
        if ($ultimo === null) {
            return [];
        }
        $primero = min($numeros);
        $esperado = (int) $ultimo + 1;

        return $primero === $esperado ? [] : [Mensaje::advertencia('NOMINA_CONTINUIDAD',
            "La quincena anterior ({$anterior->nombre()}) terminó en el número de nómina {$ultimo}; este archivo empieza en {$primero} (se esperaba {$esperado}).",
            null, 'Número de nómina', null, $primero)];
    }

    /**
     * Columnas del archivo en su orden, con el texto original de cada encabezado y la clave con
     * la que PresentadorFila conoce ese dato ('nombre_completo', 'domingo:2026-10-04', 'valor_periodo',
     * 'extra:bono_transporte'). Columnas sin título se guardan con clave null para respetar la estructura.
     */
    private function formatoDelArchivo(Carga $carga, Periodo $periodo): array
    {
        $enc = $carga->encabezados;
        $mapa = $enc['mapa'] ?? [];
        $originales = $enc['originales'] ?? [];
        $extras = $mapa['extras'] ?? [];
        $columnas = [];

        foreach ($originales as $letra => $texto) {
            if (isset($mapa['campos'][$letra])) {
                $c = $mapa['campos'][$letra];
                $clave = in_array($c, $extras, true) ? "extra:{$c}" : $c;
            } elseif (isset($mapa['domingos'][$letra])) {
                $fecha = $this->fechaDelDia($periodo, (int) $mapa['domingos'][$letra]['dia']);
                $clave = $fecha ? "domingo:{$fecha}" : null;
            } elseif (($mapa['columna_periodo'] ?? null) === $letra) {
                $clave = 'valor_periodo';
            } elseif (in_array($letra, $mapa['sin_encabezado'] ?? [], true)) {
                $clave = null;   // columna vacía entre otras (como la E del archivo de RH)
            } else {
                continue;        // relleno ("Columna1") o columna que RH decidió ignorar
            }
            $columnas[] = ['clave' => $clave, 'etiqueta' => $texto];
        }

        // Quitar columnas vacías del final (formato sobrante a la derecha de la tabla)
        while ($columnas !== [] && end($columnas)['clave'] === null) {
            array_pop($columnas);
        }

        return ['titulo' => $enc['periodo']['titulo'] ?? null, 'columnas' => $columnas];
    }

    private function fechaDelDia(Periodo $periodo, int $dia): ?string
    {
        for ($d = $periodo->fecha_inicio->copy(); $d->lte($periodo->fecha_fin); $d->addDay()) {
            if ($d->day === $dia) {
                return $d->format('Y-m-d');
            }
        }

        return null;
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
