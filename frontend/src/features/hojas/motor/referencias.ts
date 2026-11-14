/**
 * Referencias de celda. El usuario escribe y ve A1 ("=C2*D2"); se GUARDA con ids de fila y columna
 * ("=[f812c45]*[f812c46]"), así insertar o borrar filas no rompe fórmulas. Es la misma conversión que
 * hace el servidor en App\Support\Hojas\ReferenciasFormula (para plantillas).
 */
const A1 = /(?<![A-Za-z0-9_[])\$?([A-Za-z]{1,3})\$?(\d{1,6})(?![A-Za-z0-9_(])/g;
const INTERNA = /\[f(\d+)c(\d+)\]/g;

export function letra(indice: number): string {
  let s = '';
  for (let i = indice + 1; i > 0; i = Math.floor((i - 1) / 26)) s = String.fromCharCode(65 + ((i - 1) % 26)) + s;
  return s;
}

export function indice(letras: string): number {
  let n = 0;
  for (const l of letras.toUpperCase()) n = n * 26 + (l.charCodeAt(0) - 64);
  return n - 1;
}

/** "C5" para la columna 2 y la fila 4 (posiciones desde 0). */
export const nombreCelda = (fila: number, col: number) => `${letra(col)}${fila + 1}`;

/** Aplica fn solo fuera de comillas: "A1" dentro de un texto no es una referencia. */
function fueraDeTextos(fx: string, fn: (tramo: string) => string): string {
  return fx.split(/("(?:[^"]|"")*")/).map((p, i) => (i % 2 === 0 ? fn(p) : p)).join('');
}

export function aInterno(fx: string, filaIds: number[], colIds: number[]): string {
  return fueraDeTextos(fx, (t) => t.replace(A1, (_m, l: string, n: string) => {
    const f = filaIds[Number(n) - 1];
    const c = colIds[indice(l)];
    return f === undefined || c === undefined ? '#REF!' : `[f${f}c${c}]`;
  }));
}

export function aVisible(fx: string, posFila: Map<number, number>, posCol: Map<number, number>): string {
  return fueraDeTextos(fx, (t) => t.replace(INTERNA, (_m, f: string, c: string) => {
    const pf = posFila.get(Number(f));
    const pc = posCol.get(Number(c));
    return pf === undefined || pc === undefined ? '#REF!' : nombreCelda(pf, pc);
  }));
}
