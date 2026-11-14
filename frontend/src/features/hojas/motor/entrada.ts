import { isoDeDMA } from './fechas';
import type { Estilo, TipoCelda } from './tipos';

/** Lo que el usuario escribió, ya con su tipo. `fx` va en A1 (como lo escribió); se pasa a ids al guardar. */
export interface Entrada {
  t: TipoCelda;
  v?: string | number | boolean;
  fx?: string;
  fmt?: Estilo['fmt'];   // formato sugerido: "15%" -> porcentaje, "$1,250" -> moneda
}

const NUMERO = /^([-+])?(\$)?\s*(\d{1,3}(?:,\d{3})+|\d+)?(\.\d+)?\s*(%)?$/;

/**
 * Decide el tipo de lo que se escribe en una celda (como Excel, en formato de México):
 *   =A1*2 fórmula · 1,250.50 / $1,250 / 15% número · 28/10/2026 fecha · 28/10/2026 14:18 fecha y hora
 *   14:18 hora · VERDADERO / FALSO lógico · 'texto (apóstrofo) siempre texto · lo demás, texto.
 */
export function interpretar(texto: string): Entrada {
  const s = texto.trim();
  if (s === '') return { t: 'vacio' };
  if (texto.startsWith("'")) return { t: 'texto', v: texto.slice(1) };
  if (s.startsWith('=') && s.length > 1) return { t: 'formula', fx: s };

  const may = s.toUpperCase();
  if (may === 'VERDADERO' || may === 'TRUE') return { t: 'logico', v: true };
  if (may === 'FALSO' || may === 'FALSE') return { t: 'logico', v: false };

  const n = NUMERO.exec(s);
  if (n && (n[3] || n[4])) {
    let v = Number(`${n[1] ?? ''}${(n[3] ?? '0').replace(/,/g, '')}${n[4] ?? ''}`);
    if (n[5]) v = v / 100;
    if (Number.isFinite(v)) return { t: 'numero', v, fmt: n[5] ? 'porcentaje' : n[2] ? 'moneda' : undefined };
  }

  const fh = /^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:\s+(\d{1,2}):(\d{2}))?$/.exec(s);
  if (fh) {
    const iso = isoDeDMA(Number(fh[1]), Number(fh[2]), Number(fh[3]));
    if (iso && fh[4] === undefined) return { t: 'fecha', v: iso };
    if (iso && Number(fh[4]) < 24 && Number(fh[5]) < 60) return { t: 'fecha_hora', v: `${iso}T${fh[4].padStart(2, '0')}:${fh[5]}` };
  }

  const h = /^(\d{1,2}):(\d{2})$/.exec(s);
  if (h && Number(h[1]) < 24 && Number(h[2]) < 60) return { t: 'hora', v: `${h[1].padStart(2, '0')}:${h[2]}` };

  return { t: 'texto', v: texto };
}
