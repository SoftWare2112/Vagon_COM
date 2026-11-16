<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Empleado;
use App\Models\NominaRecibo;
use App\Models\TablaFiscal;
use App\Services\Empleados\EmpleadoService;
use App\Services\Nomina\SimuladorNomina;
use App\Support\Nomina\Prestaciones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** v11 — Expediente de empleados: alta manual, edición, baja, contratos, salarios y simulación de nómina. */
class EmpleadoController extends Controller
{
    public function __construct(private readonly EmpleadoService $servicio) {}

    /** ?q (nombre, número, RFC) &estado=activo|baja|todos &puesto_id &incompletos=1 */
    public function index(Request $request): JsonResponse
    {
        $q = Empleado::query()->with('puesto:id,nombre')
            ->when($request->input('estado', 'activo') !== 'todos', fn ($q) => $q->where('estado', $request->input('estado', 'activo')))
            ->when($request->filled('puesto_id'), fn ($q) => $q->where('puesto_id', $request->integer('puesto_id')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $t = '%'.addcslashes((string) $request->input('q'), '%_\\').'%';
                $q->where(fn ($q) => $q->where('nombre_completo', 'like', $t)->orWhere('numero_empleado', 'like', $t)->orWhere('rfc', 'like', $t));
            })
            ->when($request->boolean('incompletos'), fn ($q) => $q->where(fn ($q) => $q->whereNull('rfc')->orWhereNull('nss')->orWhereNull('curp')->orWhereNull('salario_mensual')))
            ->orderBy('apellido_paterno')->orderBy('nombre_completo');

        return response()->json($q->get()->map(fn (Empleado $e) => $this->resumen($e)));
    }

    public function store(Request $request): JsonResponse
    {
        $e = $this->servicio->alta($this->validar($request, true), $request->user());

        return response()->json($this->detalle($e->fresh()), 201);
    }

    public function show(Empleado $empleado): JsonResponse
    {
        return response()->json($this->detalle($empleado));
    }

    public function update(Request $request, Empleado $empleado): JsonResponse
    {
        $this->servicio->actualizar($empleado, $this->validar($request, false, $empleado), $request->user());

        return response()->json($this->detalle($empleado->fresh()));
    }

    public function baja(Request $request, Empleado $empleado): JsonResponse
    {
        $d = $request->validate(['fecha' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$empleado->fecha_ingreso->format('Y-m-d')], 'motivo' => ['required', 'string', 'max:200']]);
        $this->servicio->baja($empleado, $d['fecha'], $d['motivo']);

        return response()->json($this->detalle($empleado->fresh()));
    }

    public function reactivar(Request $request, Empleado $empleado): JsonResponse
    {
        $d = $request->validate(['fecha' => ['nullable', 'date_format:Y-m-d']]);
        $this->servicio->reactivar($empleado, $d['fecha'] ?? null);

        return response()->json($this->detalle($empleado->fresh()));
    }

    public function salario(Request $request, Empleado $empleado): JsonResponse
    {
        $d = $request->validate(['salario_mensual' => ['required', 'numeric', 'min:1', 'max:9999999'], 'desde' => ['required', 'date_format:Y-m-d'], 'motivo' => ['nullable', 'string', 'max:200']]);
        $this->servicio->cambiarSalario($empleado, (float) $d['salario_mensual'], $d['desde'], $d['motivo'] ?? null, $request->user());

        return response()->json($this->detalle($empleado->fresh()));
    }

    public function contrato(Request $request, Empleado $empleado): JsonResponse
    {
        $d = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(Empleado::CONTRATOS))],
            'inicio' => ['required', 'date_format:Y-m-d'],
            'fin' => ['nullable', 'required_unless:tipo,indeterminado', 'date_format:Y-m-d', 'after:inicio'],
            'notas' => ['nullable', 'string', 'max:200'],
        ], ['fin.required_unless' => 'Indica cuándo termina el contrato (solo el indeterminado no tiene fecha de fin).']);
        $this->servicio->nuevoContrato($empleado, $d['tipo'], $d['inicio'], $d['fin'] ?? null, $d['notas'] ?? null, $request->user());

        return response()->json($this->detalle($empleado->fresh()));
    }

    /** "¿Cuánto le toca?": nómina de un periodo sin guardar. ?fecha &faltas &retardos &domingos */
    public function simular(Request $request, Empleado $empleado, SimuladorNomina $simulador): JsonResponse
    {
        $d = $request->validate(['fecha' => ['nullable', 'date_format:Y-m-d'], 'faltas' => ['nullable', 'integer', 'min:0', 'max:31'],
            'retardos' => ['nullable', 'integer', 'min:0', 'max:31'], 'domingos' => ['nullable', 'integer', 'min:0', 'max:5']]);

        return response()->json($simulador->simular($empleado, $d));
    }

    /** Avisos para Inicio: contratos por vencer, cumplimiento de 3 meses, aniversarios y expedientes incompletos. */
    public function alertas(): JsonResponse
    {
        $hoy = now()->startOfDay();
        $limite = $hoy->copy()->addDays(15);
        $alertas = [];
        foreach (Empleado::with('puesto:id,nombre')->where('estado', 'activo')->get() as $e) {
            $base = ['empleado_id' => $e->id, 'nombre' => $e->nombre_completo, 'numero' => $e->numero_empleado];
            if ($e->contrato_fin && $e->contrato_fin->between($hoy->copy()->subDays(30), $limite)) {
                $vencido = $e->contrato_fin->lt($hoy);
                $alertas[] = [...$base, 'tipo' => 'contrato', 'nivel' => $vencido ? 'error' : 'advertencia', 'fecha' => $e->contrato_fin->format('Y-m-d'),
                    'texto' => 'Su contrato '.(Empleado::CONTRATOS[$e->tipo_contrato] ?? $e->tipo_contrato)
                        .($vencido ? ' venció el ' : ' vence el ').$e->contrato_fin->format('d/m/Y').'. Registra el siguiente contrato.'];
            }
            $tres = $e->fecha_ingreso->copy()->addMonths(3);
            if ($tres->between($hoy, $limite)) {
                $alertas[] = [...$base, 'tipo' => 'tres_meses', 'nivel' => 'info', 'fecha' => $tres->format('Y-m-d'), 'texto' => 'Cumple 3 meses el '.$tres->format('d/m/Y').': empieza a recibir el bono de 3 meses.'];
            }
            $aniv = $e->fecha_ingreso->copy()->year($hoy->year);
            if ($aniv->lt($hoy)) {
                $aniv->addYear();
            }
            $anios = $aniv->year - $e->fecha_ingreso->year;
            if ($anios >= 1 && $aniv->between($hoy, $limite)) {
                $alertas[] = [...$base, 'tipo' => 'aniversario', 'nivel' => 'info', 'fecha' => $aniv->format('Y-m-d'),
                    'texto' => "Cumple {$anios} año(s) el ".$aniv->format('d/m/Y').': le corresponden '.Prestaciones::diasVacaciones($anios).' días de vacaciones y su SDI cambia.'];
            }
            if ($faltan = $e->faltantes()) {
                $alertas[] = [...$base, 'tipo' => 'incompleto', 'nivel' => 'advertencia', 'fecha' => null, 'texto' => 'Expediente incompleto: falta '.implode(', ', $faltan).'.'];
            }
        }
        // v12: las tablas fiscales NO se actualizan solas; se avisa cuando falta la del año en curso
        $anio = (int) $hoy->format('Y');
        foreach (['isr_quincenal' => ['Tarifa de ISR', '01-01'], 'uma' => ['UMA', '02-01'], 'salario_minimo' => ['Salario mínimo', '01-01']] as $tipo => [$nombre, $desde]) {
            if ($hoy->format('m-d') >= $desde && ! TablaFiscal::where('tipo', $tipo)->where('vigente_desde', '>=', "{$anio}-{$desde}")->exists()) {
                $alertas[] = ['empleado_id' => null, 'nombre' => 'Tablas fiscales', 'numero' => null, 'tipo' => 'tablas', 'nivel' => 'error', 'fecha' => null,
                    'texto' => "No se ha capturado la {$nombre} de {$anio}. La nómina se está calculando con la del año anterior: agrega la versión nueva en Configuración de nómina → Tablas fiscales."];
            }
        }
        usort($alertas, fn ($a, $b) => [['error' => 0, 'advertencia' => 1, 'info' => 2][$a['nivel']], $a['fecha'] ?? '9'] <=> [['error' => 0, 'advertencia' => 1, 'info' => 2][$b['nivel']], $b['fecha'] ?? '9']);

        return response()->json($alertas);
    }

    // ───────────────────────── auxiliares ─────────────────────────

    private function validar(Request $request, bool $nuevo, ?Empleado $e = null): array
    {
        $req = $nuevo ? 'required' : 'sometimes';

        return $request->validate([
            'numero_empleado' => ['nullable', 'string', 'max:12', Rule::unique('empleados', 'numero_empleado')->ignore($e?->id)],
            'nombres' => [$req, 'string', 'max:80'],
            'apellido_paterno' => [$req, 'string', 'max:60'],
            'apellido_materno' => ['nullable', 'string', 'max:60'],
            'rfc' => [$req, 'string', 'max:15'],
            'curp' => ['nullable', 'string', 'max:20'],
            'nss' => [$req, 'string', 'max:15'],
            'codigo_postal' => ['nullable', 'digits:5'],
            'email' => ['nullable', 'email', 'max:120'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'fecha_ingreso' => [$req, 'date_format:Y-m-d', 'before_or_equal:'.now()->addMonths(3)->toDateString()],
            'puesto_id' => [$req, 'integer', 'exists:puestos,id'],
            'departamento' => ['nullable', 'string', 'max:80'],
            'salario_mensual' => [$nuevo ? 'required' : 'prohibited', 'numeric', 'min:1', 'max:9999999'],
            'periodicidad' => ['nullable', Rule::in(['semanal', 'quincenal', 'mensual'])],
            'tipo_contrato' => [$nuevo ? 'required' : 'prohibited', Rule::in(array_keys(Empleado::CONTRATOS))],
            'contrato_fin' => [$nuevo ? 'nullable' : 'prohibited', 'date_format:Y-m-d', 'after_or_equal:fecha_ingreso'],
            'jornada_horas' => ['nullable', 'numeric', 'min:1', 'max:24'],
            'dias_descanso' => ['nullable', 'array', 'max:6'],
            'dias_descanso.*' => ['integer', 'between:0,6'],
            'banco' => ['nullable', 'string', 'max:40'],
            'clabe' => ['nullable', 'string', 'max:20'],
            'notas' => ['nullable', 'string', 'max:2000'],
            // v12: aceptar un dato real que no pasa el dígito verificador (con motivo)
            'omitir_validacion' => ['nullable', 'array'],
            'omitir_validacion.*' => [Rule::in(['rfc', 'curp', 'nss', 'clabe'])],
            'motivo_omision' => ['nullable', 'string', 'max:300'],
        ], [
            'salario_mensual.prohibited' => 'El salario se cambia con "Cambiar salario" para conservar el historial.',
            'tipo_contrato.prohibited' => 'El contrato se cambia con "Nuevo contrato" para conservar el historial.',
            'contrato_fin.prohibited' => 'El contrato se cambia con "Nuevo contrato" para conservar el historial.',
        ]);
    }

    private function resumen(Empleado $e): array
    {
        return [
            'id' => $e->id, 'numero_empleado' => $e->numero_empleado, 'nombre' => $e->nombre_completo, 'rfc' => $e->rfc,
            'puesto' => $e->puesto?->nombre, 'salario_mensual' => $e->salario_mensual, 'periodicidad' => $e->periodicidad,
            'tipo_contrato' => $e->tipo_contrato, 'contrato_fin' => $e->contrato_fin?->format('Y-m-d'),
            'fecha_ingreso' => $e->fecha_ingreso->format('Y-m-d'), 'estado' => $e->estado, 'faltantes' => $e->faltantes(),
        ];
    }

    private function detalle(Empleado $e): array
    {
        $e->loadMissing(['puesto:id,nombre', 'contratos', 'salarios', 'movimientos.autor:id,name']);
        $hoy = now();
        $anios = Prestaciones::antiguedadAnios($e->fecha_ingreso, $hoy);
        $divisor = (float) ($e->empresa?->divisor_salario_diario ?? 30);
        $sd = $e->salario_mensual ? $e->salario_mensual / $divisor : null;
        $factor = Prestaciones::factorIntegracion($anios);

        return [
            ...$this->resumen($e),
            ...$e->only(['nombres', 'apellido_paterno', 'apellido_materno', 'curp', 'nss', 'codigo_postal', 'email', 'telefono', 'puesto_id',
                'departamento', 'jornada_horas', 'dias_descanso', 'banco', 'clabe', 'motivo_baja', 'notas']),
            'fecha_baja' => $e->fecha_baja?->format('Y-m-d'),
            'calculos' => [
                'salario_diario' => $sd ? round($sd, 2) : null, 'factor_integracion' => $factor, 'sdi' => $sd ? Prestaciones::sdi($sd, $factor) : null,
                'antiguedad_anios' => $anios, 'antiguedad_meses' => Prestaciones::antiguedadMeses($e->fecha_ingreso, $hoy),
                'dias_vacaciones' => Prestaciones::diasVacaciones(max(1, $anios)),   // en el primer año: los que tendrá al cumplirlo
                'vacaciones_desde' => $e->fecha_ingreso->copy()->addYears(max(1, $anios))->format('Y-m-d'),
                'aguinaldo_dias' => Prestaciones::diasAguinaldoProporcionales($e->fecha_ingreso, (int) $hoy->format('Y')),
            ],
            'contratos' => $e->contratos->map(fn ($c) => ['id' => $c->id, 'tipo' => $c->tipo, 'inicio' => $c->inicio->format('Y-m-d'),
                'fin' => $c->fin?->format('Y-m-d'), 'notas' => $c->notas])->values(),
            'salarios' => $e->salarios->map(fn ($s) => ['id' => $s->id, 'salario_mensual' => $s->salario_mensual, 'desde' => $s->desde->format('Y-m-d'),
                'motivo' => $s->motivo])->values(),
            // v12: trayectoria, validaciones aceptadas con motivo y recibos autorizados
            'movimientos' => $e->movimientos->map(fn ($m) => ['id' => $m->id, 'tipo' => $m->tipo, 'fecha' => $m->fecha->format('Y-m-d'),
                'descripcion' => $m->descripcion, 'por' => $m->autor?->name])->values(),
            'validaciones_omitidas' => (object) ($e->validaciones_omitidas ?? []),
            'recibos' => NominaRecibo::with('periodo')->where(['empleado_id' => $e->id, 'estado' => 'autorizado'])
                ->orderByDesc('ejercicio')->orderByDesc('periodo_id')->limit(48)->get()
                ->map(fn ($r) => ['id' => $r->id, 'ejercicio' => $r->ejercicio, 'numero' => $r->periodo->numero, 'tipo' => $r->periodo->tipo,
                    'etiqueta' => $r->periodo->etiqueta(), 'fecha_pago' => $r->periodo->fecha_pago->format('Y-m-d'), 'neto' => $r->neto, 'uuid' => $r->uuid])->values(),
        ];
    }
}
