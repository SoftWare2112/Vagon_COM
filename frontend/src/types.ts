export type Permiso =
  | 'registros.ver' | 'registros.crear' | 'registros.editar' | 'registros.eliminar'
  | 'cargas.subir' | 'cargas.ver' | 'exportar'
  | 'historico.ver' | 'historico.restaurar' | 'columnas.gestionar'
  | 'usuarios.gestionar' | 'ajustes.gestionar';

export interface Usuario {
  id: number;
  name: string;
  email: string;
  rol: 'admin_rh' | 'supervisor' | 'lector' | null;
  rol_id: number | null;
  permisos: Permiso[];
  is_active: boolean;
  bloqueado: boolean;
  locked_until: string | null;
  must_change_password: boolean;
  last_login_at: string | null;
}

export type TipoColumna = 'texto' | 'texto_largo' | 'entero' | 'decimal' | 'dinero' | 'fecha' | 'booleano';

/** Columna tal como la manda el servidor (ya expandida para la quincena y en orden). */
export interface Columna {
  clave: string;          // 'nombre_completo', 'domingo:2026-08-16', 'valor_periodo', 'extra:bono_transporte'
  etiqueta: string;
  tipo: TipoColumna;
  editable: boolean;
  origen: 'sistema' | 'personalizada' | 'domingos' | 'periodo';
}

/** Una fila: un empleado en una quincena. Los valores van por clave de columna. */
export interface Fila {
  id: number;
  empleado_id: number;
  numero_empleado: string;
  version: number;
  origen: 'excel' | 'manual';
  campos_editados: string[];
  valores: Record<string, string | number | boolean | null>;
}

export interface PeriodoInfo { id: number; etiqueta: string; inicio: string; fin: string }

export interface Paginado<T> {
  data: T[];
  meta: { current_page: number; last_page: number; total: number; per_page: number };
}

export interface TablaRespuesta extends Paginado<Fila> {
  periodo: PeriodoInfo | null;
  columnas: Columna[];
}

export interface Resumen extends Fila { columnas: Columna[]; periodo: string; faltas_mes: number }

/** Registro del catálogo (Ajustes > Columnas). */
export interface ColumnaCatalogo {
  id: number; clave: string; etiqueta: string; tipo: TipoColumna;
  origen: Columna['origen']; orden: number; alias: string[] | null;
  obligatoria: boolean; editable: boolean; visible: boolean; activa: boolean;
}

export interface ReporteCambioTipo {
  columna: string;
  tipo_actual: TipoColumna;
  tipo_nuevo: TipoColumna;
  con_valor: number;
  convertibles: number;
  no_convertibles: number;
  ejemplos: { numero_empleado: string; nombre: string; periodo: string; valor: string; esperado: string }[];
  aplicado: boolean;
}

export interface RolesRespuesta {
  permisos: { clave: Permiso; descripcion: string }[];
  roles: { id: number; nombre: string; descripcion: string; permisos: Permiso[] }[];
}

export interface Filtros {
  periodo_id?: number;
  nombre?: string;
  puesto?: string;
  ingreso_desde?: string; // dd/mm/aaaa
  ingreso_hasta?: string;
  faltas?: string;
  orden?: string;
  direccion?: 'asc' | 'desc';
  page?: number;
}

export interface MensajeCarga {
  nivel: 'ERROR' | 'ADVERTENCIA';
  codigo: string;
  mensaje: string;
  fila: number | null;
  columna: string | null;
  celda: string | null;
  valor_recibido: string | null;
  confirmar?: boolean;      // advertencia que RH debe confirmar antes de aplicar (v5)
}

/** Encabezado del Excel que la plataforma no reconoció, con las columnas que más se le parecen. */
export interface ColumnaDesconocida {
  columna: string;          // letra en el Excel ("K")
  encabezado: string;       // "# Faltas Q"
  sugerencias: { clave: string; etiqueta: string }[];
}

/** Quincena: fechas + (si el Excel trae el bloque de arriba) Ejercicio y número de periodo. */
export interface PeriodoCarga {
  inicio: string; fin: string; etiqueta: string;
  ejercicio?: number | null; numero?: number | null; tipo?: string | null;
}

export interface Carga {
  id: number;
  archivo: string;
  usuario?: string;
  estatus: 'CON_ERRORES' | 'LISTA' | 'APLICADA' | 'FALLIDA' | 'DESCARTADA';
  modo: 'REEMPLAZAR' | 'MANTENER' | null;
  periodo: PeriodoCarga | null;
  hoja?: string | null;     // hoja del libro que se leyó
  hojas?: string[];         // hojas visibles del libro (para elegir otra)
  filas_total: number;
  num_errores: number;
  num_advertencias: number;
  resumen: Record<string, number> | null;
  subida: string;
  aplicada: string | null;
  mensajes?: MensajeCarga[];
  desconocidas?: ColumnaDesconocida[];
  requiere_confirmacion?: boolean;
}

export interface Impacto {
  nuevos: number; actualizados: number; restaurados: number; eliminados: number; omitidos: number;
  detalle: Record<string, string[]>;
}

export interface Previsualizacion {
  periodo: PeriodoCarga;
  requiere_confirmacion?: boolean;
  quincena_nueva: boolean;
  columnas_ausentes: string[];
  modo_predeterminado: 'REEMPLAZAR' | 'MANTENER';
  impacto: Record<'REEMPLAZAR' | 'MANTENER', Impacto>;
}

/** v6: reporte con la estructura del archivo de RH (impresión; el Excel/PDF usan la misma). */
export interface ReporteRh {
  encabezado: { tipo: string | null; ejercicio: number | null; numero: number | null; titulo: string; periodo: string };
  columnas: { clave: string | null; etiqueta: string; tipo: TipoColumna }[];
  filas: (string | number | null)[][];
  col_faltas: number | null;
}

/** v6: una quincena en las gráficas. */
export interface PuntoSerie {
  periodo_id: number; corto: string; nombre: string;
  empleados: number; faltas_total: number; con_faltas: number; pct_con_faltas: number; acreditan_bono: number;
  prima_vacacional: number; bono_3_meses: number; bono_puntualidad: number; bono_asistencia: number;
  domingos_programados: number; domingos_trabajados: number; nuevos: number;
}

export interface Estadisticas {
  periodo: { id: number; nombre: string; ejercicio: number } | null;
  kpis: { empleados: number; faltas_total: number; con_faltas: number; pct_con_faltas: number; monto_bonos: number; acreditan_bono: number; nuevos: number } | null;
  serie: PuntoSerie[];
  top_faltas: { numero_empleado: string; nombre: string; faltas: number; quincenas: number }[];
  por_puesto: { puesto: string; empleados: number }[];
}
