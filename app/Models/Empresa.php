<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** v11: la empresa que paga la nómina (hoy una sola: Vagón COM; el diseño admite más). */
class Empresa extends Model
{
    use Auditable;

    protected $fillable = ['nombre', 'razon_social', 'rfc', 'regimen_fiscal', 'registro_patronal', 'codigo_postal', 'estado',
        'periodicidad', 'divisor_salario_diario', 'dias_aguinaldo', 'prima_vacacional', 'zona_salario_minimo', 'timbrado_activo', 'metodo_isr_extraordinario',
        // v14: CFDI de nómina
        'curp', 'clave_ent_fed', 'riesgo_puesto', 'tipo_jornada', 'serie_cfdi', 'pac', 'pac_modo', 'pac_usuario', 'pac_password',
        'csd_cer_path', 'csd_key_path', 'csd_password', 'csd_no_certificado', 'csd_rfc', 'csd_vigente_desde', 'csd_vigente_hasta'];

    /** v14: las contraseñas del PAC y del CSD, y las rutas de los archivos del CSD, nunca salen en el JSON. */
    protected $hidden = ['pac_password', 'csd_password', 'csd_cer_path', 'csd_key_path'];

    /** v14: las contraseñas tampoco se copian a la bitácora de auditoría. */
    protected array $auditExclude = ['pac_password', 'csd_password'];

    protected function casts(): array
    {
        return ['divisor_salario_diario' => 'float', 'prima_vacacional' => 'float', 'dias_aguinaldo' => 'integer', 'timbrado_activo' => 'boolean',
            'pac_password' => 'encrypted', 'csd_password' => 'encrypted', 'csd_vigente_desde' => 'datetime', 'csd_vigente_hasta' => 'datetime'];
    }

    /** La empresa con la que trabaja la plataforma. */
    public static function actual(): self
    {
        return static::query()->orderBy('id')->firstOrFail();
    }
}
