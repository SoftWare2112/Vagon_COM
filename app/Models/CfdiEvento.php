<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** v14: bitácora del CFDI de nómina (timbrado, error del PAC, cancelación, importación de XML, carga del CSD). */
class CfdiEvento extends Model
{
    protected $table = 'cfdi_eventos';

    protected $fillable = ['empresa_id', 'recibo_id', 'tipo', 'uuid', 'modo', 'mensaje', 'datos', 'xml_path', 'user_id'];

    protected function casts(): array
    {
        return ['datos' => 'array'];
    }

    public function recibo(): BelongsTo
    {
        return $this->belongsTo(NominaRecibo::class, 'recibo_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
