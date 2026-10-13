<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InformeExportado extends Model
{
    protected $table = 'informes_exportados';

    protected $fillable = ['user_id', 'periodo_id', 'formato', 'nombre', 'ruta_archivo', 'filtros', 'filas'];

    protected $hidden = ['ruta_archivo'];

    protected function casts(): array
    {
        return ['filtros' => 'array'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
