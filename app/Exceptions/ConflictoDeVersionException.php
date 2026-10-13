<?php

namespace App\Exceptions;

/** Otro usuario guardó el registro antes que tú (bloqueo optimista). */
class ConflictoDeVersionException extends ReglaNegocioException
{
    public function __construct(array $actual)
    {
        parent::__construct('Otro usuario modificó este registro mientras lo editabas. Revisa los datos actuales y vuelve a guardar.', 409, ['actual' => $actual]);
    }
}
