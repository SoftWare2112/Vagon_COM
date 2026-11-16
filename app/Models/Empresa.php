<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** v11: la empresa que paga la nómina (hoy una sola: Vagón COM; el diseño admite más). */
class Empresa extends Model
{
    use Auditable;

    protected $fillable = ['nombre', 'razon_social', 'rfc', 'regimen_fiscal', 'registro_patronal', 'codigo_postal', 'estado',
        'periodicidad', 'divisor_salario_diario', 'dias_aguinaldo', 'prima_vacacional', 'zona_salario_minimo', 'timbrado_activo'];

    protected function casts(): array
    {
        return ['divisor_salario_diario' => 'float', 'prima_vacacional' => 'float', 'dias_aguinaldo' => 'integer', 'timbrado_activo' => 'boolean'];
    }

    /** La empresa con la que trabaja la plataforma. */
    public static function actual(): self
    {
        return static::query()->orderBy('id')->firstOrFail();
    }
}
