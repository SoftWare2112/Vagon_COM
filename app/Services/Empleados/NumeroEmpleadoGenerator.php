<?php

namespace App\Services\Empleados;

use Illuminate\Support\Facades\DB;

/**
 * Genera MSI0001, MSI0002, ... de forma SEGURA ante usuarios simultáneos:
 * SELECT ... FOR UPDATE sobre la fila del contador dentro de la transacción.
 * Nunca reutiliza números (aunque el empleado se elimine).
 * Debe llamarse DENTRO de una DB::transaction().
 */
class NumeroEmpleadoGenerator
{
    private const CLAVE = 'numero_empleado';

    public function siguiente(): string
    {
        $prefijo = config('reportes.numero_empleado.prefijo', 'MSI');
        $digitos = (int) config('reportes.numero_empleado.digitos', 4);

        DB::table('secuencias')->insertOrIgnore(['clave' => self::CLAVE, 'valor' => 0]);
        $actual = (int) DB::table('secuencias')->where('clave', self::CLAVE)->lockForUpdate()->value('valor');
        $nuevo = $actual + 1;

        if ($nuevo >= 10 ** $digitos) {
            throw new \OverflowException("Se agotaron los números de empleado con {$digitos} dígitos. Aumenta NUM_EMPLEADO_DIGITOS.");
        }

        DB::table('secuencias')->where('clave', self::CLAVE)->update(['valor' => $nuevo]);

        return $prefijo.str_pad((string) $nuevo, $digitos, '0', STR_PAD_LEFT);
    }
}
