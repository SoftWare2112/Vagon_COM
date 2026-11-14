/**
 * Motor de hojas (v8) — tipos compartidos. Todo el motor es TypeScript puro: no usa React ni la red,
 * así se prueba con Vitest en milisegundos (ver __tests__).
 */
export type TipoCelda = 'vacio' | 'texto' | 'numero' | 'fecha' | 'fecha_hora' | 'hora' | 'logico' | 'formula';

export type CodigoError = '#DIV/0!' | '#REF!' | '#VALOR!' | '#NOMBRE?' | '#CICLO!' | '#N/A' | '#NUM!';

/** Un valor ya calculado (lo que se ve en la celda). Fechas en ISO: '2026-10-28', '2026-10-28T14:18', horas 'HH:MM'. */
export type Valor =
  | { t: 'vacio' }
  | { t: 'texto'; v: string }
  | { t: 'numero'; v: number }
  | { t: 'fecha'; v: string }
  | { t: 'fecha_hora'; v: string }
  | { t: 'hora'; v: string }
  | { t: 'logico'; v: boolean }
  | { t: 'error'; v: CodigoError };

/** Celda tal como la guarda el servidor. `fx` es la fórmula con ids: "=[f12c3]*[f12c4]". */
export interface Celda {
  f: number;          // id de fila
  c: number;          // id de columna
  t: TipoCelda;
  v?: string | number | boolean | null;
  fx?: string;
  rt?: string;        // tipo del resultado de la fórmula
  e?: number;         // id de estilo
  ver?: number;       // versión (para no pisar cambios de otra persona)
}

/** Formato de celda (claves cortas, igual que en el servidor). */
export interface Estilo {
  n?: boolean; i?: boolean; s?: boolean; w?: boolean;
  c?: string; f?: string;
  a?: 'izq' | 'centro' | 'der';
  fmt?: 'general' | 'numero' | 'entero' | 'moneda' | 'porcentaje' | 'fecha' | 'fecha_hora' | 'hora' | 'texto';
}

export const VACIO: Valor = { t: 'vacio' };
export const err = (v: CodigoError): Valor => ({ t: 'error', v });

/** Valor guardado de una celda que NO es fórmula, o el último resultado de una fórmula. */
export function valorDeCelda(c: Celda | undefined): Valor {
  if (!c) return VACIO;
  const t = c.t === 'formula' ? (c.rt ?? 'vacio') : c.t;
  switch (t) {
    case 'texto': return { t: 'texto', v: String(c.v ?? '') };
    case 'numero': return typeof c.v === 'number' ? { t: 'numero', v: c.v } : VACIO;
    case 'fecha': case 'fecha_hora': case 'hora': return c.v ? { t, v: String(c.v) } : VACIO;
    case 'logico': return { t: 'logico', v: Boolean(c.v) };
    case 'error': return err((c.v as CodigoError) ?? '#VALOR!');
    default: return VACIO;
  }
}

/** Convierte un valor calculado a lo que se manda al servidor como resultado de fórmula. */
export function resultadoParaGuardar(v: Valor): { rt: string; v: string | number | boolean | null } {
  return v.t === 'vacio' ? { rt: 'vacio', v: null } : { rt: v.t, v: v.v };
}
