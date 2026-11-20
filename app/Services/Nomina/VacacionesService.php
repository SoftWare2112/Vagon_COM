<?php

namespace App\Services\Nomina;

use App\Exceptions\ReglaNegocioException;
use App\Models\AuditLog;
use App\Models\Empleado;
use App\Models\EmpleadoMovimiento;
use App\Models\PeriodoNomina;
use App\Models\User;
use App\Models\Vacacion;
use App\Support\Nomina\Vacaciones;

/**
 * v13 — Vacaciones del empleado: saldo por año de servicio, registro y cancelación.
 * Los días de vacaciones se pagan como sueldo normal (no son faltas); la prima vacacional se paga
 * en la nómina del periodo donde INICIAN las vacaciones.
 */
class VacacionesService
{
    public function resumen(Empleado $e, ?string $fecha = null): array
    {
        $registros = Vacacion::with('autor:id,name')->where('empleado_id', $e->id)->orderByDesc('desde')->get();
        $saldo = Vacaciones::saldo($e->fecha_ingreso->format('Y-m-d'), $fecha ?? now()->toDateString(),
            $registros->groupBy('anio_servicio')->map(fn ($g) => (int) $g->sum('dias'))->all());

        return [
            ...$saldo,
            'registros' => $registros->map(fn (Vacacion $v) => ['id' => $v->id, 'desde' => $v->desde->format('Y-m-d'), 'hasta' => $v->hasta->format('Y-m-d'),
                'dias' => $v->dias, 'anio_servicio' => $v->anio_servicio, 'notas' => $v->notas, 'por' => $v->autor?->name,
                'prima_pagada' => $this->periodoDe($e, $v->desde->format('Y-m-d'))?->autorizado() ?? false])->values(),
        ];
    }

    public function registrar(Empleado $e, array $d, User $u): Vacacion
    {
        if ($e->estado !== 'activo') {
            throw new ReglaNegocioException('El empleado está dado de baja.', 422, ['codigo' => 'BAJA']);
        }
        $desde = $d['desde'];
        $hasta = $d['hasta'];
        $dias = (int) $d['dias'];
        $calendario = (int) (new \DateTimeImmutable($desde))->diff(new \DateTimeImmutable($hasta))->days + 1;
        if ($hasta < $desde || $dias < 1 || $dias > $calendario) {
            throw new ReglaNegocioException("Los días hábiles ({$dias}) deben estar entre 1 y los {$calendario} días del rango.", 422, ['codigo' => 'DIAS_INVALIDOS']);
        }
        if (Vacacion::where('empleado_id', $e->id)->whereDate('desde', '<=', $hasta)->whereDate('hasta', '>=', $desde)->exists()) {
            throw new ReglaNegocioException('Ya hay vacaciones registradas que se cruzan con esas fechas.', 422, ['codigo' => 'TRASLAPE']);
        }
        $periodo = $this->periodoDe($e, $desde);
        if ($periodo?->autorizado()) {
            throw new ReglaNegocioException("La nómina donde inician ({$periodo->etiqueta()}) ya está autorizada: su prima vacacional ya no se puede pagar ahí.", 409, ['codigo' => 'PERIODO_AUTORIZADO']);
        }
        $saldo = $this->resumen($e, $desde);
        $anio = isset($d['anio_servicio']) ? (int) $d['anio_servicio'] : Vacaciones::anioACargar($saldo, $dias);
        $elegido = collect($saldo['anios'])->firstWhere('anio', $anio);
        if (! $elegido || $elegido['pendientes'] < $dias) {
            throw new ReglaNegocioException($saldo['anios'] === []
                ? 'Aún no cumple su primer año de servicio: tendrá '.$saldo['proximo']['derecho'].' días a partir del '.date('d/m/Y', strtotime($saldo['proximo']['desde'])).'.'
                : "No le alcanza el saldo: tiene {$saldo['disponibles']} día(s) disponibles.", 422, ['codigo' => 'SIN_SALDO']);
        }

        $v = Vacacion::create(['empleado_id' => $e->id, 'desde' => $desde, 'hasta' => $hasta, 'dias' => $dias, 'anio_servicio' => $anio,
            'notas' => $d['notas'] ?? null, 'creado_por' => $u->id]);
        if ($periodo && $periodo->estado === 'calculado') {
            $periodo->update(['estado' => 'abierto']);   // su pre-nómina debe recalcularse para pagar la prima
        }
        AuditLog::registrar('VACACIONES', 'Empleado', $e->id, null, ['desde' => $desde, 'hasta' => $hasta, 'dias' => $dias, 'anio_servicio' => $anio], $u->id);

        return $v;
    }

    public function cancelar(Vacacion $v, User $u): void
    {
        $e = $v->empleado;
        $periodo = $this->periodoDe($e, $v->desde->format('Y-m-d'));
        if ($periodo?->autorizado()) {
            throw new ReglaNegocioException('Su prima vacacional ya se pagó en una nómina autorizada; no se puede cancelar.', 409, ['codigo' => 'PERIODO_AUTORIZADO']);
        }
        if ($periodo && $periodo->estado === 'calculado') {
            $periodo->update(['estado' => 'abierto']);
        }
        AuditLog::registrar('CANCELAR_VACACIONES', 'Empleado', $e->id, $v->only(['desde', 'hasta', 'dias']), null, $u->id);
        $v->delete();
    }

    /** Periodo ordinario del empleado que contiene la fecha (si ya se generó el calendario). */
    private function periodoDe(Empleado $e, string $fecha): ?PeriodoNomina
    {
        return PeriodoNomina::where(['empresa_id' => $e->empresa_id, 'tipo' => $e->periodicidad])
            ->whereDate('inicio', '<=', $fecha)->whereDate('fin', '>=', $fecha)->first();
    }
}
