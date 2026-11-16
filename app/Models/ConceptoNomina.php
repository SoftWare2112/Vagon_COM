<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** v11: percepción o deducción del catálogo de la empresa, con su regla de cálculo. */
class ConceptoNomina extends Model
{
    use Auditable;

    public const METODOS = ['sueldo', 'fijo', 'por_evento', 'captura', 'isr', 'imss'];

    protected $table = 'conceptos_nomina';

    protected $fillable = ['empresa_id', 'clave', 'nombre', 'tipo', 'clave_sat', 'metodo', 'monto', 'porcentaje', 'regla', 'exencion',
        'descripcion', 'sistema', 'activo', 'orden'];

    protected function casts(): array
    {
        return ['regla' => 'array', 'exencion' => 'array', 'monto' => 'float', 'porcentaje' => 'float', 'sistema' => 'boolean', 'activo' => 'boolean'];
    }

    /** Forma que espera CalculadoraNomina. */
    public function paraCalculo(): array
    {
        return [
            'clave' => $this->clave, 'nombre' => $this->nombre, 'tipo' => $this->tipo, 'clave_sat' => $this->clave_sat, 'metodo' => $this->metodo,
            'monto' => $this->monto, 'porcentaje' => $this->porcentaje, 'regla' => $this->regla ?? [], 'exencion' => $this->exencion ?? ['tipo' => 'ninguna'],
            'activo' => $this->activo,
        ];
    }
}
