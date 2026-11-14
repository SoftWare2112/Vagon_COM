<?php

namespace App\Services\Hojas;

use App\Exceptions\ReglaNegocioException;
use App\Models\AuditLog;
use App\Models\Hoja;
use App\Models\HojaCelda;
use App\Models\HojaColumna;
use App\Models\HojaCombinada;
use App\Models\HojaImagen;
use App\Models\HojaEstilo;
use App\Models\HojaFila;
use App\Models\HojaPlantilla;
use App\Models\Libro;
use App\Models\User;
use App\Support\Hojas\ReferenciasFormula;
use App\Support\Hojas\ValidadorCelda;
use App\Support\Hojas\ValidadorEstilo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * v8 — Casos de uso de las hojas de cálculo (capa de aplicación).
 *
 * Reglas:
 *  - El servidor NO calcula fórmulas: guarda la fórmula (con ids) y el resultado que calculó el navegador.
 *  - Cada celda tiene `version`. Un cambio que llega con una versión vieja NO se aplica: se devuelve
 *    como conflicto con el valor actual (v9: los libros son personales, pero la misma persona puede
 *    tener la hoja abierta en dos pestañas del navegador).
 *  - v9: cada hoja pertenece a un Libro; el acceso lo revisa AccesoLibros antes de llegar aquí.
 *  - Los cambios de una hoja se aplican en fila (bloqueo de la hoja durante la transacción).
 */
class HojaService
{
    public const FILAS_INICIALES = 100;

    public const COLUMNAS_INICIALES = 26;

    private const ANCHO = 100;

    // ───────────────────────── Crear ─────────────────────────

    /** Crea una pestaña del libro: en blanco o desde una plantilla. */
    public function crear(Libro $libro, string $nombre, User $usuario, ?HojaPlantilla $plantilla = null, int $orden = 1): Hoja
    {
        $hoja = $this->crearDesdeDefinicion($libro, $nombre, $orden, $plantilla?->definicion, $usuario, $plantilla?->id);
        AuditLog::registrar('CREAR', 'Hoja', $hoja->id, null, ['libro' => $libro->id, 'nombre' => $nombre, 'plantilla' => $plantilla?->clave]);

        return $hoja;
    }

    /**
     * Crea una hoja a partir de una definición (plantilla o Excel importado):
     * {columnas: [anchos], filas: n, altos?: [px], estilos: {nombre: estilo}, celdas: [[fila, col, celda]],
     *  combinadas?: [[f1, c1, f2, c2]], imagenes?: [[fila, col, {x, y, w, h, ruta, mime, nombre}]]}
     * Las fórmulas vienen en A1 y se guardan con ids (ReferenciasFormula).
     */
    public function crearDesdeDefinicion(Libro $libro, string $nombre, int $orden, ?array $def, User $usuario, ?int $plantillaId = null): Hoja
    {
        return DB::transaction(function () use ($libro, $nombre, $orden, $def, $usuario, $plantillaId) {
            $anchos = $def['columnas'] ?? array_fill(0, self::COLUMNAS_INICIALES, self::ANCHO);
            $anchos = array_pad($anchos, max(count($anchos), 10), self::ANCHO);   // siempre algunas columnas libres
            $altos = $def['altos'] ?? [];
            $nFilas = max((int) ($def['filas'] ?? self::FILAS_INICIALES), 20, count($altos));

            $hoja = Hoja::create(['libro_id' => $libro->id, 'orden' => $orden, 'nombre' => $nombre, 'user_id' => $libro->user_id,
                'plantilla_id' => $plantillaId, 'editado_por' => $usuario->id]);

            foreach (array_chunk(array_keys($anchos), 500) as $lote) {
                HojaColumna::insert(array_map(fn ($i) => ['hoja_id' => $hoja->id, 'orden' => $i + 1, 'ancho' => (int) $anchos[$i]], $lote));
            }
            foreach (array_chunk(range(0, $nFilas - 1), 1000) as $lote) {
                HojaFila::insert(array_map(fn ($i) => ['hoja_id' => $hoja->id, 'orden' => $i + 1, 'alto' => (int) ($altos[$i] ?? 28)], $lote));
            }

            if ($def) {
                $this->llenar($hoja, $def, $usuario);
            }

            return $hoja;
        });
    }

    private function llenar(Hoja $hoja, array $def, User $usuario): void
    {
        $filaIds = HojaFila::where('hoja_id', $hoja->id)->orderBy('orden')->pluck('id')->all();
        $colIds = HojaColumna::where('hoja_id', $hoja->id)->orderBy('orden')->pluck('id')->all();

        $estilos = [];
        foreach ($def['estilos'] ?? [] as $nombre => $e) {
            $estilos[$nombre] = $this->estiloId($hoja, ValidadorEstilo::normalizar($e));
        }

        $filas = [];
        $ahora = now();
        foreach ($def['celdas'] ?? [] as [$f, $c, $celda]) {
            if (! isset($filaIds[$f], $colIds[$c])) {
                continue;
            }
            if (($celda['t'] ?? '') === 'formula') {
                // Plantilla: el navegador calcula el resultado al abrir. Excel importado: trae el último resultado.
                $celda = ['t' => 'formula', 'fx' => ReferenciasFormula::aInterno($celda['fx'], $filaIds, $colIds),
                    'rt' => $celda['rt'] ?? 'vacio', 'e' => $celda['e'] ?? null] + (array_key_exists('v', $celda) ? ['v' => $celda['v']] : []);
            }
            try {
                $valores = ValidadorCelda::normalizar($celda);
            } catch (InvalidArgumentException) {
                $valores = ValidadorCelda::normalizar($celda['t'] === 'formula' ? ['t' => 'formula', 'fx' => $celda['fx'], 'rt' => 'vacio'] : ['t' => 'vacio']);
            }
            $filas[] = [
                ...$valores, 'hoja_id' => $hoja->id, 'fila_id' => $filaIds[$f], 'columna_id' => $colIds[$c],
                'estilo_id' => isset($celda['e']) ? ($estilos[$celda['e']] ?? null) : null, 'version' => 1,
                'editado_por' => $usuario->id, 'created_at' => $ahora, 'updated_at' => $ahora,
            ];
        }
        foreach (array_chunk($filas, 500) as $lote) {
            HojaCelda::insert($lote);
        }

        foreach ($def['combinadas'] ?? [] as [$f1, $c1, $f2, $c2]) {
            if (isset($filaIds[$f1], $colIds[$c1], $filaIds[$f2], $colIds[$c2])) {
                HojaCombinada::create(['hoja_id' => $hoja->id, 'fila_id' => $filaIds[$f1], 'columna_id' => $colIds[$c1],
                    'fila_fin_id' => $filaIds[$f2], 'columna_fin_id' => $colIds[$c2]]);
            }
        }
        foreach ($def['imagenes'] ?? [] as [$f, $c, $img]) {
            if (isset($filaIds[$f], $colIds[$c], $img['ruta'])) {
                HojaImagen::create(['hoja_id' => $hoja->id, 'fila_id' => $filaIds[$f], 'columna_id' => $colIds[$c],
                    'desplaz_x' => $img['x'] ?? 0, 'desplaz_y' => $img['y'] ?? 0, 'ancho' => $img['w'], 'alto' => $img['h'],
                    'ruta' => $img['ruta'], 'mime' => $img['mime'], 'nombre' => $img['nombre'] ?? null]);
            }
        }
    }

    // ───────────────────────── Leer ─────────────────────────

    /** Todo lo que el editor necesita para dibujar la hoja. */
    public function datos(Hoja $hoja): array
    {
        $hoja->loadMissing(['autor:id,name', 'editor:id,name']);

        return [
            'hoja' => [
                'id' => $hoja->id, 'libro_id' => $hoja->libro_id, 'nombre' => $hoja->nombre, 'version' => $hoja->version,
                'autor' => $hoja->autor?->name, 'editado_por' => $hoja->editor?->name,
                'actualizada' => $hoja->updated_at?->format('d/m/Y H:i'),
            ],
            'columnas' => HojaColumna::where('hoja_id', $hoja->id)->orderBy('orden')->get(['id', 'ancho'])->toArray(),
            'filas' => HojaFila::where('hoja_id', $hoja->id)->orderBy('orden')->get(['id', 'alto'])->toArray(),
            'estilos' => HojaEstilo::where('hoja_id', $hoja->id)->pluck('definicion', 'id'),
            'celdas' => HojaCelda::where('hoja_id', $hoja->id)->get()->map(fn (HojaCelda $c) => $this->aJson($c))->all(),
            // v9: celdas combinadas e imágenes
            'combinadas' => HojaCombinada::where('hoja_id', $hoja->id)->get()->map(fn (HojaCombinada $m) => [
                'id' => $m->id, 'f' => $m->fila_id, 'c' => $m->columna_id, 'f2' => $m->fila_fin_id, 'c2' => $m->columna_fin_id,
            ])->all(),
            'imagenes' => HojaImagen::where('hoja_id', $hoja->id)->get()->map(fn (HojaImagen $i) => [
                'id' => $i->id, 'f' => $i->fila_id, 'c' => $i->columna_id, 'x' => $i->desplaz_x, 'y' => $i->desplaz_y,
                'w' => $i->ancho, 'h' => $i->alto, 'nombre' => $i->nombre, 'url' => "/api/hojas/{$hoja->id}/imagenes/{$i->id}",
            ])->all(),
        ];
    }

    /** Celda en el formato compacto que usa el editor. */
    public function aJson(HojaCelda $c): array
    {
        $tipoValor = $c->tipo === 'formula' ? $c->resultado_tipo : $c->tipo;
        $v = match ($tipoValor) {
            'texto', 'error' => $c->valor_texto,
            'numero' => $c->valor_numero === null ? null : (float) $c->valor_numero,
            'fecha' => $c->valor_fecha ? substr($c->valor_fecha, 0, 10) : null,
            'fecha_hora' => $c->valor_fecha ? str_replace(' ', 'T', substr($c->valor_fecha, 0, 19)) : null,
            'hora' => $c->valor_texto ? substr($c->valor_texto, 0, 5) : null,
            'logico' => $c->valor_logico,
            default => null,
        };

        return array_filter([
            'f' => $c->fila_id, 'c' => $c->columna_id, 't' => $c->tipo, 'v' => $v,
            'fx' => $c->formula, 'rt' => $c->resultado_tipo, 'e' => $c->estilo_id, 'ver' => $c->version,
        ], fn ($x) => $x !== null);
    }

    // ───────────────────────── Escribir celdas y formato ─────────────────────────

    /**
     * @param  list<array{f:int, c:int, t:string, v?:mixed, fx?:string, rt?:string, ver?:int}>  $cambios
     * @param  list<array{celdas: list<array{0:int,1:int}>, estilo: array}>  $formatos
     * @return array{version:int, celdas:list<array>, conflictos:list<array>}
     */
    public function guardar(Hoja $hoja, array $cambios, array $formatos, User $usuario): array
    {
        return DB::transaction(function () use ($hoja, $cambios, $formatos, $usuario) {
            $hoja = Hoja::whereKey($hoja->id)->lockForUpdate()->firstOrFail();
            $filas = HojaFila::where('hoja_id', $hoja->id)->pluck('id')->flip();
            $columnas = HojaColumna::where('hoja_id', $hoja->id)->pluck('id')->flip();
            $existentes = HojaCelda::where('hoja_id', $hoja->id)->get()->keyBy(fn ($c) => $c->fila_id.':'.$c->columna_id);
            $guardadas = $conflictos = [];

            foreach ($cambios as $i => $cambio) {
                $f = (int) ($cambio['f'] ?? 0);
                $c = (int) ($cambio['c'] ?? 0);
                if (! isset($filas[$f], $columnas[$c])) {
                    continue;   // fila o columna borrada por otra persona: se ignora
                }
                $actual = $existentes->get("{$f}:{$c}");
                if ($actual && isset($cambio['ver']) && (int) $cambio['ver'] !== $actual->version) {
                    $conflictos[] = $this->aJson($actual);

                    continue;
                }
                try {
                    $valores = ValidadorCelda::normalizar($cambio);
                } catch (InvalidArgumentException $e) {
                    throw new ReglaNegocioException("Celda {$i}: {$e->getMessage()}", 422, ['codigo' => 'CELDA_INVALIDA']);
                }

                if ($valores['tipo'] === 'vacio' && ! $actual?->estilo_id) {
                    $actual?->delete();
                    $existentes->forget("{$f}:{$c}");
                    $guardadas[] = ['f' => $f, 'c' => $c, 't' => 'vacio', 'ver' => 0];

                    continue;
                }
                $celda = $this->escribir($hoja, $actual, $f, $c, $valores, $usuario);
                $existentes->put("{$f}:{$c}", $celda);
                $guardadas[] = $this->aJson($celda);
            }

            foreach ($formatos as $formato) {
                try {
                    $estilo = ValidadorEstilo::normalizar($formato['estilo'] ?? []);
                } catch (InvalidArgumentException $e) {
                    throw new ReglaNegocioException($e->getMessage(), 422, ['codigo' => 'FORMATO_INVALIDO']);
                }
                $estiloId = $estilo === [] ? null : $this->estiloId($hoja, $estilo);
                foreach ($formato['celdas'] ?? [] as $par) {
                    [$f, $c] = [(int) ($par[0] ?? 0), (int) ($par[1] ?? 0)];
                    if (! isset($filas[$f], $columnas[$c])) {
                        continue;
                    }
                    $actual = $existentes->get("{$f}:{$c}");
                    if (! $actual && $estiloId === null) {
                        continue;
                    }
                    if ($actual && $actual->tipo === 'vacio' && $estiloId === null) {
                        $actual->delete();
                        $existentes->forget("{$f}:{$c}");
                        $guardadas[] = ['f' => $f, 'c' => $c, 't' => 'vacio', 'ver' => 0];

                        continue;
                    }
                    $celda = $actual
                        ? tap($actual)->update(['estilo_id' => $estiloId, 'version' => $actual->version + 1, 'editado_por' => $usuario->id])
                        : $this->escribir($hoja, null, $f, $c, ValidadorCelda::normalizar(['t' => 'vacio']), $usuario, $estiloId);
                    $existentes->put("{$f}:{$c}", $celda);
                    $guardadas[] = $this->aJson($celda);
                }
            }

            $hoja->update(['version' => $hoja->version + 1, 'editado_por' => $usuario->id]);

            return ['version' => $hoja->version, 'celdas' => $guardadas, 'conflictos' => $conflictos,
                'estilos' => HojaEstilo::where('hoja_id', $hoja->id)->pluck('definicion', 'id')];
        });
    }

    private function escribir(Hoja $hoja, ?HojaCelda $actual, int $f, int $c, array $valores, User $usuario, ?int $estiloId = null): HojaCelda
    {
        if ($actual) {
            $actual->update([...$valores, 'version' => $actual->version + 1, 'editado_por' => $usuario->id]);

            return $actual;
        }

        return HojaCelda::create([...$valores, 'hoja_id' => $hoja->id, 'fila_id' => $f, 'columna_id' => $c,
            'estilo_id' => $estiloId, 'version' => 1, 'editado_por' => $usuario->id]);
    }

    /** El mismo formato se guarda una sola vez por hoja. */
    private function estiloId(Hoja $hoja, array $normalizado): ?int
    {
        if ($normalizado === []) {
            return null;
        }

        return HojaEstilo::firstOrCreate(
            ['hoja_id' => $hoja->id, 'huella' => ValidadorEstilo::huella($normalizado)],
            ['definicion' => $normalizado],
        )->id;
    }

    // ───────────────────────── Filas y columnas ─────────────────────────

    /** Inserta filas después de $despuesDe (null = al final, 0 = al inicio). Las celdas y fórmulas no se mueven. */
    public function insertarFilas(Hoja $hoja, ?int $despuesDe, int $cantidad, User $usuario): void
    {
        $this->insertar(HojaFila::class, $hoja, $despuesDe, $cantidad, ['alto' => 28], $usuario);
    }

    public function insertarColumnas(Hoja $hoja, ?int $despuesDe, int $cantidad, User $usuario): void
    {
        $this->insertar(HojaColumna::class, $hoja, $despuesDe, $cantidad, ['ancho' => self::ANCHO], $usuario);
    }

    /** @param class-string<HojaFila|HojaColumna> $modelo */
    private function insertar(string $modelo, Hoja $hoja, ?int $despuesDe, int $cantidad, array $extra, User $usuario): void
    {
        DB::transaction(function () use ($modelo, $hoja, $despuesDe, $cantidad, $extra, $usuario) {
            Hoja::whereKey($hoja->id)->lockForUpdate()->first();
            $base = match (true) {
                $despuesDe === 0 => 0,
                $despuesDe === null => (int) $modelo::where('hoja_id', $hoja->id)->max('orden'),
                default => (int) $modelo::where('hoja_id', $hoja->id)->whereKey($despuesDe)->value('orden'),
            };
            $modelo::where('hoja_id', $hoja->id)->where('orden', '>', $base)->increment('orden', $cantidad);
            $modelo::insert(array_map(fn ($i) => ['hoja_id' => $hoja->id, 'orden' => $base + $i, ...$extra], range(1, $cantidad)));
            $hoja->increment('version', 1, ['editado_por' => $usuario->id]);
        });
    }

    public function eliminarFila(Hoja $hoja, HojaFila $fila, User $usuario): void
    {
        $this->eliminar($hoja, $fila, HojaFila::class, $usuario);
    }

    public function eliminarColumna(Hoja $hoja, HojaColumna $columna, User $usuario): void
    {
        $this->eliminar($hoja, $columna, HojaColumna::class, $usuario);
    }

    private function eliminar(Hoja $hoja, HojaFila|HojaColumna $item, string $modelo, User $usuario): void
    {
        abort_if($item->hoja_id !== $hoja->id, 404);
        abort_if($modelo::where('hoja_id', $hoja->id)->count() <= 1, 422, 'La hoja debe conservar al menos una fila y una columna.');
        DB::transaction(function () use ($hoja, $item, $usuario) {
            $item->delete();   // sus celdas se borran en cascada; las fórmulas que las usaban mostrarán #REF!
            $hoja->increment('version', 1, ['editado_por' => $usuario->id]);
        });
    }

    public function anchoColumna(Hoja $hoja, HojaColumna $columna, int $ancho): void
    {
        abort_if($columna->hoja_id !== $hoja->id, 404);
        $columna->update(['ancho' => max(30, min(800, $ancho))]);
    }

    // ───────────────────────── v9: combinar celdas e imágenes ─────────────────────────

    /** Combina el rango (ids de las esquinas). No se permite encimar otra combinación. */
    public function combinar(Hoja $hoja, int $f1, int $c1, int $f2, int $c2, User $usuario): void
    {
        $posF = HojaFila::where('hoja_id', $hoja->id)->orderBy('orden')->pluck('id')->flip();
        $posC = HojaColumna::where('hoja_id', $hoja->id)->orderBy('orden')->pluck('id')->flip();
        abort_unless(isset($posF[$f1], $posF[$f2], $posC[$c1], $posC[$c2]), 422, 'El rango no pertenece a esta hoja.');
        [$r1, $r2] = [min($posF[$f1], $posF[$f2]), max($posF[$f1], $posF[$f2])];
        [$k1, $k2] = [min($posC[$c1], $posC[$c2]), max($posC[$c1], $posC[$c2])];
        abort_if($r1 === $r2 && $k1 === $k2, 422, 'Selecciona al menos dos celdas para combinar.');

        foreach (HojaCombinada::where('hoja_id', $hoja->id)->get() as $m) {
            $encima = $posF[$m->fila_id] <= $r2 && $posF[$m->fila_fin_id] >= $r1 && $posC[$m->columna_id] <= $k2 && $posC[$m->columna_fin_id] >= $k1;
            abort_if($encima, 422, 'El rango se encima con otras celdas combinadas. Sepáralas primero.');
        }
        $filas = $posF->flip();
        $cols = $posC->flip();
        HojaCombinada::create(['hoja_id' => $hoja->id, 'fila_id' => $filas[$r1], 'columna_id' => $cols[$k1], 'fila_fin_id' => $filas[$r2], 'columna_fin_id' => $cols[$k2]]);
        $hoja->increment('version', 1, ['editado_por' => $usuario->id]);
    }

    public function separar(Hoja $hoja, HojaCombinada $combinada, User $usuario): void
    {
        abort_if($combinada->hoja_id !== $hoja->id, 404);
        $combinada->delete();
        $hoja->increment('version', 1, ['editado_por' => $usuario->id]);
    }

    public function eliminarImagen(Hoja $hoja, HojaImagen $imagen): void
    {
        abort_if($imagen->hoja_id !== $hoja->id, 404);
        Storage::disk(config('reportes.etl.disco', 'local'))->delete($imagen->ruta);
        $imagen->delete();
    }
}
