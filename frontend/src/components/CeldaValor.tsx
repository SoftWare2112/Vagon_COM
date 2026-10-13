import { FaltasBadge } from './FaltasBadge';
import { formatear } from '../utils/formato';
import type { Columna } from '../types';

/** Presenta cualquier celda según su columna (semáforo para faltas). */
export function CeldaValor({ col, valor }: { col: Columna; valor: unknown }) {
  if (col.clave === 'num_faltas') return <FaltasBadge faltas={Number(valor ?? 0)} />;
  return <>{formatear(valor, col.tipo)}</>;
}

export const alinear = (col: Columna) =>
  col.tipo === 'dinero' ? 'num' : ['entero', 'decimal', 'booleano'].includes(col.tipo) ? 'centro' : undefined;
