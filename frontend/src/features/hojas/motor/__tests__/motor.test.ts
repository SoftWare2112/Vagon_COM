import { describe, expect, it } from 'vitest';
import { interpretar } from '../entrada';
import { mostrar, textoParaEditar } from '../formato';
import { recalcular, revisarSintaxis } from '../libro';
import { aInterno, aVisible, indice, letra } from '../referencias';
import type { Celda } from '../tipos';

describe('entrada: el tipo de lo que se escribe', () => {
  it('números, moneda y porcentaje', () => {
    expect(interpretar('1,250.50')).toEqual({ t: 'numero', v: 1250.5, fmt: undefined });
    expect(interpretar('$1,250')).toEqual({ t: 'numero', v: 1250, fmt: 'moneda' });
    expect(interpretar('15%')).toEqual({ t: 'numero', v: 0.15, fmt: 'porcentaje' });
    expect(interpretar('-3.5')).toEqual({ t: 'numero', v: -3.5, fmt: undefined });
  });
  it('fechas, horas, lógicos, fórmulas y texto', () => {
    expect(interpretar('28/10/2026')).toEqual({ t: 'fecha', v: '2026-10-28' });
    expect(interpretar('28/10/2026 14:18')).toEqual({ t: 'fecha_hora', v: '2026-10-28T14:18' });
    expect(interpretar('9:05')).toEqual({ t: 'hora', v: '09:05' });
    expect(interpretar('verdadero')).toEqual({ t: 'logico', v: true });
    expect(interpretar('=A1*2')).toEqual({ t: 'formula', fx: '=A1*2' });
    expect(interpretar('31/02/2026')).toEqual({ t: 'texto', v: '31/02/2026' });
    expect(interpretar("'00123")).toEqual({ t: 'texto', v: '00123' });
    expect(interpretar('   ')).toEqual({ t: 'vacio' });
  });
});

describe('referencias A1 <-> ids', () => {
  const filas = [11, 12, 13, 14];
  const cols = [21, 22, 23, 24];
  it('letras de columna', () => {
    expect(letra(0)).toBe('A');
    expect(letra(27)).toBe('AB');
    expect(indice('AA')).toBe(26);
  });
  it('ida y vuelta, sin tocar textos', () => {
    const fx = aInterno('=SUMA(A1:B2)+"C3"&C3', filas, cols);
    expect(fx).toBe('=SUMA([f11c21]:[f12c22])+"C3"&[f13c23]');
    expect(aVisible(fx, new Map(filas.map((f, i) => [f, i])), new Map(cols.map((c, i) => [c, i])))).toBe('=SUMA(A1:B2)+"C3"&C3');
  });
  it('fila borrada = #REF!', () => {
    expect(aVisible('=[f99c21]', new Map(filas.map((f, i) => [f, i])), new Map(cols.map((c, i) => [c, i])))).toBe('=#REF!');
  });
});

/** Mini hoja: A1=10, A2=20, A3=texto, B1=fórmula… (ids: filas 1..5, columnas 1..3) */
function hoja(formulas: Record<string, string>, extra: Celda[] = []) {
  const est = { filas: [1, 2, 3, 4, 5], columnas: [1, 2, 3] };
  const celdas = new Map<string, Celda>();
  const pon = (c: Celda) => celdas.set(`${c.f}:${c.c}`, c);
  pon({ f: 1, c: 1, t: 'numero', v: 10 });
  pon({ f: 2, c: 1, t: 'numero', v: 20 });
  pon({ f: 3, c: 1, t: 'texto', v: 'hola' });
  pon({ f: 4, c: 1, t: 'fecha', v: '2026-10-01' });
  extra.forEach(pon);
  for (const [k, fx] of Object.entries(formulas)) {
    const [f, c] = k.split(':').map(Number);
    pon({ f, c, t: 'formula', fx: aInterno(fx, est.filas, est.columnas) });
  }
  const { valores } = recalcular(celdas, est, () => '2026-10-28');
  return (k: string) => valores.get(k);
}

describe('evaluación de fórmulas', () => {
  it('aritmética con prioridad, paréntesis y porcentaje', () => {
    const v = hoja({ '1:2': '=A1+A2*2', '2:2': '=(A1+A2)*2', '3:2': '=A2*16%', '4:2': '=2^3-1', '5:2': '=-A1+5' });
    expect(v('1:2')).toEqual({ t: 'numero', v: 50 });
    expect(v('2:2')).toEqual({ t: 'numero', v: 60 });
    expect(v('3:2')).toEqual({ t: 'numero', v: 3.2 });
    expect(v('4:2')).toEqual({ t: 'numero', v: 7 });
    expect(v('5:2')).toEqual({ t: 'numero', v: -5 });
  });
  it('funciones con rangos (ignoran texto) y separador ; o ,', () => {
    const v = hoja({ '1:2': '=SUMA(A1:A3)', '2:2': '=PROMEDIO(A1:A2)', '3:2': '=MAX(A1,A2)', '4:2': '=CONTAR(A1:A5)', '5:2': '=REDONDEAR(10/3;2)' });
    expect(v('1:2')).toEqual({ t: 'numero', v: 30 });
    expect(v('2:2')).toEqual({ t: 'numero', v: 15 });
    expect(v('3:2')).toEqual({ t: 'numero', v: 20 });
    expect(v('4:2')).toEqual({ t: 'numero', v: 3 });      // 10, 20 y la fecha
    expect(v('5:2')).toEqual({ t: 'numero', v: 3.33 });
  });
  it('SI, comparaciones, texto y lógicos', () => {
    const v = hoja({ '1:2': '=SI(A1>5;"alto";"bajo")', '2:2': '=A3&" mundo"', '3:2': '=Y(A1>0;A2>0)', '4:2': '=SI(C5="";"vacía";"llena")' });
    expect(v('1:2')).toEqual({ t: 'texto', v: 'alto' });
    expect(v('2:2')).toEqual({ t: 'texto', v: 'hola mundo' });
    expect(v('3:2')).toEqual({ t: 'logico', v: true });
    expect(v('4:2')).toEqual({ t: 'texto', v: 'vacía' });
  });
  it('fechas: sumar días, restar fechas y HOY()', () => {
    const v = hoja({ '1:2': '=A4+30', '2:2': '=HOY()-A4', '3:2': '=REDONDEAR((HOY()-A4)/365;1)' });
    expect(v('1:2')).toEqual({ t: 'fecha', v: '2026-10-31' });
    expect(v('2:2')).toEqual({ t: 'numero', v: 27 });
    expect(v('3:2')).toEqual({ t: 'numero', v: 0.1 });
  });
  it('errores: división entre cero, texto en suma, función desconocida, ciclo y propagación', () => {
    const v = hoja({ '1:2': '=A1/0', '2:2': '=A3+1', '3:2': '=NOEXISTE(1)', '4:2': '=C4+1', '4:3': '=B4*2', '5:2': '=B1+1' });
    expect(v('1:2')).toEqual({ t: 'error', v: '#DIV/0!' });
    expect(v('2:2')).toEqual({ t: 'error', v: '#VALOR!' });
    expect(v('3:2')).toEqual({ t: 'error', v: '#NOMBRE?' });
    expect(v('4:2')).toEqual({ t: 'error', v: '#CICLO!' });
    expect(v('5:2')).toEqual({ t: 'error', v: '#DIV/0!' });   // usa B1, que tiene error
  });
  it('fórmula que depende de otra fórmula', () => {
    const v = hoja({ '1:2': '=A1*2', '1:3': '=B1+1' });
    expect(v('1:3')).toEqual({ t: 'numero', v: 21 });
  });
  it('sintaxis', () => {
    expect(revisarSintaxis('=SUMA(1;2')).not.toBeNull();
    expect(revisarSintaxis('="abc')).not.toBeNull();
    expect(revisarSintaxis('=SUMA(1;2)')).toBeNull();
  });
  it('detecta qué resultados cambiaron', () => {
    const celdas = new Map<string, Celda>([
      ['1:1', { f: 1, c: 1, t: 'numero', v: 5 }],
      ['1:2', { f: 1, c: 2, t: 'formula', fx: '=[f1c1]*2', rt: 'numero', v: 10 }],
      ['1:3', { f: 1, c: 3, t: 'formula', fx: '=[f1c1]+1', rt: 'numero', v: 0 }],
    ]);
    expect(recalcular(celdas, { filas: [1], columnas: [1, 2, 3] }).cambiadas).toEqual(['1:3']);
  });
});

describe('formato de visualización', () => {
  it('moneda, porcentaje, fechas y edición', () => {
    expect(mostrar({ t: 'numero', v: 1250.5 }, 'moneda')).toBe('$1,250.50');
    expect(mostrar({ t: 'numero', v: 0.155 }, 'porcentaje')).toBe('15.50%');
    expect(mostrar({ t: 'fecha', v: '2026-10-28' })).toBe('28/10/2026');
    expect(mostrar({ t: 'numero', v: 0.1 + 0.2 })).toBe('0.3');
    expect(textoParaEditar({ t: 'numero', v: 1250.5 })).toBe('1250.5');
    expect(textoParaEditar({ t: 'texto', v: '00123' })).toBe("'00123");
  });
});
