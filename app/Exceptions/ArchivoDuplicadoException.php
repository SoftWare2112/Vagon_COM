<?php

namespace App\Exceptions;

class ArchivoDuplicadoException extends ReglaNegocioException
{
    public function __construct(string $fecha, string $usuario)
    {
        parent::__construct("Este archivo (o uno con los mismos datos) ya se cargó el {$fecha} por {$usuario}. No se puede volver a subir.", 409, ['codigo' => 'ARCHIVO_DUPLICADO']);
    }
}
