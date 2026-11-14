/**
 * Fechas como en Excel: número de serie = días desde el 30/12/1899 (así "=A1+30" y "=HOY()-D2" funcionan).
 * Se trabaja en UTC para que la zona horaria no mueva el día.
 */
const BASE = Date.UTC(1899, 11, 30);
const DIA = 86400000;

export function serialDeFecha(iso: string): number {
  const [d, h] = iso.split('T');
  const [a, m, dd] = d.split('-').map(Number);
  let serial = (Date.UTC(a, m - 1, dd) - BASE) / DIA;
  if (h) serial += fraccionDeHora(h);
  return serial;
}

export function fraccionDeHora(hhmm: string): number {
  const [h, m, s] = hhmm.split(':').map(Number);
  return ((h || 0) * 3600 + (m || 0) * 60 + (s || 0)) / 86400;
}

export function fechaDeSerial(serial: number): string {
  const d = new Date(BASE + Math.floor(serial) * DIA);
  return d.toISOString().slice(0, 10);
}

export function fechaHoraDeSerial(serial: number): string {
  const d = new Date(BASE + Math.round(serial * DIA / 60000) * 60000);
  return d.toISOString().slice(0, 16);
}

export function horaDeFraccion(frac: number): string {
  const min = Math.round((frac - Math.floor(frac)) * 1440) % 1440;
  return `${String(Math.floor(min / 60)).padStart(2, '0')}:${String(min % 60).padStart(2, '0')}`;
}

/** dd/mm/aaaa válida -> 'aaaa-mm-dd'; si no, null. */
export function isoDeDMA(d: number, m: number, a: number): string | null {
  const f = new Date(Date.UTC(a, m - 1, d));
  if (f.getUTCFullYear() !== a || f.getUTCMonth() !== m - 1 || f.getUTCDate() !== d) return null;
  return f.toISOString().slice(0, 10);
}

export function hoyISO(ahora = new Date()): string {
  const p = (n: number) => String(n).padStart(2, '0');
  return `${ahora.getFullYear()}-${p(ahora.getMonth() + 1)}-${p(ahora.getDate())}`;
}
