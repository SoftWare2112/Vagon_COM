const mxn = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

export const dinero = (v: number | null | undefined) => (v == null ? '—' : mxn.format(v));
export const numero = (v: number | null | undefined, decimales?: number) =>
  v == null ? '—' : decimales === undefined ? String(Number(v)) : Number(v).toLocaleString('es-MX', { minimumFractionDigits: decimales, maximumFractionDigits: decimales });

/** "2026-11-15" -> "15/11/2026" */
export const fecha = (iso: string | null | undefined) => (iso ? iso.slice(0, 10).split('-').reverse().join('/') : '—');

/** "2026-11-15" -> "15 nov 2026" */
export const fechaCorta = (iso: string | null | undefined) =>
  iso ? new Date(`${iso.slice(0, 10)}T12:00:00`).toLocaleDateString('es-MX', { day: 'numeric', month: 'short', year: 'numeric' }).replace('.', '') : '—';

/** Fecha de hoy en formato del sistema (YYYY-MM-DD, hora local). */
export function hoyISO(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export const CONTRATOS = { capacitacion: 'Capacitación', prueba: 'Periodo de prueba', temporal: 'Temporal', indeterminado: 'Indeterminado' } as const;
export const PERIODICIDADES = { semanal: 'Semanal', quincenal: 'Quincenal', mensual: 'Mensual' } as const;
export const DIAS_SEMANA = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
