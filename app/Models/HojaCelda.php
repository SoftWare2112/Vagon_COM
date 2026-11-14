<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una celda con contenido o formato. El valor va en la columna de su clase
 * (valor_texto / valor_numero / valor_fecha / valor_logico); una fórmula guarda además su texto.
 */
class HojaCelda extends Model
{
    protected $fillable = [
        'hoja_id', 'fila_id', 'columna_id', 'tipo', 'valor_texto', 'valor_numero', 'valor_fecha', 'valor_logico',
        'formula', 'resultado_tipo', 'estilo_id', 'version', 'editado_por',
    ];

    protected function casts(): array
    {
        return ['valor_logico' => 'boolean', 'version' => 'integer'];
    }
}
