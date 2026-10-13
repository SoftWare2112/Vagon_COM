<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Carga */
class CargaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'archivo' => $this->nombre_original,
            'usuario' => $this->whenLoaded('usuario', fn () => $this->usuario->name),
            'estatus' => $this->estatus->value,
            'modo' => $this->modo?->value,
            'periodo' => $this->encabezados['periodo'] ?? null,
            'hoja' => $this->encabezados['hoja'] ?? null,
            'hojas' => $this->encabezados['hojas'] ?? [],
            'columnas' => $this->encabezados['mapa'] ?? null,
            'filas_total' => $this->filas_total,
            'num_errores' => $this->num_errores,
            'num_advertencias' => $this->num_advertencias,
            'resumen' => $this->resumen,
            'subida' => $this->created_at->format('d/m/Y H:i'),
            'aplicada' => $this->aplicada_at?->format('d/m/Y H:i'),
            'mensajes' => $this->whenLoaded('mensajes', fn () => $this->mensajes->map->only(['nivel', 'codigo', 'mensaje', 'fila', 'columna', 'celda', 'valor_recibido'])->values()),
        ];
    }
}
