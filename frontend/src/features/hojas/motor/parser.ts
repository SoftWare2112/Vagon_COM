/**
 * Analizador de fórmulas (sobre la fórmula INTERNA, con ids). Descenso recursivo por niveles de
 * prioridad, de menor a mayor:
 *   comparación (= <> < > <= >=) · concatenación (&) · suma/resta · multiplicación/división
 *   · potencia (^) · signo (-x) · porcentaje (x%) · primario (número, "texto", celda, rango, función, paréntesis)
 * Los argumentos se separan con ";" o "," (Excel en español usa ";"). Decimales con punto.
 */
export type Nodo =
  | { k: 'num'; v: number }
  | { k: 'txt'; v: string }
  | { k: 'log'; v: boolean }
  | { k: 'err'; v: string }
  | { k: 'ref'; f: number; c: number }
  | { k: 'rango'; a: { f: number; c: number }; b: { f: number; c: number } }
  | { k: 'un'; op: string; x: Nodo }
  | { k: 'bin'; op: string; a: Nodo; b: Nodo }
  | { k: 'pct'; x: Nodo }
  | { k: 'fn'; nombre: string; args: Nodo[] };

type Token =
  | { k: 'num'; v: number } | { k: 'txt'; v: string } | { k: 'ref'; f: number; c: number }
  | { k: 'err'; v: string } | { k: 'id'; v: string } | { k: 'op'; v: string } | { k: 'fin' };

export class ErrorSintaxis extends Error {}

function tokenizar(src: string): Token[] {
  const t: Token[] = [];
  let i = 0;
  while (i < src.length) {
    const ch = src[i];
    if (/\s/.test(ch)) { i++; continue; }
    const resto = src.slice(i);
    let m: RegExpExecArray | null;
    if ((m = /^\[f(\d+)c(\d+)\]/.exec(resto))) { t.push({ k: 'ref', f: Number(m[1]), c: Number(m[2]) }); i += m[0].length; continue; }
    if ((m = /^(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?/.exec(resto))) { t.push({ k: 'num', v: Number(m[0]) }); i += m[0].length; continue; }
    if ((m = /^#(?:DIV\/0!|REF!|VALOR!|VALUE!|NOMBRE\?|NAME\?|CICLO!|N\/A|NUM!)/i.exec(resto))) { t.push({ k: 'err', v: m[0].toUpperCase() }); i += m[0].length; continue; }
    if (ch === '"') {
      let j = i + 1; let s = '';
      while (j < src.length) {
        if (src[j] === '"' && src[j + 1] === '"') { s += '"'; j += 2; continue; }
        if (src[j] === '"') break;
        s += src[j++];
      }
      if (j >= src.length) throw new ErrorSintaxis('Falta cerrar comillas');
      t.push({ k: 'txt', v: s }); i = j + 1; continue;
    }
    if ((m = /^[A-Za-zÁÉÍÓÚÑáéíóúñ_][A-Za-z0-9ÁÉÍÓÚÑáéíóúñ_.]*/.exec(resto))) { t.push({ k: 'id', v: m[0].toUpperCase() }); i += m[0].length; continue; }
    if ((m = /^(<>|<=|>=|[-+*/^&=<>%(),;:])/.exec(resto))) { t.push({ k: 'op', v: m[0] }); i += m[0].length; continue; }
    throw new ErrorSintaxis(`Carácter no válido: ${ch}`);
  }
  t.push({ k: 'fin' });
  return t;
}

export function analizar(fx: string): Nodo {
  const toks = tokenizar(fx.startsWith('=') ? fx.slice(1) : fx);
  let p = 0;
  const ver = () => toks[p];
  const esOp = (...ops: string[]) => { const x = toks[p]; return x.k === 'op' && ops.includes(x.v); };
  const tomar = (op: string) => { if (!esOp(op)) throw new ErrorSintaxis(`Se esperaba "${op}"`); p++; };

  const binario = (sig: () => Nodo, ops: string[]) => (): Nodo => {
    let a = sig();
    while (esOp(...ops)) { const op = (toks[p++] as { v: string }).v; a = { k: 'bin', op, a, b: sig() }; }
    return a;
  };

  const primario = (): Nodo => {
    const x = ver();
    if (x.k === 'num') { p++; return { k: 'num', v: x.v }; }
    if (x.k === 'txt') { p++; return { k: 'txt', v: x.v }; }
    if (x.k === 'err') { p++; return { k: 'err', v: x.v }; }
    if (x.k === 'ref') {
      p++;
      if (esOp(':')) {
        p++;
        const y = ver();
        if (y.k !== 'ref') throw new ErrorSintaxis('Rango incompleto');
        p++;
        return { k: 'rango', a: { f: x.f, c: x.c }, b: { f: y.f, c: y.c } };
      }
      return { k: 'ref', f: x.f, c: x.c };
    }
    if (x.k === 'id') {
      p++;
      if (esOp('(')) {
        p++;
        const args: Nodo[] = [];
        if (!esOp(')')) {
          do { args.push(expresion()); } while (esOp(';', ',') && ++p);
        }
        tomar(')');
        return { k: 'fn', nombre: x.v, args };
      }
      if (x.v === 'VERDADERO' || x.v === 'TRUE') return { k: 'log', v: true };
      if (x.v === 'FALSO' || x.v === 'FALSE') return { k: 'log', v: false };
      return { k: 'err', v: '#NOMBRE?' };
    }
    if (esOp('(')) { p++; const e = expresion(); tomar(')'); return e; }
    throw new ErrorSintaxis('Fórmula incompleta');
  };

  const porcentaje = (): Nodo => { let x = primario(); while (esOp('%')) { p++; x = { k: 'pct', x }; } return x; };
  const unario = (): Nodo => (esOp('-', '+') ? { k: 'un', op: (toks[p++] as { v: string }).v, x: unario() } : porcentaje());
  const potencia = binario(unario, ['^']);
  const producto = binario(potencia, ['*', '/']);
  const suma = binario(producto, ['+', '-']);
  const concat = binario(suma, ['&']);
  const expresion = binario(concat, ['=', '<>', '<', '>', '<=', '>=']);

  const raiz = expresion();
  if (ver().k !== 'fin') throw new ErrorSintaxis('Sobra texto al final de la fórmula');
  return raiz;
}
