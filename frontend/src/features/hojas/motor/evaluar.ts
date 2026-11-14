import { fechaDeSerial, fechaHoraDeSerial, fraccionDeHora, hoyISO, serialDeFecha } from './fechas';
import type { Nodo } from './parser';
import { err, VACIO, type CodigoError, type Valor } from './tipos';

/** Lo que el evaluador necesita saber de la hoja. */
export interface Contexto {
  valor(f: number, c: number): Valor;                                            // valor ya calculado de una celda
  rango(a: { f: number; c: number }, b: { f: number; c: number }): Valor[] | null; // null = #REF!
  hoy?: () => string;
}

class Falla extends Error {
  constructor(readonly codigo: CodigoError) { super(codigo); }
}

const NORMALIZA_ERROR: Record<string, CodigoError> = { '#VALUE!': '#VALOR!', '#NAME?': '#NOMBRE?' };

/** Evalúa un árbol de fórmula. Nunca lanza: los problemas se devuelven como valor de error (#DIV/0!…). */
export function evaluar(n: Nodo, ctx: Contexto): Valor {
  try {
    const v = ev(n, ctx);
    if (Array.isArray(v)) return err('#VALOR!');   // un rango suelto no es un valor
    if (v.t === 'numero' && !Number.isFinite(v.v)) return err('#NUM!');
    return v;
  } catch (e) {
    if (e instanceof Falla) return err(e.codigo);
    throw e;
  }
}

type R = Valor | Valor[];

function ev(n: Nodo, ctx: Contexto): R {
  switch (n.k) {
    case 'num': return { t: 'numero', v: n.v };
    case 'txt': return { t: 'texto', v: n.v };
    case 'log': return { t: 'logico', v: n.v };
    case 'err': throw new Falla((NORMALIZA_ERROR[n.v] ?? n.v) as CodigoError);
    case 'ref': return falla(ctx.valor(n.f, n.c));
    case 'rango': {
      const r = ctx.rango(n.a, n.b);
      if (!r) throw new Falla('#REF!');
      return r;
    }
    case 'pct': return { t: 'numero', v: num(uno(ev(n.x, ctx))) / 100 };
    case 'un': {
      const x = num(uno(ev(n.x, ctx)));
      return { t: 'numero', v: n.op === '-' ? -x : x };
    }
    case 'bin': return binario(n.op, uno(ev(n.a, ctx)), uno(ev(n.b, ctx)));
    case 'fn': return funcion(n.nombre, n.args, ctx);
  }
}

/** Un error dentro de una celda referida se propaga. */
function falla(v: Valor): Valor {
  if (v.t === 'error') throw new Falla(v.v);
  return v;
}

function uno(r: R): Valor {
  if (Array.isArray(r)) throw new Falla('#VALOR!');
  return r;
}

/** A número, como Excel: vacío = 0, VERDADERO = 1, fechas = número de serie, "3" = 3. */
export function num(v: Valor): number {
  switch (v.t) {
    case 'vacio': return 0;
    case 'numero': return v.v;
    case 'logico': return v.v ? 1 : 0;
    case 'fecha': case 'fecha_hora': return serialDeFecha(v.v);
    case 'hora': return fraccionDeHora(v.v);
    case 'texto': {
      const s = v.v.trim().replace(/,/g, '');
      if (s !== '' && Number.isFinite(Number(s))) return Number(s);
      throw new Falla('#VALOR!');
    }
    case 'error': throw new Falla(v.v);
  }
}

function texto(v: Valor): string {
  switch (v.t) {
    case 'vacio': return '';
    case 'numero': return String(+v.v.toPrecision(15));
    case 'logico': return v.v ? 'VERDADERO' : 'FALSO';
    case 'error': throw new Falla(v.v);
    default: return String(v.v);
  }
}

function binario(op: string, a: Valor, b: Valor): Valor {
  if (op === '&') return { t: 'texto', v: texto(a) + texto(b) };
  if (['=', '<>', '<', '>', '<=', '>='].includes(op)) return { t: 'logico', v: comparar(op, a, b) };

  const x = num(a);
  const y = num(b);
  // Fechas: fecha ± días = fecha; fecha - fecha = días
  const esFecha = (v: Valor) => v.t === 'fecha';
  if (op === '+' && (esFecha(a) !== esFecha(b))) return { t: 'fecha', v: fechaDeSerial(x + y) };
  if (op === '-' && esFecha(a) && !esFecha(b)) return { t: 'fecha', v: fechaDeSerial(x - y) };
  if (op === '+' && (a.t === 'fecha_hora' || b.t === 'fecha_hora')) return { t: 'fecha_hora', v: fechaHoraDeSerial(x + y) };

  switch (op) {
    case '+': return { t: 'numero', v: x + y };
    case '-': return { t: 'numero', v: x - y };
    case '*': return { t: 'numero', v: x * y };
    case '/': if (y === 0) throw new Falla('#DIV/0!'); return { t: 'numero', v: x / y };
    case '^': return { t: 'numero', v: x ** y };
  }
  throw new Falla('#VALOR!');
}

function comparar(op: string, a: Valor, b: Valor): boolean {
  if (a.t === 'error') throw new Falla(a.v);
  if (b.t === 'error') throw new Falla(b.v);
  const vacioComoTexto = (v: Valor, otro: Valor) => (v.t === 'vacio' && otro.t === 'texto' ? { t: 'texto', v: '' } as Valor : v);
  const A = vacioComoTexto(a, b);
  const B = vacioComoTexto(b, a);
  let d: number;
  if (A.t === 'texto' && B.t === 'texto') d = A.v.localeCompare(B.v, 'es', { sensitivity: 'base' });
  else if (A.t === 'texto' || B.t === 'texto') d = A.t === 'texto' ? 1 : -1;   // como Excel: texto > número
  else d = num(A) - num(B);
  return { '=': d === 0, '<>': d !== 0, '<': d < 0, '>': d > 0, '<=': d <= 0, '>=': d >= 0 }[op] ?? false;
}

// ───────────────────────── Funciones ─────────────────────────

const ALIAS: Record<string, string> = {
  SUM: 'SUMA', AVERAGE: 'PROMEDIO', COUNT: 'CONTAR', COUNTA: 'CONTARA', IF: 'SI', AND: 'Y', OR: 'O', NOT: 'NO',
  ROUND: 'REDONDEAR', TODAY: 'HOY', NOW: 'AHORA', INT: 'ENTERO', MOD: 'RESIDUO', CONCATENATE: 'CONCATENAR',
};

export const FUNCIONES = ['SUMA', 'PROMEDIO', 'MIN', 'MAX', 'CONTAR', 'CONTARA', 'SI', 'Y', 'O', 'NO',
  'REDONDEAR', 'ABS', 'ENTERO', 'RESIDUO', 'HOY', 'AHORA', 'CONCATENAR'] as const;

/** Números de los argumentos: en rangos se ignoran textos, vacíos y lógicos (como Excel). */
function numeros(args: Nodo[], ctx: Contexto): number[] {
  const out: number[] = [];
  for (const a of args) {
    const r = ev(a, ctx);
    if (Array.isArray(r)) {
      for (const v of r) {
        if (v.t === 'error') throw new Falla(v.v);
        if (v.t === 'numero' || v.t === 'fecha' || v.t === 'fecha_hora' || v.t === 'hora') out.push(num(v));
      }
    } else if (r.t !== 'vacio') {
      out.push(num(r));
    }
  }
  return out;
}

function valores(args: Nodo[], ctx: Contexto): Valor[] {
  return args.flatMap((a) => { const r = ev(a, ctx); return Array.isArray(r) ? r : [r]; });
}

function logico(v: Valor): boolean {
  if (v.t === 'logico') return v.v;
  if (v.t === 'texto') throw new Falla('#VALOR!');
  return num(v) !== 0;
}

function aridad(args: Nodo[], min: number, max = min) {
  if (args.length < min || args.length > max) throw new Falla('#VALOR!');
}

function funcion(nombre: string, args: Nodo[], ctx: Contexto): Valor {
  const f = ALIAS[nombre] ?? nombre;
  switch (f) {
    case 'SUMA': return { t: 'numero', v: numeros(args, ctx).reduce((s, x) => s + x, 0) };
    case 'PROMEDIO': {
      const n = numeros(args, ctx);
      if (n.length === 0) throw new Falla('#DIV/0!');
      return { t: 'numero', v: n.reduce((s, x) => s + x, 0) / n.length };
    }
    case 'MIN': { const n = numeros(args, ctx); return { t: 'numero', v: n.length ? Math.min(...n) : 0 }; }
    case 'MAX': { const n = numeros(args, ctx); return { t: 'numero', v: n.length ? Math.max(...n) : 0 }; }
    case 'CONTAR': return { t: 'numero', v: valores(args, ctx).filter((v) => ['numero', 'fecha', 'fecha_hora', 'hora'].includes(v.t)).length };
    case 'CONTARA': return { t: 'numero', v: valores(args, ctx).filter((v) => v.t !== 'vacio').length };
    case 'SI': {
      aridad(args, 2, 3);
      const cond = logico(uno(ev(args[0], ctx)));
      if (cond) return uno(ev(args[1], ctx));
      return args[2] ? uno(ev(args[2], ctx)) : { t: 'logico', v: false };
    }
    case 'Y': return { t: 'logico', v: valores(args, ctx).filter((v) => v.t !== 'vacio').every(logico) };
    case 'O': return { t: 'logico', v: valores(args, ctx).filter((v) => v.t !== 'vacio').some(logico) };
    case 'NO': aridad(args, 1); return { t: 'logico', v: !logico(uno(ev(args[0], ctx))) };
    case 'REDONDEAR': {
      aridad(args, 1, 2);
      const x = num(uno(ev(args[0], ctx)));
      const d = args[1] ? Math.trunc(num(uno(ev(args[1], ctx)))) : 0;
      const k = 10 ** d;
      return { t: 'numero', v: Math.sign(x) * Math.round(Math.abs(x) * k + 1e-9) / k };
    }
    case 'ABS': aridad(args, 1); return { t: 'numero', v: Math.abs(num(uno(ev(args[0], ctx)))) };
    case 'ENTERO': aridad(args, 1); return { t: 'numero', v: Math.floor(num(uno(ev(args[0], ctx)))) };
    case 'RESIDUO': {
      aridad(args, 2);
      const a = num(uno(ev(args[0], ctx)));
      const b = num(uno(ev(args[1], ctx)));
      if (b === 0) throw new Falla('#DIV/0!');
      return { t: 'numero', v: a - b * Math.floor(a / b) };
    }
    case 'HOY': aridad(args, 0); return { t: 'fecha', v: (ctx.hoy ?? hoyISO)() };
    case 'AHORA': aridad(args, 0); return { t: 'fecha_hora', v: new Date().toISOString().slice(0, 16) };
    case 'CONCATENAR': return { t: 'texto', v: valores(args, ctx).map(texto).join('') };
    default: throw new Falla('#NOMBRE?');
  }
}

export { VACIO };
