<?php

namespace App\Services\Empleados;

use App\Models\Empleado;
use App\Models\EmpleadoContrato;
use App\Models\EmpleadoMovimiento;
use App\Models\EmpleadoSalario;
use App\Models\Empresa;
use App\Models\User;
use App\Support\Nomina\ValidadorFiscal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * v11: alta, edición, baja y reactivación de empleados; contratos y cambios de salario con historial.
 * Antes de guardar se validan RFC, CURP, NSS y CLABE con su dígito verificador.
 */
class EmpleadoService
{
    public function alta(array $d, User $u): Empleado
    {
        $omitidas = $this->validarFiscales($d, null, $u);

        return DB::transaction(function () use ($d, $u) {
            $empresa = Empresa::actual();
            $e = Empleado::create([
                ...$this->campos($d),
                'empresa_id' => $empresa->id,
                'numero_empleado' => $d['numero_empleado'] ?? $this->siguienteNumero(),
                'origen' => 'manual',
                'estado' => 'activo',
                'periodicidad' => $d['periodicidad'] ?? $empresa->periodicidad,
                'validaciones_omitidas' => $omitidas ?: null,
            ]);
            EmpleadoMovimiento::registrar($e->id, 'alta', $e->fecha_ingreso->format('Y-m-d'), 'Alta en la empresa · sueldo mensual $'.number_format((float) $e->salario_mensual, 2),
                ['salario_mensual' => $e->salario_mensual, 'puesto_id' => $e->puesto_id, 'contrato' => $e->tipo_contrato]);
            EmpleadoContrato::create(['empleado_id' => $e->id, 'tipo' => $e->tipo_contrato, 'inicio' => $d['contrato_inicio'] ?? $e->fecha_ingreso,
                'fin' => $e->contrato_fin, 'notas' => 'Contrato al dar de alta', 'creado_por' => $u->id]);
            EmpleadoSalario::create(['empleado_id' => $e->id, 'salario_mensual' => $e->salario_mensual, 'desde' => $e->fecha_ingreso,
                'motivo' => 'Salario de ingreso', 'creado_por' => $u->id]);

            return $e;
        });
    }

    /** El salario y el contrato NO se cambian aquí: tienen su propio movimiento con fecha (historial). */
    public function actualizar(Empleado $e, array $d, ?User $u = null): Empleado
    {
        $omitidas = $this->validarFiscales($d, $e, $u);
        unset($d['salario_mensual'], $d['tipo_contrato'], $d['contrato_fin'], $d['numero_empleado']);
        $puestoAntes = $e->puesto_id;
        $campos = $this->campos($d);
        // Un dato corregido para que sí pase la validación ya no necesita la excepción guardada
        $previas = array_filter((array) $e->validaciones_omitidas, fn ($v, $campo) => ! array_key_exists($campo, $campos) || $campos[$campo] === $e->{$campo}, ARRAY_FILTER_USE_BOTH);
        $campos['validaciones_omitidas'] = [...$previas, ...$omitidas] ?: null;
        $e->update($campos);
        if (array_key_exists('puesto_id', $campos) && (int) $puestoAntes !== (int) $e->puesto_id) {
            EmpleadoMovimiento::registrar($e->id, 'puesto', now()->toDateString(), 'Cambio de puesto a '.($e->puesto?->nombre ?? 'sin puesto'),
                ['antes' => $puestoAntes, 'despues' => $e->puesto_id]);
        }

        return $e;
    }

    public function cambiarSalario(Empleado $e, float $salario, string $desde, ?string $motivo, User $u): void
    {
        DB::transaction(function () use ($e, $salario, $desde, $motivo, $u) {
            EmpleadoSalario::create(['empleado_id' => $e->id, 'salario_mensual' => $salario, 'desde' => $desde, 'motivo' => $motivo, 'creado_por' => $u->id]);
            $vigente = $e->salarios()->whereDate('desde', '<=', now()->toDateString())->first();
            $anterior = $e->salario_mensual;
            $e->update(['salario_mensual' => $vigente?->salario_mensual ?? $salario]);
            EmpleadoMovimiento::registrar($e->id, 'salario', $desde, 'Sueldo mensual de $'.number_format((float) $anterior, 2).' a $'.number_format($salario, 2).($motivo ? " · {$motivo}" : ''),
                ['antes' => $anterior, 'despues' => $salario]);
        });
    }

    /** Nuevo contrato (p. ej. de prueba → temporal → indeterminado). Cierra el anterior el día previo. */
    public function nuevoContrato(Empleado $e, string $tipo, string $inicio, ?string $fin, ?string $notas, User $u): EmpleadoContrato
    {
        return DB::transaction(function () use ($e, $tipo, $inicio, $fin, $notas, $u) {
            $anterior = $e->contratos()->first();
            if ($anterior && (! $anterior->fin || $anterior->fin->format('Y-m-d') >= $inicio)) {
                if ($anterior->inicio->format('Y-m-d') >= $inicio) {
                    throw ValidationException::withMessages(['inicio' => 'El nuevo contrato debe empezar después del '.$anterior->inicio->format('d/m/Y').'.']);
                }
                $anterior->update(['fin' => date('Y-m-d', strtotime($inicio.' -1 day'))]);
            }
            $c = EmpleadoContrato::create(['empleado_id' => $e->id, 'tipo' => $tipo, 'inicio' => $inicio, 'fin' => $tipo === 'indeterminado' ? null : $fin,
                'notas' => $notas, 'creado_por' => $u->id]);
            $e->update(['tipo_contrato' => $tipo, 'contrato_fin' => $c->fin]);
            EmpleadoMovimiento::registrar($e->id, 'contrato', $inicio, 'Contrato '.mb_strtolower(Empleado::CONTRATOS[$tipo] ?? $tipo).($c->fin ? ' hasta el '.$c->fin->format('d/m/Y') : ''),
                ['tipo' => $tipo, 'fin' => $c->fin?->format('Y-m-d')]);

            return $c;
        });
    }

    public function baja(Empleado $e, string $fecha, string $motivo): void
    {
        DB::transaction(function () use ($e, $fecha, $motivo) {
            $e->update(['estado' => 'baja', 'fecha_baja' => $fecha, 'motivo_baja' => $motivo]);
            EmpleadoMovimiento::registrar($e->id, 'baja', $fecha, "Baja: {$motivo}", ['motivo' => $motivo]);
        });
    }

    /** v12: reingreso con fecha (queda en la trayectoria). La antigüedad se sigue contando desde la fecha de ingreso original. */
    public function reactivar(Empleado $e, ?string $fecha = null): void
    {
        DB::transaction(function () use ($e, $fecha) {
            $baja = $e->fecha_baja?->format('d/m/Y');
            $e->update(['estado' => 'activo', 'fecha_baja' => null, 'motivo_baja' => null]);
            EmpleadoMovimiento::registrar($e->id, 'reingreso', $fecha ?? now()->toDateString(), 'Reingreso'.($baja ? " (baja del {$baja})" : ''));
        });
    }

    private function campos(array $d): array
    {
        foreach (['rfc', 'curp'] as $c) {
            if (isset($d[$c])) {
                $d[$c] = ValidadorFiscal::normalizar($d[$c]) ?: null;
            }
        }
        foreach (['nss', 'clabe'] as $c) {
            if (isset($d[$c])) {
                $d[$c] = preg_replace('/\D/', '', $d[$c]) ?: null;
            }
        }
        foreach (['nombres', 'apellido_paterno', 'apellido_materno'] as $c) {
            if (isset($d[$c])) {
                $d[$c] = mb_strtoupper(trim(preg_replace('/\s+/', ' ', $d[$c])));   // como aparecen en la constancia fiscal
            }
        }

        return array_intersect_key($d, array_flip((new Empleado)->getFillable()));
    }

    /**
     * Errores por campo (422) si RFC, CURP, NSS o CLABE no son válidos o ya pertenecen a otro empleado.
     * v12: si SOLO falla el dígito verificador y RH confirma que el dato es correcto (lo vio en el documento oficial),
     * se acepta con motivo: `omitir_validacion: ["rfc"]` + `motivo_omision`. Se guarda quién y cuándo, y se avisa en el expediente.
     * El formato (longitud, fecha, letras) y la unicidad NUNCA se omiten.
     * @return array<string, array> las validaciones omitidas en esta operación
     */
    private function validarFiscales(array $d, ?Empleado $actual = null, ?User $u = null): array
    {
        $errores = [];
        $omitidas = [];
        $omitir = array_intersect((array) ($d['omitir_validacion'] ?? []), ['rfc', 'curp', 'nss', 'clabe']);
        $motivo = trim((string) ($d['motivo_omision'] ?? ''));
        $reglas = ['rfc' => fn ($v) => ValidadorFiscal::rfc($v), 'curp' => fn ($v) => ValidadorFiscal::curp($v),
            'nss' => fn ($v) => ValidadorFiscal::nss($v), 'clabe' => fn ($v) => ValidadorFiscal::clabe($v)];
        foreach ($reglas as $campo => $validar) {
            if (empty($d[$campo]) || ! ($msg = $validar($d[$campo]))) {
                continue;
            }
            if (ValidadorFiscal::esErrorDeDigito($msg) && in_array($campo, $omitir, true)) {
                if (mb_strlen($motivo) < 10) {
                    $errores['motivo_omision'] = 'Explica en al menos 10 caracteres por qué el dato es correcto (p. ej. «Coincide con la Constancia de Situación Fiscal»).';
                }
                $omitidas[$campo] = ['motivo' => $motivo, 'por' => $u?->id, 'por_nombre' => $u?->name, 'fecha' => now()->toDateString()];

                continue;
            }
            $errores[$campo] = $msg;
        }
        foreach (['rfc' => 'RFC', 'curp' => 'CURP', 'nss' => 'NSS'] as $campo => $nombre) {
            if (empty($d[$campo]) || isset($errores[$campo])) {
                continue;
            }
            $valor = $campo === 'nss' ? preg_replace('/\D/', '', $d[$campo]) : ValidadorFiscal::normalizar($d[$campo]);
            $otro = Empleado::where($campo, $valor)->when($actual, fn ($q) => $q->whereKeyNot($actual->id))->first();
            if ($otro) {
                $errores[$campo] = "Ese {$nombre} ya pertenece a {$otro->nombre_completo} ({$otro->numero_empleado}).";
            }
        }
        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        return $omitidas;
    }

    /** Número de empleado consecutivo (seguro aunque dos personas den de alta al mismo tiempo). */
    private function siguienteNumero(): string
    {
        $prefijo = config('reportes.numero_empleado.prefijo', 'VC');
        $digitos = (int) config('reportes.numero_empleado.digitos', 4);
        DB::table('secuencias')->insertOrIgnore(['clave' => 'numero_empleado', 'valor' => 0]);
        do {
            $n = (int) DB::table('secuencias')->where('clave', 'numero_empleado')->lockForUpdate()->value('valor') + 1;
            DB::table('secuencias')->where('clave', 'numero_empleado')->update(['valor' => $n]);
            $numero = $prefijo.str_pad((string) $n, $digitos, '0', STR_PAD_LEFT);
        } while (Empleado::where('numero_empleado', $numero)->exists());

        return $numero;
    }
}
