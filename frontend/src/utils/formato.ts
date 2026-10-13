import type { TipoColumna } from '../types';

const mxn = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

export const dinero = (v: number | null | undefined) => (v == null ? '—' : mxn.format(v));
export const numero = (v: number | null | undefined) => (v == null ? '—' : String(Number(v)));

/** Valida dd/mm/aaaa (formato pedido por RH para filtros y capturas). */
export function esFechaValida(txt: string): boolean {
  const m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(txt);
  if (!m) return false;
  const [d, mes, a] = [Number(m[1]), Number(m[2]), Number(m[3])];
  const f = new Date(a, mes - 1, d);
  return f.getFullYear() === a && f.getMonth() === mes - 1 && f.getDate() === d;
}

/** Autoformatea mientras se escribe: "15012018" -> "15/01/2018". */
export function mascaraFecha(txt: string): string {
  const s = txt.replace(/\D/g, '').slice(0, 8);
  return [s.slice(0, 2), s.slice(2, 4), s.slice(4)].filter(Boolean).join('/');
}

/** Muestra un valor según el tipo de su columna. */
export function formatear(v: unknown, tipo: TipoColumna): string {
  if (v === null || v === undefined || v === '') return '';
  if (tipo === 'dinero') return dinero(Number(v));
  if (tipo === 'booleano') return v ? 'Sí' : 'No';
  if (tipo === 'decimal' || tipo === 'entero') return numero(Number(v));
  return String(v);
}

export const textoFaltas = (n: number) => (n === 0 ? 'Sin faltas' : n === 1 ? '1 falta' : `${n} faltas`);
