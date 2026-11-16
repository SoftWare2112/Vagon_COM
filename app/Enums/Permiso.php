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
    // v8: módulo Hojas
    case HojasVer = 'hojas.ver';
    case HojasCrear = 'hojas.crear';
    case HojasEditar = 'hojas.editar';
    case PlantillasGestionar = 'plantillas.gestionar';
    // v9: espacio personal e historial de importaciones
    case HojasVerTodas = 'hojas.ver_todas';
    case InformesLimpiar = 'informes.limpiar';
    // v10: publicaciones
    case PublicacionesVer = 'publicaciones.ver';
    case PublicacionesCrear = 'publicaciones.crear';
    case PublicacionesModerar = 'publicaciones.moderar';
    // v11: sistema de nómina
    case EmpleadosVer = 'empleados.ver';
    case EmpleadosGestionar = 'empleados.gestionar';
    case NominaConfigurar = 'nomina.configurar';
    // v12: nómina por periodo, historial, reportes e indicadores
    case NominaVer = 'nomina.ver';
    case NominaCalcular = 'nomina.calcular';
    case NominaAutorizar = 'nomina.autorizar';
    case IndicadoresVer = 'indicadores.ver';

    /**
     * v11: permisos de los módulos retirados (incidencias por Excel, hojas de cálculo, informes de cargas).
     * El caso se conserva solo porque migraciones antiguas lo mencionan; ya no se asigna ni se muestra.
     */
    public const RETIRADOS = [
        'registros.ver', 'registros.crear', 'registros.editar', 'registros.eliminar', 'cargas.subir', 'cargas.ver', 'exportar',
        'historico.ver', 'historico.restaurar', 'columnas.gestionar', 'ajustes.gestionar', 'hojas.ver', 'hojas.crear', 'hojas.editar',
        'plantillas.gestionar', 'hojas.ver_todas', 'informes.limpiar',
    ];

    /** @return list<self> los permisos que existen hoy en la plataforma */
    public static function vigentes(): array
    {
        return array_values(array_filter(self::cases(), fn (self $p) => ! in_array($p->value, self::RETIRADOS, true)));
    }

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
            self::HojasVer => 'Ver las hojas de cálculo',
            self::HojasCrear => 'Crear e importar sus propios libros (en blanco, desde plantilla o desde un Excel)',
            self::HojasEditar => 'Escribir, dar formato y cambiar filas o columnas de las hojas',
            self::PlantillasGestionar => 'Guardar y borrar plantillas de hojas',
            self::HojasVerTodas => 'Ver y controlar los trabajos (libros) de todos los usuarios',
            self::InformesLimpiar => 'Quitar importaciones de otros usuarios y limpiar todo el historial',
            self::PublicacionesVer => 'Ver las publicaciones dirigidas a su usuario o rol',
            self::PublicacionesCrear => 'Publicar textos, documentos, imágenes y videos',
            self::PublicacionesModerar => 'Fijar, marcar como oficial, ocultar o eliminar publicaciones de cualquiera',
            self::EmpleadosVer => 'Consultar el expediente de los empleados (incluye salario)',
            self::EmpleadosGestionar => 'Dar de alta, editar y dar de baja empleados; contratos y cambios de salario',
            self::NominaConfigurar => 'Configurar la nómina: empresa, conceptos, tablas fiscales y periodos',
            self::NominaVer => 'Consultar nóminas autorizadas, recibos de periodos anteriores y reportes',
            self::NominaCalcular => 'Capturar incidencias del periodo y calcular la pre-nómina',
            self::NominaAutorizar => 'Autorizar (cerrar) la nómina, reabrir la última sin timbrar y crear respaldos',
            self::IndicadoresVer => 'Ver las gráficas de indicadores (faltas, bonos, domingos, altas y bajas)',
        };
    }

    /** Matriz inicial (editable después desde Usuarios > Roles y permisos). v12: nómina por periodo. */
    public static function porRol(): array
    {
        return [
            'admin_rh' => array_map(fn (self $p) => $p->value, self::vigentes()),
            'supervisor' => [
                self::EmpleadosVer->value,
                self::NominaVer->value,
                self::NominaCalcular->value,
                self::IndicadoresVer->value,
                self::PublicacionesVer->value,
                self::PublicacionesCrear->value,
            ],
            'lector' => [
                self::EmpleadosVer->value,
                self::IndicadoresVer->value,
                self::PublicacionesVer->value,
            ],
        ];
    }
}
