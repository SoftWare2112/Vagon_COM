import { mascaraFecha } from '../utils/formato';
import type { Columna } from '../types';

type Valor = string | number | boolean | null;

/** Input adecuado al tipo de columna (sirve para columnas que RH agregue después). */
export function CampoValor({ col, valor, onCambiar }: { col: Columna; valor: Valor; onCambiar: (v: Valor) => void }) {
  const v = valor ?? '';
  switch (col.tipo) {
    case 'booleano':
      return (
        <select value={valor === null ? '' : valor ? '1' : '0'} onChange={(e) => onCambiar(e.target.value === '' ? null : e.target.value === '1')}>
          <option value="">—</option><option value="1">Sí</option><option value="0">No</option>
        </select>
      );
    case 'texto_largo':
      return <textarea rows={3} maxLength={2000} value={String(v)} onChange={(e) => onCambiar(e.target.value || null)} />;
    case 'fecha':
      return <input inputMode="numeric" placeholder="dd/mm/aaaa" value={String(v)} onChange={(e) => onCambiar(mascaraFecha(e.target.value) || null)} />;
    case 'entero':
    case 'decimal':
    case 'dinero':
      return (
        <input type="number" min={0} step={col.tipo === 'entero' ? 1 : 0.01} inputMode={col.tipo === 'entero' ? 'numeric' : 'decimal'}
          value={String(v)} onChange={(e) => onCambiar(e.target.value === '' ? null : Number(e.target.value))} />
      );
    default:
      return <input value={String(v)} maxLength={255} onChange={(e) => onCambiar(e.target.value || null)} />;
  }
}
