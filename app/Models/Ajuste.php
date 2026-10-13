<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Ajuste extends Model
{
    protected $primaryKey = 'clave';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['clave', 'valor', 'descripcion'];

    /** Claves editables desde la pantalla Ajustes (lista blanca). */
    public const PERMITIDOS = [
        'modo_etl_predeterminado' => ['in:REEMPLAZAR,MANTENER', 'MANTENER'],
        'filas_esperadas' => ['integer|min:0|max:1000', '48'],
    ];

    public static function valor(string $clave, mixed $defecto = null): mixed
    {
        return Cache::rememberForever("ajuste:{$clave}", fn () => static::find($clave)?->valor) ?? $defecto ?? (self::PERMITIDOS[$clave][1] ?? null);
    }

    public static function guardar(string $clave, string $valor): void
    {
        static::updateOrCreate(['clave' => $clave], ['valor' => $valor]);
        Cache::forget("ajuste:{$clave}");
    }
}
