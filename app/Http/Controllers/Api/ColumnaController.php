<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Columna;
use App\Services\Columnas\CambioTipoColumna;
use App\Services\Etl\HeaderMapper;
use App\Support\ConversorTipo;
use App\Support\Texto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Ajustes > Columnas. Insertar una columna en una posición NO altera los datos
 * existentes: se guardan por clave (registros.valores_extra), nunca por posición.
 */
class ColumnaController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Columna::orderBy('orden')->get());
    }

    /** body: {etiqueta, tipo, despues_de?: clave | antes_de?: clave, alias?: []}  (sin ninguno = al final) */
    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'etiqueta' => ['required', 'string', 'max:100'],
            'tipo' => ['sometimes', Rule::in(ConversorTipo::TIPOS)],   // por defecto texto (RH lo cambia después)
            'despues_de' => ['nullable', 'string', 'exists:columnas,clave'],
            'antes_de' => ['nullable', 'string', 'exists:columnas,clave', 'prohibits:despues_de'],
            'alias' => ['nullable', 'array'],
            'alias.*' => ['string', 'max:100'],
        ]);

        $orden = match (true) {
            ! empty($datos['despues_de']) => Columna::where('clave', $datos['despues_de'])->value('orden') + 1,
            ! empty($datos['antes_de']) => (int) Columna::where('clave', $datos['antes_de'])->value('orden'),
            default => (int) Columna::max('orden') + 1,
        };

        $columna = Columna::insertarEn($orden, [
            'clave' => Columna::claveDesde($datos['etiqueta']),
            'etiqueta' => $datos['etiqueta'],
            'tipo' => $datos['tipo'] ?? 'texto',
            'origen' => 'personalizada',
            'alias' => $datos['alias'] ?? [],
        ]);
        AuditLog::registrar('CREAR', 'Columna', $columna->id, null, $columna->only(['clave', 'etiqueta', 'tipo', 'orden']));

        return response()->json($columna, 201);
    }

    /** Renombrar, alias, mostrar/ocultar. El tipo se cambia con cambiarTipo() (convierte los datos guardados). */
    public function update(Request $request, Columna $columna): JsonResponse
    {
        $datos = $request->validate([
            'etiqueta' => ['sometimes', 'string', 'max:100'],
            'alias' => ['sometimes', 'array'],
            'alias.*' => ['string', 'max:100'],
            'visible' => ['sometimes', 'boolean'],
        ]);
        abort_if(isset($datos['visible']) && ! $datos['visible'] && $columna->obligatoria, 422, 'Esta columna es obligatoria y no se puede ocultar.');
        $columna->update($datos);

        return response()->json($columna);
    }

    /**
     * Cambiar el tipo de una columna agregada: body {tipo, simular?: bool, forzar?: bool}
     * Primero se llama con simular=true para mostrar el reporte; luego sin simular para aplicar.
     */
    public function cambiarTipo(Request $request, Columna $columna, CambioTipoColumna $servicio): JsonResponse
    {
        $datos = $request->validate([
            'tipo' => ['required', Rule::in(ConversorTipo::TIPOS)],
            'simular' => ['sometimes', 'boolean'],
            'forzar' => ['sometimes', 'boolean'],
        ]);

        return response()->json($servicio->ejecutar($columna, $datos['tipo'], (bool) ($datos['simular'] ?? false), (bool) ($datos['forzar'] ?? false)));
    }

    /**
     * Mapeo asistido (v5): "esta columna del Excel es Número de faltas". Guarda el encabezado como
     * alias, así la próxima carga lo reconoce sola. body {alias: "# Faltas Q"}
     */
    public function agregarAlias(Request $request, Columna $columna): JsonResponse
    {
        $alias = Texto::limpiar($request->validate(['alias' => ['required', 'string', 'max:100']])['alias']);
        abort_if(! in_array($columna->origen, ['sistema', 'personalizada'], true), 422, 'Esta columna no admite alias.');
        abort_if(Texto::claveEncabezado($alias) === '', 422, 'El encabezado está vacío.');

        $actual = (new HeaderMapper(Columna::paraEtl()))->clasificar($alias);
        abort_if($actual['tipo'] === 'campo' && $actual['clave'] !== $columna->clave, 422,
            "'{$alias}' ya se reconoce como otra columna.");

        $lista = $columna->alias ?? [];
        if (! in_array($alias, $lista, true)) {
            $antes = $lista;
            $lista[] = $alias;
            $columna->update(['alias' => $lista]);
            AuditLog::registrar('EDITAR', 'Columna', $columna->id, ['alias' => $antes], ['alias' => $lista]);
        }

        return response()->json($columna);
    }

    /** Mover a otra posición: body {orden} */
    public function mover(Request $request, Columna $columna): JsonResponse
    {
        $nuevo = (int) $request->validate(['orden' => ['required', 'integer', 'min:1']])['orden'];

        DB::transaction(function () use ($columna, $nuevo) {
            $nuevo = min($nuevo, (int) Columna::max('orden'));
            $actual = $columna->orden;
            if ($nuevo < $actual) {
                Columna::whereBetween('orden', [$nuevo, $actual - 1])->increment('orden');
            } elseif ($nuevo > $actual) {
                Columna::whereBetween('orden', [$actual + 1, $nuevo])->decrement('orden');
            }
            $columna->update(['orden' => $nuevo]);
        });

        return $this->index();
    }

    /** Solo personalizadas; se DESACTIVA: los datos ya capturados se conservan y puede reactivarse. */
    public function destroy(Columna $columna): JsonResponse
    {
        abort_if($columna->origen !== 'personalizada', 422, 'Las columnas del sistema no se eliminan; puedes ocultarlas.');
        $columna->update(['activa' => ! $columna->activa]);

        return response()->json($columna);
    }
}
