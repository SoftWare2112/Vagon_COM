<?php

namespace App\Services\Registros;

use App\Exceptions\ConflictoDeVersionException;
use App\Models\Puesto;
use App\Models\Registro;
use App\Models\User;
use App\Support\ConversorTipo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edición, borrado y restauración de una fila (registro de una quincena).
 * Edición con BLOQUEO OPTIMISTA: el cliente manda la `version` que vio; si otro usuario
 * guardó antes, responde 409 y no se pisa su cambio.
 */
class RegistroService
{
    public function __construct(private readonly PresentadorFila $presentador) {}

    /** @param array<string, mixed> $valores  {clave => valor} con las claves de PresentadorFila */
    public function guardar(Registro $registro, array $valores, int $versionVista, User $usuario): Registro
    {
        return DB::transaction(function () use ($registro, $valores, $versionVista, $usuario) {
            $reg = Registro::whereKey($registro->id)->lockForUpdate()->firstOrFail();
            if ($reg->version !== $versionVista) {
                $reg->load(['empleado', 'puesto', 'domingos', 'periodo']);
                $cols = $this->presentador->columnas($reg->periodo);
                throw new ConflictoDeVersionException(['version' => $reg->version, 'valores' => $this->presentador->valores($reg, $cols)]);
            }

            $tipos = collect($this->presentador->columnas($reg->periodo()->first(), false))->keyBy('clave');
            $editados = $reg->campos_editados ?? [];
            $extras = $reg->valores_extra ?? [];
            $domingos = [];

            foreach ($valores as $clave => $valor) {
                $col = $tipos->get($clave);
                if (! $col || ! $col['editable']) {
                    continue;   // columna inexistente o no editable (nombre, fecha de ingreso)
                }
                $valor = $this->normalizar($valor, $col['tipo'], $col['etiqueta'], $clave);

                if ($clave === 'puesto') {
                    $reg->puesto_id = Puesto::resolver($valor)?->id;   // solo esta quincena
                } elseif (str_starts_with($clave, 'domingo:')) {
                    $domingos[substr($clave, 8)] = [$valor, $col['etiqueta']];
                } elseif (str_starts_with($clave, 'extra:')) {
                    $extras[substr($clave, 6)] = $valor;
                } else {
                    $reg->{$clave} = $clave === 'num_faltas' ? ($valor ?? 0) : $valor;
                }
                $editados[] = $clave;   // MANTENER respetará este campo en próximas cargas
            }

            $reg->valores_extra = $extras ?: null;
            $reg->campos_editados = array_values(array_unique($editados));
            $reg->version++;
            $reg->editado_por = $usuario->id;
            $reg->save();

            foreach ($domingos as $fecha => [$cantidad, $etiqueta]) {
                $reg->domingos()->updateOrCreate(['fecha' => $fecha], ['etiqueta' => $etiqueta, 'cantidad' => $cantidad]);
            }

            return $reg->load(['empleado', 'puesto', 'domingos', 'periodo']);
        });
    }

    public function eliminar(Registro $registro, User $usuario): void
    {
        DB::transaction(function () use ($registro, $usuario) {
            $registro->forceFill(['deleted_by' => $usuario->id, 'motivo_baja' => 'MANUAL', 'conservar_en_cargas' => false])->saveQuietly();
            $registro->delete();
        });
    }

    /**
     * Regresa la fila a SU quincena (no a la última carga) y la protege en futuras cargas
     * de esa quincena con modo Reemplazar.
     */
    public function restaurar(Registro $registro): void
    {
        DB::transaction(function () use ($registro) {
            $registro->restore();
            $registro->forceFill(['conservar_en_cargas' => true, 'motivo_baja' => null, 'deleted_by' => null])->save();
        });
    }

    /** Mismas reglas que el ETL (ConversorTipo). */
    private function normalizar(mixed $v, string $tipo, string $etiqueta, string $clave): mixed
    {
        $r = ConversorTipo::convertir($v, $tipo);
        if (! $r['ok']) {
            throw ValidationException::withMessages(["valores.{$clave}" => "{$etiqueta}: se esperaba {$r['esperado']}."]);
        }
        if ($clave === 'num_faltas' && $r['valor'] > config('reportes.etl.max_faltas', 31)) {
            throw ValidationException::withMessages(["valores.{$clave}" => "{$etiqueta}: el máximo es ".config('reportes.etl.max_faltas', 31).'.']);
        }

        return $r['valor'];
    }
}
