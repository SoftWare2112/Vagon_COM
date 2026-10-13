<?php

namespace App\Services\Etl\Sync;

use App\Models\Carga;
use App\Models\CargaFila;
use App\Models\Empleado;
use App\Models\Periodo;
use App\Models\Puesto;
use App\Models\Registro;
use App\Services\Empleados\NumeroEmpleadoGenerator;
use Illuminate\Support\Collection;

/** Código compartido por las estrategias (patrón Template Method). */
abstract class EstrategiaBase implements EstrategiaSincronizacion
{
    /** @var Collection<string, Empleado>|null  clave natural -> empleado */
    private ?Collection $empleados = null;

    /** @var Collection<int, Registro>|null  empleado_id -> registro de la quincena (incluye eliminados) */
    private ?Collection $registros = null;

    private ?int $periodoCargado = null;

    public function __construct(protected readonly NumeroEmpleadoGenerator $numeros) {}

    protected function buscarEmpleado(CargaFila $fila): ?Empleado
    {
        $this->empleados ??= Empleado::all()->keyBy(fn (Empleado $e) => $e->nombre_normalizado.'|'.$e->fecha_ingreso->format('Y-m-d'));

        return $this->empleados->get($fila->nombre_normalizado.'|'.$fila->datos['fecha_ingreso']);
    }

    /** Registro del empleado en la quincena (null si no existe; puede venir eliminado). */
    protected function buscarRegistro(?Empleado $emp, ?Periodo $periodo): ?Registro
    {
        if (! $emp || ! $periodo) {
            return null;
        }
        if ($this->periodoCargado !== $periodo->id) {
            $this->registros = Registro::withTrashed()->where('periodo_id', $periodo->id)->get()->keyBy('empleado_id');
            $this->periodoCargado = $periodo->id;
        }

        return $this->registros->get($emp->id);
    }

    protected function crearEmpleado(array $d): Empleado
    {
        // Solo los NUEVOS reciben número; a uno existente jamás se le reasigna.
        $emp = Empleado::create([
            'numero_empleado' => $this->numeros->siguiente(),
            'nombre_completo' => $d['nombre_completo'],
            'fecha_ingreso' => $d['fecha_ingreso'],
            'origen' => 'excel',
        ]);
        $this->empleados?->put($emp->nombre_normalizado.'|'.$d['fecha_ingreso'], $emp);

        return $emp;
    }

    /**
     * Inserta/actualiza el registro de la quincena con las columnas QUE VIENEN en el archivo.
     * Columna ausente = clave ausente en $d = valor guardado intacto (en ambos modos).
     * $respetarEditados = true  -> lo que RH editó en la web no se toca (MANTENER)
     * $respetarEditados = false -> el Excel manda y se limpia la marca (REEMPLAZAR)
     */
    protected function guardarRegistro(Empleado $emp, Periodo $periodo, Carga $carga, CargaFila $fila, ?Registro $reg, bool $respetarEditados): Registro
    {
        $d = $fila->datos;
        $reg ??= new Registro(['empleado_id' => $emp->id, 'periodo_id' => $periodo->id, 'version' => 0]);
        $editados = $respetarEditados ? ($reg->campos_editados ?? []) : [];

        foreach (Registro::EDITABLES as $campo) {
            if (array_key_exists($campo, $d) && ! in_array($campo, $editados, true)) {
                $reg->{$campo} = $campo === 'num_faltas' ? ($d[$campo] ?? 0) : $d[$campo];
            }
        }

        // Puesto de esta quincena (si la columna viene en el archivo y no se editó en la web)
        if (array_key_exists('puesto', $d) && ! in_array('puesto', $editados, true)) {
            $reg->puesto_id = Puesto::resolver($d['puesto'])?->id;
        }

        $extras = $reg->valores_extra ?? [];
        foreach ($d['extras'] ?? [] as $clave => $valor) {
            if (! in_array('extra:'.$clave, $editados, true)) {
                $extras[$clave] = $valor;
            }
        }
        $reg->valores_extra = $extras ?: null;

        $reg->carga_id = $carga->id;
        $reg->campos_editados = $editados ?: null;
        $reg->version = $reg->version + 1;
        $reg->save();

        foreach ($d['domingos'] ?? [] as $dom) {
            if ($dom['fecha'] === null || in_array('domingo:'.$dom['fecha'], $editados, true)) {
                continue;
            }
            $reg->domingos()->updateOrCreate(['fecha' => $dom['fecha']], ['etiqueta' => $dom['etiqueta'], 'cantidad' => $dom['cantidad']]);
        }

        return $reg;
    }

    protected function nombres(Collection $items, int $max = 50): array
    {
        return $items->take($max)->map(fn ($x) => $x instanceof Registro ? $x->empleado?->nombre_completo : $x->datos['nombre_completo'])->values()->all();
    }
}
