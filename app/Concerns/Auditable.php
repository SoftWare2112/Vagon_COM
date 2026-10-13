<?php

namespace App\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Patrón Observer: cada modelo que use este trait deja rastro automático
 * de crear/editar/eliminar/restaurar, sin repetir código en los controladores.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $m) => $m->auditar('CREAR', null, $m->datosAuditables($m->getAttributes())));

        static::updated(function (Model $m) {
            $cambios = $m->datosAuditables($m->getChanges());
            unset($cambios['updated_at']);
            if ($cambios === []) {
                return;
            }
            $antes = array_intersect_key($m->getOriginal(), $cambios);
            $m->auditar('EDITAR', $m->datosAuditables($antes), $cambios);
        });

        static::deleted(fn (Model $m) => $m->auditar('ELIMINAR', $m->datosAuditables($m->getAttributes()), null));

        if (method_exists(static::class, 'restored')) {
            static::restored(fn (Model $m) => $m->auditar('RESTAURAR', null, ['id' => $m->getKey()]));
        }
    }

    protected function auditar(string $evento, ?array $antes, ?array $despues): void
    {
        AuditLog::registrar($evento, class_basename($this), $this->getKey(), $antes, $despues);
    }

    protected function datosAuditables(array $datos): array
    {
        $excluir = property_exists($this, 'auditExclude') ? $this->auditExclude : [];

        return array_diff_key($datos, array_flip($excluir));
    }
}
