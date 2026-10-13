<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Support\CatalogoColumnas;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Una fila del reporte: un empleado en una quincena. Se elimina (lógico) y se restaura a su quincena. */
class Registro extends Model
{
    use Auditable, SoftDeletes;

    /** Columnas propias editables (además de puesto, domingos y extras). */
    public const EDITABLES = [...CatalogoColumnas::DE_REGISTRO, 'valor_periodo'];

    protected $fillable = [
        'empleado_id', 'periodo_id', 'carga_id', 'puesto_id', ...self::EDITABLES, 'valores_extra',
        'campos_editados', 'version', 'editado_por', 'conservar_en_cargas', 'motivo_baja', 'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'prima_vacacional' => 'decimal:2',
            'bono_3_meses' => 'decimal:2',
            'bono_puntualidad' => 'decimal:2',
            'bono_asistencia' => 'decimal:2',
            'programado_domingo' => 'decimal:2',
            'num_faltas' => 'integer',
            'acredita_bono' => 'boolean',
            'valores_extra' => 'array',
            'campos_editados' => 'array',
            'conservar_en_cargas' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    /** Puesto que tenía la persona en ESTA quincena. */
    public function puesto(): BelongsTo
    {
        return $this->belongsTo(Puesto::class);
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(Periodo::class);
    }

    public function domingos(): HasMany
    {
        return $this->hasMany(RegistroDomingo::class)->orderBy('fecha');
    }

    public function eliminadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
