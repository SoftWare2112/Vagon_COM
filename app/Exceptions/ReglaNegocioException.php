<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Base de errores de negocio: se convierten solos en JSON con su código HTTP. */
class ReglaNegocioException extends RuntimeException
{
    public function __construct(string $mensaje, protected int $status = 422, protected array $extra = [])
    {
        parent::__construct($mensaje);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), ...$this->extra], $this->status);
    }
}
