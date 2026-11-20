<?php

namespace App\Http\Controllers\Api;

use App\Enums\Permiso;
use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\NominaRecibo;
use App\Models\PeriodoNomina;
use App\Services\Nomina\Indicadores;
use App\Services\Nomina\NominaService;
use App\Services\Nomina\ReportesNomina;
use App\Services\Nomina\RespaldoService;
use App\Services\Nomina\EnvioRecibos;
use App\Services\Nomina\RecibosPdf;
use App\Models\ReciboEnvio;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * v12 — Nómina del periodo (captura → pre-nómina → autorización), historial, sobre-recibos, reportes,
 * respaldos e indicadores. Controlador delgado: la lógica vive en Services/Nomina.
 */
class NominaController extends Controller
{
    public function __construct(private readonly NominaService $nomina) {}

    /** Periodos del ejercicio con su estado y totales (historial de nóminas). */
    public function historial(Request $request): JsonResponse
    {
        $d = $request->validate(['ejercicio' => ['required', 'integer', 'between:2000,2100'], 'tipo' => ['required', Rule::in(['semanal', 'quincenal', 'mensual', 'especial'])]]);
        $periodos = PeriodoNomina::with('autorizador:id,name')->where(['empresa_id' => Empresa::actual()->id, 'tipo' => $d['tipo'], 'ejercicio' => $d['ejercicio']])
            ->withCount('recibos')->withSum('recibos as neto', 'neto')->withSum('recibos as percepciones', 'percepciones')
            ->withCount(['recibos as timbrados' => fn ($q) => $q->whereNotNull('uuid')])->orderBy('numero')->get();

        return response()->json($periodos->map(fn (PeriodoNomina $p) => [...$this->nomina->periodoJson($p),
            'recibos' => $p->recibos_count, 'timbrados' => $p->timbrados, 'neto' => round((float) $p->neto, 2), 'percepciones' => round((float) $p->percepciones, 2)]));
    }

    public function captura(Request $request, PeriodoNomina $periodo): JsonResponse
    {
        abort_unless($request->user()->can(Permiso::NominaVer->value) || $request->user()->can(Permiso::NominaCalcular->value), 403);

        return response()->json($this->nomina->captura($periodo));
    }

    public function incidencias(Request $request, PeriodoNomina $periodo): JsonResponse
    {
        $d = $request->validate([
            'filas' => ['required', 'array', 'min:1', 'max:500'],
            'filas.*.empleado_id' => ['required', 'integer'],
            'filas.*.faltas' => ['nullable', 'integer', 'between:0,31'],
            'filas.*.retardos' => ['nullable', 'integer', 'between:0,31'],
            'filas.*.domingos' => ['nullable', 'integer', 'between:0,5'],
            'filas.*.capturados' => ['nullable', 'array'],
            'filas.*.capturados.*' => ['nullable', 'numeric', 'between:0,999999'],
            'filas.*.notas' => ['nullable', 'string', 'max:200'],
        ]);
        $this->nomina->guardarIncidencias($periodo, $d['filas'], $request->user());

        return response()->json($this->nomina->captura($periodo->fresh()));
    }

    public function calcular(Request $request, PeriodoNomina $periodo): JsonResponse
    {
        return response()->json($this->nomina->calcular($periodo, $request->user()));
    }

    public function autorizar(Request $request, PeriodoNomina $periodo): JsonResponse
    {
        $request->validate(['confirmar' => ['accepted']], ['confirmar.accepted' => 'Confirma que revisaste la pre-nómina.']);
        $this->nomina->autorizar($periodo, $request->user());

        return response()->json(['message' => 'Nómina autorizada. Los recibos quedaron guardados en el historial y son de solo lectura.',
            'captura' => $this->nomina->captura($periodo->fresh())]);
    }

    public function reabrir(Request $request, PeriodoNomina $periodo): JsonResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'min:10', 'max:300']]);
        $this->nomina->reabrir($periodo, $d['motivo'], $request->user());

        return response()->json(['message' => 'Periodo reabierto: los recibos volvieron a pre-nómina. Corrige, calcula y autoriza de nuevo.',
            'captura' => $this->nomina->captura($periodo->fresh())]);
    }

    /** Recibos autorizados de un empleado (para el filtro ejercicio/periodo del sobre-recibo). */
    public function recibos(Request $request): JsonResponse
    {
        $d = $request->validate(['empleado_id' => ['required', 'integer', 'exists:empleados,id']]);

        return response()->json(NominaRecibo::with('periodo')->where(['empleado_id' => $d['empleado_id'], 'estado' => 'autorizado'])
            ->orderByDesc('ejercicio')->orderByDesc('periodo_id')->get()
            ->map(fn ($r) => ['id' => $r->id, 'ejercicio' => $r->ejercicio, 'numero' => $r->periodo->numero, 'tipo' => $r->periodo->tipo,
                'etiqueta' => $r->periodo->etiqueta(), 'fecha_pago' => $r->periodo->fecha_pago->format('Y-m-d'), 'neto' => $r->neto, 'uuid' => $r->uuid]));
    }

    public function recibo(NominaRecibo $recibo): JsonResponse
    {
        return response()->json($this->nomina->reciboJson($recibo));
    }

    public function listaDeRaya(PeriodoNomina $periodo, ReportesNomina $reportes): JsonResponse
    {
        return response()->json($reportes->listaDeRaya($periodo));
    }

    public function acumulados(Request $request, ReportesNomina $reportes): JsonResponse
    {
        $d = $request->validate(['ejercicio' => ['required', 'integer', 'between:2000,2100'], 'tipo' => ['required', Rule::in(['semanal', 'quincenal', 'mensual', 'especial'])],
            'empleado_id' => ['nullable', 'integer', 'exists:empleados,id']]);

        return response()->json($reportes->acumulados(Empresa::actual(), (int) $d['ejercicio'], $d['tipo'], isset($d['empleado_id']) ? (int) $d['empleado_id'] : null));
    }

    public function indicadores(Request $request, Indicadores $indicadores): JsonResponse
    {
        $d = $request->validate(['ejercicio' => ['required', 'integer', 'between:2000,2100'], 'agrupar' => ['nullable', Rule::in(['quincena', 'mes'])]]);
        $r = $indicadores->calcular(Empresa::actual(), (int) $d['ejercicio'], $d['agrupar'] ?? 'quincena');
        if (! $request->user()->can(Permiso::NominaVer->value)) {
            // Quien solo ve indicadores no ve montos de la nómina (solo conteos: faltas, domingos, altas, bajas)
            $r['serie'] = array_map(fn ($s) => [...$s, 'neto' => null, 'prima_dominical' => null, 'bonos' => (object) []], $r['serie']);
            $r['bonos'] = [];
            $r['totales']['bonos'] = null;
        }

        return response()->json($r);
    }

    public function respaldos(RespaldoService $respaldos): JsonResponse
    {
        return response()->json($respaldos->listar());
    }

    public function crearRespaldo(Request $request, RespaldoService $respaldos): JsonResponse
    {
        $d = $request->validate(['motivo' => ['nullable', 'string', 'max:60']]);

        return response()->json($respaldos->crear($d['motivo'] ?? 'manual'), 201);
    }

    public function descargarRespaldo(string $archivo, RespaldoService $respaldos): BinaryFileResponse
    {
        return response()->download($respaldos->ruta($archivo), $archivo, ['Content-Type' => 'application/sql']);
    }

    // ───────────── v13: aguinaldo, recibos en PDF y por correo ─────────────

    public function crearAguinaldo(Request $request): JsonResponse
    {
        $d = $request->validate(['ejercicio' => ['required', 'integer', 'between:2000,2100'], 'fecha_pago' => ['required', 'date_format:Y-m-d']]);
        $p = $this->nomina->crearAguinaldo(Empresa::actual(), (int) $d['ejercicio'], $d['fecha_pago'], $request->user());

        return response()->json($this->nomina->periodoJson($p), 201);
    }

    public function pdf(NominaRecibo $recibo, RecibosPdf $pdf): Response
    {
        return response($pdf->generar([$recibo]), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.RecibosPdf::nombreArchivo($recibo).'"']);
    }

    /** Todos los recibos del periodo en un solo PDF (uno por hoja), para imprimir y firmar. */
    public function pdfPeriodo(PeriodoNomina $periodo, RecibosPdf $pdf): Response
    {
        $recibos = NominaRecibo::where('periodo_id', $periodo->id)->orderBy('nombre')->get();
        abort_if($recibos->isEmpty(), 404, 'El periodo no tiene recibos calculados.');
        $nombre = 'recibos-'.$periodo->ejercicio.'-'.($periodo->especial() ? 'aguinaldo' : str_pad((string) $periodo->numero, 2, '0', STR_PAD_LEFT)).'.pdf';

        return response($pdf->generar($recibos), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$nombre.'"']);
    }

    public function enviarRecibo(Request $request, NominaRecibo $recibo, EnvioRecibos $envio): JsonResponse
    {
        $r = $envio->enviar($recibo, $request->user());

        return response()->json($r, $r['estado'] === 'error' ? 502 : 200);
    }

    public function enviarPeriodo(Request $request, PeriodoNomina $periodo, EnvioRecibos $envio): JsonResponse
    {
        return response()->json($envio->periodo($periodo, $request->user()));
    }

    /** Último envío de cada recibo del periodo (para mostrar «enviado el…»). */
    public function envios(PeriodoNomina $periodo): JsonResponse
    {
        $ids = NominaRecibo::where('periodo_id', $periodo->id)->pluck('id');

        return response()->json(ReciboEnvio::whereIn('recibo_id', $ids)->orderBy('id')->get()
            ->keyBy('recibo_id')->map(fn ($e) => ['email' => $e->email, 'estado' => $e->estado, 'error' => $e->error, 'fecha' => $e->created_at->format('d/m/Y H:i')]));
    }

    public function reconstruir(Request $request): JsonResponse
    {
        $d = $request->validate(['ejercicio' => ['nullable', 'integer', 'between:2000,2100']]);

        return response()->json($this->nomina->reconstruir(Empresa::actual(), isset($d['ejercicio']) ? (int) $d['ejercicio'] : null));
    }
}
