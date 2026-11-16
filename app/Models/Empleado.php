<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Support\Texto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** v11: expediente del trabajador para la nómina. Una baja no lo borra: conserva su historial. */
class Empleado extends Model
{
    use Auditable;

    public const CONTRATOS = ['capacitacion' => 'Capacitación', 'prueba' => 'Periodo de prueba', 'temporal' => 'Temporal', 'indeterminado' => 'Indeterminado'];

    protected $fillable = [
        'empresa_id', 'numero_empleado', 'nombre_completo', 'apellido_paterno', 'apellido_materno', 'nombres', 'fecha_ingreso', 'origen',
        'rfc', 'curp', 'nss', 'codigo_postal', 'email', 'telefono', 'puesto_id', 'departamento', 'salario_mensual', 'periodicidad',
        'tipo_contrato', 'contrato_fin', 'jornada_horas', 'dias_descanso', 'banco', 'clabe', 'estado', 'fecha_baja', 'motivo_baja', 'notas', 'validaciones_omitidas',
    ];

    /** La cuenta bancaria no se copia a la bitácora de auditoría. */
    protected array $auditExclude = ['clabe', 'nombre_normalizado'];

    protected function casts(): array
    {
        return ['fecha_ingreso' => 'date', 'contrato_fin' => 'date', 'fecha_baja' => 'date', 'salario_mensual' => 'float',
            'jornada_horas' => 'float', 'dias_descanso' => 'array', 'validaciones_omitidas' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $e) {
            if ($e->nombres || $e->apellido_paterno) {
                $e->nombre_completo = trim(preg_replace('/\s+/', ' ', "{$e->nombres} {$e->apellido_paterno} {$e->apellido_materno}"));
            }
            $e->nombre_normalizado = Texto::normalizar($e->nombre_completo);
        });
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function puesto(): BelongsTo
    {
        return $this->belongsTo(Puesto::class);
    }

    public function contratos(): HasMany
    {
        return $this->hasMany(EmpleadoContrato::class)->orderByDesc('inicio')->orderByDesc('id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(EmpleadoMovimiento::class)->orderByDesc('fecha')->orderByDesc('id');
    }

    public function recibos(): HasMany
    {
        return $this->hasMany(NominaRecibo::class);
    }

    /** v12: salario mensual vigente en una fecha (según el historial de sueldos). */
    public function salarioEn(string $fecha): ?float
    {
        $s = $this->salarios()->whereDate('desde', '<=', $fecha)->first();

        return $s?->salario_mensual ?? $this->salario_mensual;
    }

    public function salarios(): HasMany
    {
        return $this->hasMany(EmpleadoSalario::class)->orderByDesc('desde')->orderByDesc('id');
    }

    /** Datos obligatorios para calcular y, más adelante, timbrar la nómina. */
    public function faltantes(): array
    {
        $faltan = [];
        foreach (['rfc' => 'RFC', 'nss' => 'NSS', 'curp' => 'CURP', 'salario_mensual' => 'Salario', 'puesto_id' => 'Puesto', 'codigo_postal' => 'Código postal fiscal'] as $c => $nombre) {
            if (empty($this->{$c})) {
                $faltan[] = $nombre;
            }
        }

        return $faltan;
    }
}
