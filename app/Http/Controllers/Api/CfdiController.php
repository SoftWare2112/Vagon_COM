<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CfdiEvento;
use App\Models\Empresa;
use App\Models\NominaRecibo;
use App\Models\PeriodoNomina;
use App\Services\Cfdi\ImportarXmlService;
use App\Services\Cfdi\TimbradoService;
use App\Support\Cfdi\DatosCfdiNomina;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * v14 — CFDI de nómina: configuración del timbrado (opcional), CSD, PAC, timbrado, cancelación,
 * descarga del XML e importación de los XML timbrados por el departamento de nóminas.
 */
class CfdiController extends Controller
{
    public function __construct(private readonly TimbradoService $timbrado) {}

    public function configuracion(): JsonResponse
    {
        return response()->json($this->timbrado->estado(Empresa::actual()));
    }

    public function guardarConfiguracion(Request $request): JsonResponse
    {
        $d = $request->validate([
            'timbrado_activo' => ['sometimes', 'boolean'],
            'curp' => ['nullable', 'regex:/^[A-Z][AEIOUX][A-Z]{2}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/i'],
            'clave_ent_fed' => ['nullable', Rule::in(array_keys(DatosCfdiNomina::ESTADOS))],
            'riesgo_puesto' => ['sometimes', Rule::in(array_keys(DatosCfdiNomina::RIESGOS))],
            'tipo_jornada' => ['sometimes', Rule::in(array_keys(DatosCfdiNomina::JORNADAS))],
            'serie_cfdi' => ['sometimes', 'nullable', 'regex:/^[A-Za-z0-9]{0,10}$/'],
            'pac' => ['sometimes', Rule::in(['simulado', 'sw'])],
            'pac_modo' => ['sometimes', Rule::in(['pruebas', 'produccion'])],
            'pac_usuario' => ['nullable', 'string', 'max:150'],
            'pac_password' => ['nullable', 'string', 'max:200'],
        ], ['curp.regex' => 'La CURP no tiene el formato correcto.', 'serie_cfdi.regex' => 'La serie admite hasta 10 letras o números.']);
        if (array_key_exists('serie_cfdi', $d)) {
            $d['serie_cfdi'] = strtoupper((string) $d['serie_cfdi']) ?: 'N';
        }
        if (isset($d['curp'])) {
            $d['curp'] = strtoupper($d['curp']);
        }
        $e = Empresa::actual();
        $this->timbrado->guardarConfiguracion($e, $d, $request->user());

        return response()->json($this->timbrado->estado($e->refresh()));
    }

    public function cargarCsd(Request $request): JsonResponse
    {
        $request->validate([
            'cer' => ['required', 'file', 'max:20'], 'key' => ['required', 'file', 'max:20'], 'password' => ['required', 'string', 'max:100'],
        ], ['cer.required' => 'Seleccione el archivo .cer del CSD.', 'key.required' => 'Seleccione el archivo .key del CSD.',
            'password.required' => 'Escriba la contraseña de la llave privada del CSD.']);
        $e = Empresa::actual();
        $csd = $this->timbrado->cargarCsd($e, $request->file('cer'), $request->file('key'), $request->string('password')->toString(), $request->user());

        return response()->json(['csd' => $csd, 'estado' => $this->timbrado->estado($e->refresh())]);
    }

    public function probar(Request $request): JsonResponse
    {
        return response()->json($this->timbrado->probarConexion(Empresa::actual(), $request->user()));
    }

    public function validar(NominaRecibo $recibo): JsonResponse
    {
        $errores = $this->timbrado->validar($recibo);

        return response()->json(['listo' => ! $errores, 'errores' => $errores]);
    }

    public function timbrarRecibo(NominaRecibo $recibo, Request $request): JsonResponse
    {
        return response()->json($this->timbrado->timbrarRecibo($recibo, $request->user()));
    }

    public function timbrarPeriodo(PeriodoNomina $periodo, Request $request): JsonResponse
    {
        return response()->json($this->timbrado->timbrarPeriodo($periodo, $request->user()));
    }

    public function cancelar(NominaRecibo $recibo, Request $request): JsonResponse
    {
        $d = $request->validate([
            'motivo' => ['required', Rule::in(array_keys(TimbradoService::MOTIVOS_CANCELACION))],
            'folio_sustitucion' => ['nullable', 'string', 'max:36'],
        ]);

        return response()->json($this->timbrado->cancelar($recibo, $d['motivo'], $d['folio_sustitucion'] ?? null, $request->user()));
    }

    /** Descarga del XML timbrado (con permiso nomina.ver). */
    public function xml(NominaRecibo $recibo): Response
    {
        $xml = $this->timbrado->xml($recibo);
        abort_if(! $xml, 404, 'El recibo no tiene XML timbrado.');
        $nombre = sprintf('%s-%d-%s.xml', $recibo->numero_empleado, $recibo->ejercicio, $recibo->uuid ?? 'cfdi');

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$nombre.'"']);
    }

    public function importar(Request $request, ImportarXmlService $importar): JsonResponse
    {
        $request->validate([
            'archivos' => ['required', 'array', 'min:1', 'max:200'],
            'archivos.*' => ['file', 'max:51200', function ($attr, $f, $fail) {
                if (! preg_match('/\.(xml|zip)$/i', $f->getClientOriginalName())) {
                    $fail('Solo se aceptan archivos .xml o .zip.');
                }
            }],
            'aplicar' => ['sometimes', 'boolean'],
            'forzar' => ['sometimes', 'array'], 'forzar.*' => ['string', 'size:36'],
        ], ['archivos.required' => 'Seleccione los XML (o un ZIP con ellos).']);
        $rutas = [];
        foreach ($request->file('archivos') as $f) {
            $rutas[$f->getClientOriginalName()] = $f->getRealPath();
        }

        return response()->json($importar->procesar(Empresa::actual(), $importar->expandir($rutas), $request->boolean('aplicar'),
            $request->input('forzar', []), $request->user()));
    }

    public function eventos(Request $request): JsonResponse
    {
        $q = CfdiEvento::with(['usuario:id,name', 'recibo:id,nombre,numero_empleado,periodo_id'])->latest('id')
            ->when($request->integer('recibo_id'), fn ($q, $id) => $q->where('recibo_id', $id))
            ->when($request->string('tipo')->toString(), fn ($q, $t) => $q->where('tipo', $t));

        return response()->json($q->limit(200)->get()->map(fn (CfdiEvento $ev) => [
            'id' => $ev->id, 'tipo' => $ev->tipo, 'uuid' => $ev->uuid, 'modo' => $ev->modo, 'mensaje' => $ev->mensaje, 'datos' => $ev->datos,
            'fecha' => $ev->created_at?->format('d/m/Y H:i'), 'usuario' => $ev->usuario?->name,
            'recibo' => $ev->recibo ? ['id' => $ev->recibo->id, 'nombre' => $ev->recibo->nombre, 'numero_empleado' => $ev->recibo->numero_empleado] : null,
        ]));
    }
}
