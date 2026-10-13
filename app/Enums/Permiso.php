<?php

namespace App\Enums;

/**
 * Catálogo único de permisos. Las rutas usan ->can(Permiso::X->value).
 * El seeder asigna la matriz inicial; después el Administrador la cambia
 * desde Usuarios > Roles y permisos, sin tocar código.
 */
enum Permiso: string
{
    case RegistrosVer = 'registros.ver';
    case RegistrosCrear = 'registros.crear';
    case RegistrosEditar = 'registros.editar';
    case RegistrosEliminar = 'registros.eliminar';
    case CargasSubir = 'cargas.subir';
    case CargasVer = 'cargas.ver';
    case Exportar = 'exportar';
    case HistoricoVer = 'historico.ver';
    case HistoricoRestaurar = 'historico.restaurar';
    case ColumnasGestionar = 'columnas.gestionar';
    case UsuariosGestionar = 'usuarios.gestionar';
    case AjustesGestionar = 'ajustes.gestionar';

    public function descripcion(): string
    {
        return match ($this) {
            self::RegistrosVer => 'Ver el Dashboard y la vista en resumen',
            self::RegistrosCrear => 'Agregar empleados manualmente',
            self::RegistrosEditar => 'Editar celdas de la tabla',
            self::RegistrosEliminar => 'Quitar filas (pasan al Histórico)',
            self::CargasSubir => 'Subir archivos de Excel',
            self::CargasVer => 'Ver el historial de cargas',
            self::Exportar => 'Exportar a Excel y PDF',
            self::HistoricoVer => 'Consultar el Histórico',
            self::HistoricoRestaurar => 'Restaurar desde el Histórico',
            self::ColumnasGestionar => 'Agregar, mover y ocultar columnas',
            self::UsuariosGestionar => 'Gestionar usuarios, roles y permisos',
            self::AjustesGestionar => 'Cambiar ajustes del sistema',
        };
    }

    /** Matriz inicial confirmada con RH (editable después desde la web). */
    public static function porRol(): array
    {
        return [
            'admin_rh' => array_map(fn (self $p) => $p->value, self::cases()),
            'supervisor' => [
                self::RegistrosVer->value,
                self::RegistrosEditar->value,     // RH: "sí puede editar los registros al igual que el administrador"
                self::CargasSubir->value,
                self::CargasVer->value,
                self::Exportar->value,
                self::HistoricoVer->value,        // consulta; restaurar se puede habilitar desde la web
            ],
            'lector' => [
                self::RegistrosVer->value,
                self::Exportar->value,
            ],
        ];
    }
}
