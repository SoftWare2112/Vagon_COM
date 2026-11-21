import { http, csrf } from './http';
import type {
  Acumulados, AjusteAnualRespuesta, EnviosPeriodo, ResultadoEnvio, SaldoVacaciones, TipoPeriodo, Alerta, CalculoNomina, Captura, ConceptoNomina, IndicadoresRespuesta, ListaDeRaya, PeriodoHistorial, Recibo, ReciboLista, Respaldo, DatosEmpleado, DatosPublicacion, Empleado, EmpleadoResumen, Empresa, FiltrosPublicaciones,
  Paginado, PeriodoNomina, Periodicidad, Permiso, Publicacion, RolesRespuesta, TablaFiscal, TipoContrato, Usuario,
  EstadoTimbrado, EventoCfdi, ModoCfdi, ResultadoImportacion, ResultadoTimbradoPeriodo,
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

export const usuariosApi = {
  listar: async () => (await http.get<{ data: Usuario[] }>('/api/usuarios')).data.data,
  crear: (p: { name: string; email: string; rol_id: number }) => http.post('/api/usuarios', p),
  actualizar: (id: number, p: Partial<{ name: string; rol_id: number; is_active: boolean }>) => http.put(`/api/usuarios/${id}`, p),
  desbloquear: (id: number) => http.post(`/api/usuarios/${id}/desbloquear`),
  desactivar: (id: number) => http.delete(`/api/usuarios/${id}`),
};

/** v11: expediente de empleados. */
export const empleadosApi = {
  listar: async (f: { q?: string; estado?: 'activo' | 'baja' | 'todos'; puesto_id?: number; incompletos?: boolean } = {}) =>
    (await http.get<EmpleadoResumen[]>('/api/empleados', { params: limpiar({ ...f, incompletos: f.incompletos ? 1 : undefined }) })).data,
  obtener: async (id: number) => (await http.get<Empleado>(`/api/empleados/${id}`)).data,
  crear: async (d: DatosEmpleado) => (await http.post<Empleado>('/api/empleados', d)).data,
  actualizar: async (id: number, d: DatosEmpleado) => (await http.patch<Empleado>(`/api/empleados/${id}`, d)).data,
  baja: async (id: number, fecha: string, motivo: string) => (await http.post<Empleado>(`/api/empleados/${id}/baja`, { fecha, motivo })).data,
  reactivar: async (id: number, fecha?: string) => (await http.post<Empleado>(`/api/empleados/${id}/reactivar`, { fecha })).data,
  salario: async (id: number, d: { salario_mensual: number; desde: string; motivo?: string }) => (await http.post<Empleado>(`/api/empleados/${id}/salario`, d)).data,
  contrato: async (id: number, d: { tipo: TipoContrato; inicio: string; fin?: string | null; notas?: string }) =>
    (await http.post<Empleado>(`/api/empleados/${id}/contrato`, d)).data,
  simular: async (id: number, p: { fecha?: string; faltas?: number; retardos?: number; domingos?: number } = {}) =>
    (await http.get<CalculoNomina>(`/api/empleados/${id}/simular`, { params: limpiar(p) })).data,
  alertas: async () => (await http.get<Alerta[]>('/api/empleados/alertas')).data,
};

/** v11: configuración de nómina. */
export const nominaApi = {
  puestos: async () => (await http.get<{ id: number; nombre: string }[]>('/api/puestos')).data,
  crearPuesto: async (nombre: string) => (await http.post<{ id: number; nombre: string }>('/api/puestos', { nombre })).data,
  empresa: async () => (await http.get<Empresa>('/api/nomina/empresa')).data,
  guardarEmpresa: async (d: Partial<Empresa>) => (await http.put<Empresa>('/api/nomina/empresa', d)).data,
  conceptos: async () => (await http.get<ConceptoNomina[]>('/api/nomina/conceptos')).data,
  crearConcepto: async (d: Partial<ConceptoNomina>) => (await http.post<ConceptoNomina>('/api/nomina/conceptos', d)).data,
  actualizarConcepto: async (id: number, d: Partial<ConceptoNomina>) => (await http.patch<ConceptoNomina>(`/api/nomina/conceptos/${id}`, d)).data,
  eliminarConcepto: (id: number) => http.delete(`/api/nomina/conceptos/${id}`),
  tablas: async () => (await http.get<TablaFiscal[]>('/api/nomina/tablas')).data,
  guardarTabla: async (d: { tipo: string; vigente_desde: string; datos: unknown; fuente?: string }) => (await http.post<TablaFiscal>('/api/nomina/tablas', d)).data,
  eliminarTabla: (id: number) => http.delete(`/api/nomina/tablas/${id}`),
  periodos: async (tipo: TipoPeriodo, ejercicio: number) => (await http.get<PeriodoNomina[]>('/api/nomina/periodos', { params: { tipo, ejercicio } })).data,
  generarPeriodos: async (d: { tipo: Periodicidad; ejercicio: number; primer_dia?: string }) =>
    (await http.post<{ message: string; nuevos: number }>('/api/nomina/periodos/generar', d)).data,
  fechaPago: (id: number, fecha_pago: string) => http.patch(`/api/nomina/periodos/${id}`, { fecha_pago }),
};

/** v12: nómina del periodo, historial, sobre-recibos, reportes y respaldos. */
export type FilaIncidencia = { empleado_id: number; faltas?: number; retardos?: number; domingos?: number; capturados?: Record<string, number>; notas?: string | null };
export const nominaPeriodoApi = {
  historial: async (ejercicio: number, tipo: TipoPeriodo) => (await http.get<PeriodoHistorial[]>('/api/nomina/historial', { params: { ejercicio, tipo } })).data,
  captura: async (id: number) => (await http.get<Captura>(`/api/nomina/periodos/${id}/captura`)).data,
  incidencias: async (id: number, filas: FilaIncidencia[]) => (await http.put<Captura>(`/api/nomina/periodos/${id}/incidencias`, { filas })).data,
  calcular: async (id: number) => (await http.post<{ captura: Captura; avisos: string[] }>(`/api/nomina/periodos/${id}/calcular`)).data,
  autorizar: async (id: number) => (await http.post<{ message: string; captura: Captura }>(`/api/nomina/periodos/${id}/autorizar`, { confirmar: true })).data,
  reabrir: async (id: number, motivo: string) => (await http.post<{ message: string; captura: Captura }>(`/api/nomina/periodos/${id}/reabrir`, { motivo })).data,
  recibos: async (empleado_id: number) => (await http.get<ReciboLista[]>('/api/nomina/recibos', { params: { empleado_id } })).data,
  recibo: async (id: number) => (await http.get<Recibo>(`/api/nomina/recibos/${id}`)).data,
  listaDeRaya: async (periodoId: number) => (await http.get<ListaDeRaya>(`/api/nomina/reportes/lista-de-raya/${periodoId}`)).data,
  acumulados: async (p: { ejercicio: number; tipo: TipoPeriodo; empleado_id?: number }) => (await http.get<Acumulados>('/api/nomina/reportes/acumulados', { params: limpiar(p) })).data,
  respaldos: async () => (await http.get<Respaldo[]>('/api/nomina/respaldos')).data,
  crearRespaldo: async (motivo: string) => (await http.post<Respaldo>('/api/nomina/respaldos', { motivo })).data,
  descargarRespaldo: (archivo: string) => descargarArchivo(`/api/nomina/respaldos/${archivo}`, archivo),
  // v13
  crearAguinaldo: async (ejercicio: number, fecha_pago: string) => (await http.post<PeriodoNomina>('/api/nomina/aguinaldo', { ejercicio, fecha_pago })).data,
  pdf: (id: number, nombre: string) => descargarArchivo(`/api/nomina/recibos/${id}/pdf`, nombre),
  pdfPeriodo: (id: number, nombre: string) => descargarArchivo(`/api/nomina/periodos/${id}/recibos-pdf`, nombre),
  enviarRecibo: async (id: number) => (await http.post<{ estado: string; email?: string; error?: string }>(`/api/nomina/recibos/${id}/enviar`)).data,
  enviarPeriodo: async (id: number) => (await http.post<ResultadoEnvio>(`/api/nomina/periodos/${id}/enviar-recibos`)).data,
  envios: async (id: number) => (await http.get<EnviosPeriodo>(`/api/nomina/periodos/${id}/envios`)).data,
  reconstruir: async (ejercicio?: number) =>
    (await http.post<{ recibos: number; alterados: { recibo_id: number; empleado: string; periodo: string }[]; acumulados: number }>('/api/nomina/reconstruir', limpiar({ ejercicio }))).data,
};

/** v14: CFDI de nómina (timbrado opcional, CSD, PAC, cancelación, XML e importación). */
export const cfdiApi = {
  configuracion: async () => (await http.get<EstadoTimbrado>('/api/nomina/cfdi/configuracion')).data,
  guardar: async (d: Record<string, unknown>) => (await http.put<EstadoTimbrado>('/api/nomina/cfdi/configuracion', d)).data,
  cargarCsd: async (cer: File, key: File, password: string) => {
    const fd = new FormData();
    fd.append('cer', cer); fd.append('key', key); fd.append('password', password);
    return (await http.post<{ csd: unknown; estado: EstadoTimbrado }>('/api/nomina/cfdi/csd', fd)).data;
  },
  probar: async () => (await http.post<{ ok: boolean; mensaje: string }>('/api/nomina/cfdi/probar')).data,
  validar: async (reciboId: number) => (await http.get<{ listo: boolean; errores: string[] }>(`/api/nomina/cfdi/recibos/${reciboId}/validar`)).data,
  timbrarRecibo: async (reciboId: number) => (await http.post<{ recibo_id: number; uuid: string; modo: ModoCfdi; total: number }>(`/api/nomina/cfdi/recibos/${reciboId}/timbrar`, {}, { timeout: 120_000 })).data,
  timbrarPeriodo: async (periodoId: number) => (await http.post<ResultadoTimbradoPeriodo>(`/api/nomina/cfdi/periodos/${periodoId}/timbrar`, {}, { timeout: 600_000 })).data,
  cancelar: async (reciboId: number, motivo: string, folio_sustitucion?: string) =>
    (await http.post<{ uuid: string; estado: string; mensaje: string }>(`/api/nomina/cfdi/recibos/${reciboId}/cancelar`, limpiar({ motivo, folio_sustitucion }), { timeout: 120_000 })).data,
  xml: (reciboId: number, nombre: string) => descargarArchivo(`/api/nomina/recibos/${reciboId}/xml`, nombre),
  importar: async (archivos: File[], aplicar: boolean, forzar: string[] = [], onProgreso?: (pct: number) => void) => {
    const fd = new FormData();
    archivos.forEach((a) => fd.append('archivos[]', a));
    fd.append('aplicar', aplicar ? '1' : '0');
    forzar.forEach((u) => fd.append('forzar[]', u));
    return (await http.post<ResultadoImportacion>('/api/nomina/cfdi/importar', fd, {
      timeout: 600_000, onUploadProgress: (e) => e.total && onProgreso?.(Math.round((e.loaded / e.total) * 100)),
    })).data;
  },
  eventos: async (p: { recibo_id?: number; tipo?: string } = {}) => (await http.get<EventoCfdi[]>('/api/nomina/cfdi/eventos', { params: limpiar(p) })).data,
};

/** v13: vacaciones del empleado y cálculo anual de ISR. */
export const vacacionesApi = {
  obtener: async (empleadoId: number) => (await http.get<SaldoVacaciones>(`/api/empleados/${empleadoId}/vacaciones`)).data,
  registrar: async (empleadoId: number, d: { desde: string; hasta: string; dias: number; notas?: string }) =>
    (await http.post<SaldoVacaciones>(`/api/empleados/${empleadoId}/vacaciones`, d)).data,
  cancelar: async (id: number) => (await http.delete<SaldoVacaciones>(`/api/vacaciones/${id}`)).data,
};
export const ajusteAnualApi = {
  obtener: async (ejercicio: number) => (await http.get<AjusteAnualRespuesta>('/api/nomina/ajuste-anual', { params: { ejercicio } })).data,
  calcular: async (ejercicio: number) => (await http.post<AjusteAnualRespuesta>('/api/nomina/ajuste-anual/calcular', { ejercicio })).data,
  declaracion: async (id: number, declara_por_su_cuenta: boolean) => (await http.patch<AjusteAnualRespuesta>(`/api/nomina/ajuste-anual/${id}`, { declara_por_su_cuenta })).data,
  aplicar: async (ejercicio: number, periodo_id: number) =>
    (await http.post<{ aplicados: number; fuera: string[]; periodo: string; ajuste: AjusteAnualRespuesta }>('/api/nomina/ajuste-anual/aplicar', { ejercicio, periodo_id })).data,
  quitar: async (ejercicio: number) => (await http.post<AjusteAnualRespuesta>('/api/nomina/ajuste-anual/quitar', { ejercicio })).data,
};

export const indicadoresApi = {
  obtener: async (ejercicio: number, agrupar: 'quincena' | 'mes') => (await http.get<IndicadoresRespuesta>('/api/indicadores', { params: { ejercicio, agrupar } })).data,
};

export const rolesApi = {
  listar: async () => (await http.get<RolesRespuesta>('/api/roles')).data,
  guardarPermisos: async (rolId: number, permisos: Permiso[]) =>
    (await http.put<RolesRespuesta>(`/api/roles/${rolId}/permisos`, { permisos })).data,
};

/** Archivo protegido (se pide con la sesión) como Blob, para mostrarlo con URL.createObjectURL. */
export const archivoBlob = async (url: string) => (await http.get<Blob>(url, { responseType: 'blob' })).data;

/** Descarga un archivo protegido con su nombre. */
export async function descargarArchivo(url: string, nombre: string) {
  const blob = await archivoBlob(url);
  const u = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = u; a.download = nombre; a.click();
  setTimeout(() => URL.revokeObjectURL(u), 1000);
}

/** v10: publicaciones (texto, documentos, imágenes, enlaces a video y trabajos de la plataforma). */
export const publicacionesApi = {
  listar: async (f: FiltrosPublicaciones = {}) => (await http.get<Paginado<Publicacion>>('/api/publicaciones', { params: limpiar(f) })).data,
  sinLeer: async () => (await http.get<{ total: number }>('/api/publicaciones/sin-leer')).data.total,
  categorias: async () => (await http.get<{ categoria: string; total: number }[]>('/api/publicaciones/categorias')).data,
  audiencias: async () => (await http.get<{ roles: { nombre: string; descripcion: string }[]; usuarios: { id: number; name: string }[] }>('/api/publicaciones/audiencias')).data,
  obtener: async (id: number) => (await http.get<Publicacion>(`/api/publicaciones/${id}`)).data,
  /** Nueva publicación con sus archivos (multipart). */
  crear: async (d: DatosPublicacion, archivos: File[], onProgreso?: (pct: number) => void) => {
    const fd = new FormData();
    for (const [k, v] of Object.entries(d)) {
      if (v === undefined || v === null || v === '') continue;
      if (Array.isArray(v)) v.forEach((x) => fd.append(`${k}[]`, String(x)));
      else fd.append(k, typeof v === 'boolean' ? (v ? '1' : '0') : String(v));
    }
    archivos.forEach((a) => fd.append('adjuntos[]', a));
    return (await http.post<Publicacion>('/api/publicaciones', fd, {
      timeout: 300_000, onUploadProgress: (e) => e.total && onProgreso?.(Math.round((e.loaded / e.total) * 100)),
    })).data;
  },
  actualizar: async (id: number, d: DatosPublicacion) => (await http.patch<Publicacion>(`/api/publicaciones/${id}`, d)).data,
  eliminar: (id: number) => http.delete(`/api/publicaciones/${id}`),
  publicar: async (id: number) => (await http.post<Publicacion>(`/api/publicaciones/${id}/publicar`)).data,
  archivar: async (id: number) => (await http.post<Publicacion>(`/api/publicaciones/${id}/archivar`)).data,
  moderar: async (id: number, d: { fijada?: boolean; oficial?: boolean }) => (await http.post<Publicacion>(`/api/publicaciones/${id}/moderar`, d)).data,
  favorita: async (id: number) => (await http.post<{ favorita: boolean }>(`/api/publicaciones/${id}/favorita`)).data.favorita,
  agregarAdjuntos: async (id: number, archivos: File[]) => {
    const fd = new FormData();
    archivos.forEach((a) => fd.append('adjuntos[]', a));
    return (await http.post<Publicacion>(`/api/publicaciones/${id}/adjuntos`, fd, { timeout: 300_000 })).data;
  },
  quitarAdjunto: async (id: number, adjunto: number) => (await http.delete<Publicacion>(`/api/publicaciones/${id}/adjuntos/${adjunto}`)).data,
  lecturas: async (id: number) => (await http.get<{ id: number; nombre: string; leida: string }[]>(`/api/publicaciones/${id}/lecturas`)).data,
};
