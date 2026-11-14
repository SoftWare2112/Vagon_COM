import { evaluar, type Contexto } from './evaluar';
import { analizar, ErrorSintaxis, type Nodo } from './parser';
import { err, VACIO, valorDeCelda, type Celda, type Valor } from './tipos';

/**
 * Recalcula todas las fórmulas de una hoja.
 *  - Cada fórmula se evalúa una sola vez (memoria) aunque muchas la usen.
 *  - Una fórmula que, directa o indirectamente, se usa a sí misma da #CICLO!.
 *  - Los rangos se resuelven por la posición actual de filas y columnas.
 * Devuelve el valor de cada celda con fórmula y cuáles cambiaron respecto a su último resultado
 * guardado (esos resultados se mandan al servidor junto con el cambio que los provocó).
 */
export interface Estructura {
  filas: number[];      // ids de fila en orden
  columnas: number[];   // ids de columna en orden
}

const clave = (f: number, c: number) => `${f}:${c}`;
const cacheArboles = new Map<string, Nodo | ErrorSintaxis>();

function arbol(fx: string): Nodo | ErrorSintaxis {
  let a = cacheArboles.get(fx);
  if (!a) {
    try { a = analizar(fx); } catch (e) { a = e instanceof ErrorSintaxis ? e : new ErrorSintaxis(String(e)); }
    if (cacheArboles.size > 20000) cacheArboles.clear();
    cacheArboles.set(fx, a);
  }
  return a;
}

/** ¿La fórmula está bien escrita? Devuelve el problema o null. */
export function revisarSintaxis(fxInterna: string): string | null {
  const a = arbol(fxInterna);
  return a instanceof ErrorSintaxis ? a.message : null;
}

export function recalcular(celdas: Map<string, Celda>, est: Estructura, hoy?: () => string): { valores: Map<string, Valor>; cambiadas: string[] } {
  const posFila = new Map(est.filas.map((id, i) => [id, i]));
  const posCol = new Map(est.columnas.map((id, i) => [id, i]));
  const valores = new Map<string, Valor>();
  const enCurso = new Set<string>();

  const valor = (f: number, c: number): Valor => {
    if (!posFila.has(f) || !posCol.has(c)) return err('#REF!');
    const k = clave(f, c);
    const celda = celdas.get(k);
    if (!celda || celda.t !== 'formula') return valorDeCelda(celda);
    const listo = valores.get(k);
    if (listo) return listo;
    if (enCurso.has(k)) return err('#CICLO!');
    enCurso.add(k);
    const a = arbol(celda.fx ?? '');
    const v = a instanceof ErrorSintaxis ? err('#NOMBRE?') : evaluar(a, ctx);
    enCurso.delete(k);
    valores.set(k, v);
    return v;
  };

  const rango: Contexto['rango'] = (a, b) => {
    const [f1, f2, c1, c2] = [posFila.get(a.f), posFila.get(b.f), posCol.get(a.c), posCol.get(b.c)];
    if (f1 === undefined || f2 === undefined || c1 === undefined || c2 === undefined) return null;
    const out: Valor[] = [];
    for (let i = Math.min(f1, f2); i <= Math.max(f1, f2); i++) {
      for (let j = Math.min(c1, c2); j <= Math.max(c1, c2); j++) out.push(valor(est.filas[i], est.columnas[j]));
    }
    return out;
  };

  const ctx: Contexto = { valor, rango, hoy };
  const cambiadas: string[] = [];
  for (const [k, celda] of celdas) {
    if (celda.t !== 'formula') continue;
    const v = valor(celda.f, celda.c);
    if (!igual(v, valorDeCelda(celda))) cambiadas.push(k);
  }
  return { valores, cambiadas };
}

function igual(a: Valor, b: Valor): boolean {
  if (a.t !== b.t) return false;
  if (a.t === 'vacio') return true;
  if (a.t === 'numero' && b.t === 'numero') return Math.abs(a.v - b.v) < 1e-9 * Math.max(1, Math.abs(a.v));
  return (a as { v: unknown }).v === (b as { v: unknown }).v;
}

export { VACIO };
