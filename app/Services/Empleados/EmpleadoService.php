<?php

namespace App\Services\Empleados;

use App\Exceptions\ReglaNegocioException;
use App\Models\Empleado;
use App\Models\Periodo;
use App\Models\Puesto;
use App\Models\Registro;
use App\Models\User;
use App\Services\Registros\RegistroService;
use App\Support\Texto;
use Illuminate\Support\Facades\DB;

class EmpleadoService
{
    public function __construct(
        private readonly NumeroEmpleadoGenerator $numeros,
        private readonly RegistroService $registros,
    ) {}

    /**
     * Alta manual en una quincena. Si la persona ya existe (otra quincena) se reutiliza
     * con su mismo número; si su fila de esta quincena está en Histórico, se pide restaurarla.
     */
    public function crear(array $datos, Periodo $periodo, User $usuario): Registro
    {
        return DB::transaction(function () use ($datos, $periodo, $usuario) {
            $empleado = Empleado::where('nombre_normalizado', Texto::normalizar($datos['nombre_completo']))
                ->whereDate('fecha_ingreso', $datos['fecha_ingreso'])->first();

            if ($empleado) {
                $existente = Registro::withTrashed()->where('empleado_id', $empleado->id)->where('periodo_id', $periodo->id)->first();
                if ($existente) {
                    throw new ReglaNegocioException($existente->trashed()
                        ? "{$empleado->nombre_completo} ({$empleado->numero_empleado}) está en el Histórico de esta quincena. Restáuralo en lugar de crearlo."
                        : "{$empleado->nombre_completo} ({$empleado->numero_empleado}) ya está en esta quincena.");
                }
            } else {
                $empleado = Empleado::create([
                    'numero_empleado' => $this->numeros->siguiente(),
                    'nombre_completo' => Texto::limpiar($datos['nombre_completo']),
                    'fecha_ingreso' => $datos['fecha_ingreso'],
                    'origen' => 'manual',
                ]);
            }

            $registro = Registro::create([
                'empleado_id' => $empleado->id, 'periodo_id' => $periodo->id, 'version' => 1,
                'puesto_id' => Puesto::resolver($datos['puesto'] ?? null)?->id,
                'num_faltas' => 0, 'editado_por' => $usuario->id,
            ]);

            if (! empty($datos['valores'])) {
                $registro = $this->registros->guardar($registro, $datos['valores'], 1, $usuario);
            }

            return $registro;
        });
    }
}
