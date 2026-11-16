<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConceptoNomina;
use App\Models\Empresa;
use App\Models\PeriodoNomina;
use App\Models\Puesto;
use App\Models\TablaFiscal;
use App\Services\Nomina\PeriodoService;
use App\Support\Nomina\ValidadorFiscal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * v11 — Configuración de nómina: empresa, puestos, catálogo de conceptos, tablas fiscales y calendario de periodos.
 * Leer: empleados.ver o nomina.configurar. Cambiar: nomina.configurar (en las rutas).
 */
class NominaConfigController extends Controller
{
    // ───────────── Empresa ─────────────

    public function empresa(): JsonResponse
    {
        return response()->json(Empresa::actual());
    }

    public function guardarEmpresa(Request $request): JsonResponse
    {
        $d = $request->validate([
            'nombre' => ['required', 'string', 'max:120'], 'razon_social' => ['nullable', 'string', 'max:200'], 'rfc' => ['nullable', 'string', 'max:15'],
            'regimen_fiscal' => ['nullable', 'digits:3'], 'registro_patronal' => ['nullable', 'regex:/^[A-Z0-9]{11}$/i'],
            'codigo_postal' => ['nullable', 'digits:5'], 'estado' => ['nullable', 'string', 'max:40'],
            'periodicidad' => ['required', Rule::in(['semanal', 'quincenal', 'mensual'])],
            'divisor_salario_diario' => ['required', 'numeric', 'between:28,31'],
            'dias_aguinaldo' => ['required', 'integer', 'between:15,90'], 'prima_vacacional' => ['required', 'numeric', 'between:25,100'],
            'zona_salario_minimo' => ['required', Rule::in(['general', 'frontera_norte'])],
            // v12: el timbrado del CFDI es opcional; para activarlo el emisor debe tener sus datos fiscales completos
            'timbrado_activo' => ['sometimes', 'boolean'],
        ], [
            'registro_patronal.regex' => 'El registro patronal del IMSS tiene 11 caracteres.',
            'dias_aguinaldo.between' => 'El aguinaldo mínimo de ley es de 15 días.',
            'prima_vacacional.between' => 'La prima vacacional mínima de ley es del 25 %.',
        ]);
        if (! empty($d['timbrado_activo'])) {
            $faltan = array_filter(['rfc' => 'RFC', 'regimen_fiscal' => 'régimen fiscal', 'codigo_postal' => 'código postal', 'registro_patronal' => 'registro patronal'],
                fn ($c) => empty($d[$c]), ARRAY_FILTER_USE_KEY);
            if ($faltan) {
                throw ValidationException::withMessages(['timbrado_activo' => 'Para activar el timbrado captura primero: '.implode(', ', $faltan).'.']);
            }
        }
        if (! empty($d['rfc'])) {
            $d['rfc'] = ValidadorFiscal::normalizar($d['rfc']);
            $error = ValidadorFiscal::rfc($d['rfc'], strlen($d['rfc']) === 12);
            if ($error) {
                throw ValidationException::withMessages(['rfc' => $error]);
            }
        }
        $empresa = Empresa::actual();
        $empresa->update($d);

        return response()->json($empresa);
    }

    // ───────────── Puestos ─────────────

    public function puestos(): JsonResponse
    {
        return response()->json(Puesto::orderBy('nombre')->get(['id', 'nombre']));
    }

    public function crearPuesto(Request $request): JsonResponse
    {
        $p = Puesto::resolver($request->validate(['nombre' => ['required', 'string', 'max:100']])['nombre']);

        return response()->json(['id' => $p->id, 'nombre' => $p->nombre], 201);
    }

    // ───────────── Conceptos ─────────────

    public function conceptos(): JsonResponse
    {
        return response()->json(ConceptoNomina::where('empresa_id', Empresa::actual()->id)->orderBy('orden')->orderBy('clave')->get());
    }

    public function crearConcepto(Request $request): JsonResponse
    {
        $d = $this->validarConcepto($request, null);
        $c = ConceptoNomina::create([...$d, 'empresa_id' => Empresa::actual()->id, 'sistema' => false]);

        return response()->json($c, 201);
    }

    public function actualizarConcepto(Request $request, ConceptoNomina $concepto): JsonResponse
    {
        $d = $this->validarConcepto($request, $concepto);
        if ($concepto->sistema) {
            unset($d['clave'], $d['tipo'], $d['metodo']);   // del sistema: se puede renombrar y activar, no cambiar su cálculo base
        }
        $concepto->update($d);

        return response()->json($concepto->fresh());
    }

    public function eliminarConcepto(ConceptoNomina $concepto): JsonResponse
    {
        abort_if($concepto->sistema, 422, 'Este concepto es del sistema (sueldo, IMSS o ISR) y no se puede eliminar.');
        $concepto->delete();

        return response()->json(['message' => 'Concepto eliminado.']);
    }

    private function validarConcepto(Request $request, ?ConceptoNomina $c): array
    {
        $d = $request->validate([
            'clave' => [$c ? 'sometimes' : 'required', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/i',
                Rule::unique('conceptos_nomina', 'clave')->where('empresa_id', Empresa::actual()->id)->ignore($c?->id)],
            'nombre' => [$c ? 'sometimes' : 'required', 'string', 'max:80'],
            'tipo' => [$c ? 'sometimes' : 'required', Rule::in(['percepcion', 'deduccion'])],
            'clave_sat' => ['nullable', 'regex:/^\d{3}$/'],
            'metodo' => [$c ? 'sometimes' : 'required', Rule::in(['fijo', 'por_evento', 'captura'])],
            'monto' => ['sometimes', 'numeric', 'min:0', 'max:9999999'],
            'porcentaje' => ['sometimes', 'numeric', 'min:0', 'max:1000'],
            'regla' => ['sometimes', 'array'],
            'regla.solo_fin_de_mes' => ['sometimes', 'boolean'], 'regla.sin_faltas' => ['sometimes', 'boolean'], 'regla.sin_retardos' => ['sometimes', 'boolean'],
            'regla.antiguedad_meses_min' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:600'],
            'regla.frecuencia' => ['sometimes', Rule::in(['recurrente', 'unico'])],
            'exencion' => ['sometimes', 'array'],
            'exencion.tipo' => ['required_with:exencion', Rule::in(['ninguna', 'total', 'uma_por_evento', 'uma_tope'])],
            'exencion.umas' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'descripcion' => ['nullable', 'string', 'max:300'],
            'activo' => ['sometimes', 'boolean'],
            'orden' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ], ['clave.regex' => 'La clave solo lleva letras y números (p. ej. P006).', 'clave_sat.regex' => 'La clave SAT tiene 3 dígitos (catálogo c_TipoPercepcion / c_TipoDeduccion).']);
        if (isset($d['clave'])) {
            $d['clave'] = strtoupper($d['clave']);
        }

        return $d;
    }

    // ───────────── Tablas fiscales ─────────────

    public function tablas(): JsonResponse
    {
        return response()->json(TablaFiscal::orderBy('tipo')->orderByDesc('vigente_desde')->get()->map(fn (TablaFiscal $t) => [
            'id' => $t->id, 'tipo' => $t->tipo, 'nombre' => TablaFiscal::TIPOS[$t->tipo] ?? $t->tipo, 'vigente_desde' => $t->vigente_desde->format('Y-m-d'),
            'datos' => $t->datos, 'fuente' => $t->fuente, 'vigente' => $t->vigente_desde->lte(now()) && ! TablaFiscal::where('tipo', $t->tipo)
                ->where('vigente_desde', '>', $t->vigente_desde)->where('vigente_desde', '<=', now())->exists(),
        ]));
    }

    /** Nueva versión de una tabla (p. ej. la UMA del año siguiente). Las anteriores se conservan para recalcular el pasado. */
    public function guardarTabla(Request $request): JsonResponse
    {
        $d = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(TablaFiscal::TIPOS))],
            'vigente_desde' => ['required', 'date_format:Y-m-d'],
            'datos' => ['required', 'array'],
            'fuente' => ['nullable', 'string', 'max:200'],
        ]);
        $this->validarDatosTabla($d['tipo'], $d['datos']);
        $t = TablaFiscal::updateOrCreate(['tipo' => $d['tipo'], 'vigente_desde' => $d['vigente_desde']], ['datos' => $d['datos'], 'fuente' => $d['fuente'] ?? null]);

        return response()->json($t, 201);
    }

    public function eliminarTabla(TablaFiscal $tabla): JsonResponse
    {
        abort_if(TablaFiscal::where('tipo', $tabla->tipo)->count() <= 1, 422, 'Es la única versión de esta tabla; agrega otra antes de borrarla.');
        $tabla->delete();

        return response()->json(['message' => 'Versión eliminada.']);
    }

    private function validarDatosTabla(string $tipo, array $datos): void
    {
        $num = fn ($v) => is_numeric($v) && $v >= 0;
        $ok = match (true) {
            $tipo === 'uma' => $num($datos['diaria'] ?? null) && $num($datos['mensual'] ?? null),
            $tipo === 'salario_minimo' => $num($datos['general'] ?? null),
            $tipo === 'subsidio' => $num($datos['porcentaje'] ?? null) && $num($datos['uma_mensual'] ?? null) && $num($datos['limite_mensual'] ?? null),
            $tipo === 'imss_obrero' => collect($datos)->every(fn ($r) => is_array($r) && isset($r['nombre']) && $num($r['tasa'] ?? null)),
            str_starts_with($tipo, 'isr_') => isset($datos['renglones']) && count($datos['renglones']) >= 2
                && collect($datos['renglones'])->every(fn ($r) => is_array($r) && count($r) === 3 && $num($r[0]) && $num($r[1]) && $num($r[2]) && $r[2] <= 100)
                && collect($datos['renglones'])->pluck(0)->sort()->values()->all() === collect($datos['renglones'])->pluck(0)->values()->all(),
            default => false,
        };
        if (! $ok) {
            throw ValidationException::withMessages(['datos' => 'Los valores de la tabla no son válidos: revisa que todos sean números y que los renglones del ISR vayan de menor a mayor.']);
        }
    }

    // ───────────── Periodos ─────────────

    public function periodos(Request $request): JsonResponse
    {
        $empresa = Empresa::actual();
        $tipo = $request->input('tipo', $empresa->periodicidad);
        $ejercicio = $request->integer('ejercicio', (int) now()->format('Y'));

        return response()->json(PeriodoNomina::where(['empresa_id' => $empresa->id, 'tipo' => $tipo, 'ejercicio' => $ejercicio])->orderBy('numero')->get()
            ->map(fn (PeriodoNomina $p) => [...$p->only(['id', 'tipo', 'ejercicio', 'numero', 'dias', 'fin_de_mes', 'estado']),
                'inicio' => $p->inicio->format('Y-m-d'), 'fin' => $p->fin->format('Y-m-d'), 'fecha_pago' => $p->fecha_pago->format('Y-m-d'), 'etiqueta' => $p->etiqueta()]));
    }

    public function generarPeriodos(Request $request, PeriodoService $servicio): JsonResponse
    {
        $d = $request->validate([
            'tipo' => ['required', Rule::in(['semanal', 'quincenal', 'mensual'])],
            'ejercicio' => ['required', 'integer', 'between:2020,2100'],
            'primer_dia' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $n = $servicio->generar(Empresa::actual(), $d['tipo'], $d['ejercicio'], $d['primer_dia'] ?? null);

        return response()->json(['message' => $n ? "Se generaron {$n} periodos." : 'El calendario ya estaba completo.', 'nuevos' => $n]);
    }

    public function actualizarPeriodo(Request $request, PeriodoNomina $periodo): JsonResponse
    {
        abort_unless($periodo->estado === 'abierto', 422, 'Solo se puede cambiar la fecha de pago de un periodo abierto.');
        $periodo->update($request->validate(['fecha_pago' => ['required', 'date_format:Y-m-d']]));

        return response()->json(['message' => 'Fecha de pago actualizada.']);
    }
}
