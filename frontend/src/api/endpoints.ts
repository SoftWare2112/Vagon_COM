import { http, csrf } from './http';
import type {
  Carga, Columna, ColumnaCatalogo, Estadisticas, ReporteCambioTipo, ReporteRh, Fila, Filtros, Paginado, Permiso, Previsualizacion, Resumen,
  RolesRespuesta, TablaRespuesta, TipoColumna, Usuario,
} from '../types';

/** Capa de servicios: los componentes nunca escriben URLs directamente. */
const limpiar = (f: object) => Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v != null));

export const authApi = {
  login: async (email: string, password: string) => {
    await csrf();
    return (await http.post<{ user: Usuario }>('/api/auth/login', { email, password })).data.user;
  },
  logout: () => http.post('/api/auth/logout'),
  me: async () => (await http.get<{ data: Usuario }>('/api/auth/me')).data.data,
  olvide: async (email: string) => {
    await csrf();
    return (await http.post<{ message: string }>('/api/auth/olvide', { email })).data.message;
  },
  restablecer: async (p: { token: string; email: string; password: string; password_confirmation: string }) => {
    await csrf();
    return (await http.post<{ message: string }>('/api/auth/restablecer', p)).data.message;
  },
  cambiarPassword: (p: { password_actual: string; password: string; password_confirmation: string }) =>
    http.put('/api/auth/password', p),
};

export const registrosApi = {
  tabla: async (f: Filtros) => (await http.get<TablaRespuesta>('/api/registros', { params: limpiar(f) })).data,
  /** v6: toda la tabla (sin paginar) con la estructura del archivo de RH, para imprimir. */
  impresion: async (f: Filtros) => (await http.get<ReporteRh>('/api/registros/impresion', { params: limpiar({ ...f, page: undefined }) })).data,
  resumen: async (registroId: number) => (await http.get<Resumen>(`/api/registros/${registroId}/resumen`)).data,
  /** Solo se envían las columnas que cambiaron; `version` = la que vio el usuario (bloqueo optimista). */
  actualizar: async (registroId: number, version: number, valores: Fila['valores']) =>
    (await http.patch<Fila>(`/api/registros/${registroId}`, { version, valores })).data,
  eliminar: (registroId: number) => http.delete<{ message: string }>(`/api/registros/${registroId}`),
  crearEmpleado: (p: { nombre_completo: string; puesto?: string; fecha_ingreso: string; periodo_id?: number }) =>
    http.post('/api/empleados', p),
};

/** v6: datos de la pantalla Gráficas. */
export const estadisticasApi = {
  obtener: async (periodoId?: number) => (await http.get<Estadisticas>('/api/estadisticas', { params: limpiar({ periodo_id: periodoId }) })).data,
};

export const catalogosApi = {
  puestos: async () => (await http.get<{ id: number; nombre: string }[]>('/api/puestos')).data,
  periodos: async () => (await http.get<{ id: number; etiqueta: string }[]>('/api/periodos')).data,
};

export const cargasApi = {
  historial: async () => (await http.get<Paginado<Carga>>('/api/cargas')).data,
  /** periodo: fechas dd/mm/aaaa que RH escribe cuando el encabezado del periodo está mal o no viene. */
  subir: async (archivo: File, anio: number, onProgreso?: (pct: number) => void, hoja?: string, periodo?: { inicio: string; fin: string }) => {
    const fd = new FormData();
    fd.append('archivo', archivo);
    fd.append('anio', String(anio));
    if (hoja) fd.append('hoja', hoja);
    if (periodo) {
      fd.append('periodo_inicio', periodo.inicio);
      fd.append('periodo_fin', periodo.fin);
    }
    const r = await http.post<{ data: Carga }>('/api/cargas', fd, {
      onUploadProgress: (e) => e.total && onProgreso?.(Math.round((e.loaded / e.total) * 100)),
    });
    return r.data.data;
  },
  detalle: async (id: number) => (await http.get<{ data: Carga }>(`/api/cargas/${id}`)).data.data,
  previsualizar: async (id: number) => (await http.get<Previsualizacion>(`/api/cargas/${id}/previsualizar`)).data,
  /** confirmar: RH revisó las advertencias marcadas (columnas que faltan, domingos, duración del periodo). */
  aplicar: async (id: number, modo: 'REEMPLAZAR' | 'MANTENER', confirmar = false) =>
    (await http.post<{ message: string; resumen: Record<string, number> }>(`/api/cargas/${id}/aplicar`, { modo, confirmar })).data,
  descartar: (id: number) => http.post(`/api/cargas/${id}/descartar`),
};

export const exportacionesApi = {
  generar: async (formato: 'PDF' | 'EXCEL', f: Filtros) =>
    (await http.post<{ id: number; nombre: string }>('/api/exportaciones', { formato, ...limpiar(f) })).data,
  historial: async () =>
    (await http.get<Paginado<{ id: number; nombre: string; formato: string; filas: number; usuario: string; fecha: string }>>('/api/exportaciones')).data,
  /** Descarga como blob (la ruta exige sesión; no hay URLs públicas). */
  descargar: async (id: number, nombre: string) => {
    const r = await http.get(`/api/exportaciones/${id}/descargar`, { responseType: 'blob' });
    const url = URL.createObjectURL(r.data as Blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = nombre;
    a.click();
    URL.revokeObjectURL(url);
  },
};

export const historicoApi = {
  listar: async (f: object) => (await http.get<Paginado<{
    id: number; numero_empleado: string; nombre_completo: string; periodo: string;
    eliminado: string; eliminado_por: string; motivo: string; columnas: Columna[]; valores: Fila['valores'];
  }>>('/api/historico', { params: limpiar(f) })).data,
  restaurar: (id: number) => http.post<{ message: string }>(`/api/historico/${id}/restaurar`),
};

export const usuariosApi = {
  listar: async () => (await http.get<{ data: Usuario[] }>('/api/usuarios')).data.data,
  crear: (p: { name: string; email: string; rol_id: number }) => http.post('/api/usuarios', p),
  actualizar: (id: number, p: Partial<{ name: string; rol_id: number; is_active: boolean }>) => http.put(`/api/usuarios/${id}`, p),
  desbloquear: (id: number) => http.post(`/api/usuarios/${id}/desbloquear`),
  desactivar: (id: number) => http.delete(`/api/usuarios/${id}`),
};

export const ajustesApi = {
  obtener: async () => (await http.get<Record<string, string>>('/api/ajustes')).data,
  guardar: async (p: Record<string, string>) => (await http.put<Record<string, string>>('/api/ajustes', p)).data,
};

export const columnasApi = {
  listar: async () => (await http.get<ColumnaCatalogo[]>('/api/columnas')).data,
  crear: async (p: { etiqueta: string; tipo: TipoColumna; despues_de?: string; antes_de?: string; alias?: string[] }) =>
    (await http.post<ColumnaCatalogo>('/api/columnas', p)).data,
  actualizar: (id: number, p: Partial<Pick<ColumnaCatalogo, 'etiqueta' | 'alias' | 'visible'>>) => http.patch(`/api/columnas/${id}`, p),
  /** Simula (simular=true) o aplica el cambio de tipo; convierte los valores ya guardados. */
  cambiarTipo: async (id: number, tipo: TipoColumna, opciones: { simular?: boolean; forzar?: boolean }) =>
    (await http.patch<ReporteCambioTipo>(`/api/columnas/${id}/tipo`, { tipo, ...opciones })).data,
  mover: async (id: number, orden: number) => (await http.post<ColumnaCatalogo[]>(`/api/columnas/${id}/mover`, { orden })).data,
  activarDesactivar: (id: number) => http.delete(`/api/columnas/${id}`),
  /** Mapeo asistido: el encabezado del Excel se guarda como otro nombre de esta columna. */
  agregarAlias: async (id: number, alias: string) => (await http.post<ColumnaCatalogo>(`/api/columnas/${id}/alias`, { alias })).data,
};

export const rolesApi = {
  listar: async () => (await http.get<RolesRespuesta>('/api/roles')).data,
  guardarPermisos: async (rolId: number, permisos: Permiso[]) =>
    (await http.put<RolesRespuesta>(`/api/roles/${rolId}/permisos`, { permisos })).data,
};
