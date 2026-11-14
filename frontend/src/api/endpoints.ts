import { http, csrf } from './http';
import type {
  Carga, Columna, ColumnaCatalogo, Estadisticas, FiltrosHistorial, HojaDatos, HojaGuardado, HojaPlantillaResumen, LibroDetalle, LibroResumen, ReporteCambioTipo, ReporteRh, Fila, Filtros, Paginado, Permiso, Previsualizacion, Resumen,
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

/** v8: hojas de cálculo. */
/** v9: libros personales (cada usuario ve los suyos; hojas.ver_todas ve los de todos). */
export const librosApi = {
  listar: async (p: { q?: string; todos?: boolean } = {}) =>
    (await http.get<LibroResumen[]>('/api/libros', { params: limpiar({ q: p.q, todos: p.todos ? 1 : undefined }) })).data,
  personal: async () => (await http.get<LibroDetalle>('/api/libros/personal')).data,
  crear: async (nombre: string, plantillaId?: number) => (await http.post<LibroDetalle>('/api/libros', { nombre, plantilla_id: plantillaId })).data,
  importar: async (archivo: File, nombre?: string, onProgreso?: (pct: number) => void) => {
    const fd = new FormData();
    fd.append('archivo', archivo);
    if (nombre) fd.append('nombre', nombre);
    return (await http.post<LibroDetalle>('/api/libros/importar', fd, {
      timeout: 180_000,
      onUploadProgress: (e) => e.total && onProgreso?.(Math.round((e.loaded / e.total) * 100)),
    })).data;
  },
  obtener: async (id: number) => (await http.get<LibroDetalle>(`/api/libros/${id}`)).data,
  renombrar: (id: number, nombre: string) => http.patch(`/api/libros/${id}`, { nombre }),
  eliminar: (id: number) => http.delete(`/api/libros/${id}`),
  agregarHoja: async (id: number) => (await http.post<LibroDetalle>(`/api/libros/${id}/hojas`, {})).data,
};

export const hojasApi = {
  plantillas: async () => (await http.get<HojaPlantillaResumen[]>('/api/hoja-plantillas')).data,
  obtener: async (id: number) => (await http.get<HojaDatos>(`/api/hojas/${id}`)).data,
  /** Renombra la pestaña. */
  renombrar: (id: number, nombre: string) => http.patch(`/api/hojas/${id}`, { nombre }),
  /** Borra la pestaña y devuelve el libro actualizado. */
  eliminar: async (id: number) => (await http.delete<LibroDetalle>(`/api/hojas/${id}`)).data,
  guardar: async (id: number, cambios: unknown[], formatos: unknown[]) =>
    (await http.patch<HojaGuardado>(`/api/hojas/${id}/celdas`, { cambios, formatos })).data,
  /** despuesDe: id de fila; 0 = al inicio; null = al final. */
  insertarFilas: async (id: number, despuesDe: number | null, cantidad = 1) =>
    (await http.post<HojaDatos>(`/api/hojas/${id}/filas`, despuesDe === 0 ? { al_inicio: true, cantidad } : { despues_de: despuesDe, cantidad })).data,
  eliminarFila: async (id: number, fila: number) => (await http.delete<HojaDatos>(`/api/hojas/${id}/filas/${fila}`)).data,
  insertarColumnas: async (id: number, despuesDe: number | null, cantidad = 1) =>
    (await http.post<HojaDatos>(`/api/hojas/${id}/columnas`, despuesDe === 0 ? { al_inicio: true, cantidad } : { despues_de: despuesDe, cantidad })).data,
  eliminarColumna: async (id: number, columna: number) => (await http.delete<HojaDatos>(`/api/hojas/${id}/columnas/${columna}`)).data,
  ancho: (id: number, columna: number, ancho: number) => http.patch(`/api/hojas/${id}/columnas/${columna}`, { ancho }),
  // v9: combinar celdas e imágenes
  combinar: async (id: number, r: { f: number; c: number; f2: number; c2: number }) => (await http.post<HojaDatos>(`/api/hojas/${id}/combinar`, r)).data,
  separar: async (id: number, combinada: number) => (await http.delete<HojaDatos>(`/api/hojas/${id}/combinadas/${combinada}`)).data,
  eliminarImagen: (id: number, imagen: number) => http.delete(`/api/hojas/${id}/imagenes/${imagen}`),
  /** La imagen se pide con la sesión y se muestra como blob (no hay enlaces públicos). */
  imagen: async (url: string) => (await http.get<Blob>(url, { responseType: 'blob' })).data,
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
  /** v9: historial de importaciones (nómina y libros) con búsqueda, filtros, orden y papelera. */
  historial: async (f: FiltrosHistorial = {}) =>
    (await http.get<Paginado<Carga>>('/api/cargas', { params: limpiar({ ...f, papelera: f.papelera ? 1 : undefined }) })).data,
  quitar: async (id: number, conLibro = false) =>
    (await http.delete<{ message: string }>(`/api/cargas/${id}`, { params: conLibro ? { con_libro: 1 } : {} })).data,
  restaurar: async (id: number) => (await http.post<{ message: string }>(`/api/cargas/${id}/restaurar`)).data,
  limpiar: async (alcance: 'mias' | 'todas', tipo?: 'nomina' | 'libro') =>
    (await http.post<{ message: string; cantidad: number }>('/api/cargas/limpiar', { alcance, tipo })).data,
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
  /** v7: el mismo Excel con las celdas con problema marcadas y una hoja con la lista. */
  descargarErrores: async (id: number, archivo: string) => {
    const r = await http.get(`/api/cargas/${id}/errores`, { responseType: 'blob' });
    const url = URL.createObjectURL(r.data as Blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `errores_${archivo.replace(/\.[^.]+$/, '')}.xlsx`;
    a.click();
    URL.revokeObjectURL(url);
  },
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
