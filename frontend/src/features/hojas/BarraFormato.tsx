import { useRef } from 'react';
import type { Estilo } from './motor/tipos';

interface Acciones {
  combinar: () => void; separar: () => void;
  filaArriba: () => void; filaAbajo: () => void; borrarFila: () => void;
  columnaIzq: () => void; columnaDer: () => void; borrarColumna: () => void;
}

const FORMATOS: { valor: NonNullable<Estilo['fmt']>; texto: string }[] = [
  { valor: 'general', texto: 'General' }, { valor: 'numero', texto: 'Número (1,234.50)' }, { valor: 'entero', texto: 'Entero' },
  { valor: 'moneda', texto: 'Moneda ($)' }, { valor: 'porcentaje', texto: 'Porcentaje (%)' }, { valor: 'fecha', texto: 'Fecha' },
  { valor: 'fecha_hora', texto: 'Fecha y hora' }, { valor: 'hora', texto: 'Hora' },
];

/** v8 — Barra de formato: negrita, cursiva, subrayado, colores, alineación, formato de número, filas y columnas. */
export function BarraFormato({ estilo, onFormato, acciones, combinada = false, varias = false }: {
  estilo: Estilo;
  onFormato: (cambio: Partial<Estilo> | null) => void;
  acciones: Acciones;
  combinada?: boolean;   // la celda activa es parte de una combinación
  varias?: boolean;      // hay más de una celda seleccionada
}) {
  const menu = useRef<HTMLDetailsElement>(null);
  const cerrarY = (accion: () => void) => () => { if (menu.current) menu.current.open = false; accion(); };
  const boton = (activo: boolean | undefined, titulo: string, contenido: React.ReactNode, accion: () => void) => (
    <button type="button" className={`btn-herramienta ${activo ? 'activa' : ''}`} title={titulo} aria-label={titulo} aria-pressed={!!activo}
      onMouseDown={(e) => e.preventDefault()} onClick={accion}>{contenido}</button>
  );

  return (
    <div className="barra-formato" role="toolbar" aria-label="Formato">
      {boton(estilo.n, 'Negrita (Ctrl+B)', <b>N</b>, () => onFormato({ n: !estilo.n }))}
      {boton(estilo.i, 'Cursiva (Ctrl+I)', <i>K</i>, () => onFormato({ i: !estilo.i }))}
      {boton(estilo.s, 'Subrayado (Ctrl+U)', <u>S</u>, () => onFormato({ s: !estilo.s }))}
      <span className="separador" />
      <label className="color-herramienta" title="Color de texto">
        <span style={{ borderBottom: `3px solid ${estilo.c ?? '#1f2933'}` }}>A</span>
        <input type="color" value={estilo.c ?? '#1f2933'} onChange={(e) => onFormato({ c: e.target.value.toUpperCase() })} />
      </label>
      <label className="color-herramienta" title="Color de relleno">
        <span style={{ background: estilo.f ?? 'transparent', border: '1px solid var(--line)' }}>▧</span>
        <input type="color" value={estilo.f ?? '#ffffff'} onChange={(e) => onFormato({ f: e.target.value.toUpperCase() })} />
      </label>
      {boton(false, 'Sin relleno', '⌀', () => onFormato({ f: undefined }))}
      <span className="separador" />
      {boton(estilo.a === 'izq', 'Alinear a la izquierda', '⇤', () => onFormato({ a: estilo.a === 'izq' ? undefined : 'izq' }))}
      {boton(estilo.a === 'centro', 'Centrar', '↔', () => onFormato({ a: estilo.a === 'centro' ? undefined : 'centro' }))}
      {boton(estilo.a === 'der', 'Alinear a la derecha', '⇥', () => onFormato({ a: estilo.a === 'der' ? undefined : 'der' }))}
      {boton(estilo.w, 'Ajustar texto', '↵', () => onFormato({ w: !estilo.w }))}
      {combinada
        ? boton(true, 'Separar celdas', '⊟', acciones.separar)
        : <button type="button" className="btn-herramienta" title="Combinar celdas (se ve el valor de la esquina superior izquierda)" aria-label="Combinar celdas"
            disabled={!varias} onMouseDown={(e) => e.preventDefault()} onClick={acciones.combinar}>⊞</button>}
      <span className="separador" />
      <select aria-label="Formato de número" value={estilo.fmt ?? 'general'} onChange={(e) => onFormato({ fmt: e.target.value as Estilo['fmt'] })}>
        {FORMATOS.map((f) => <option key={f.valor} value={f.valor}>{f.texto}</option>)}
      </select>
      {boton(false, 'Quitar formato', '✕', () => onFormato(null))}
      <span className="separador" />
      <details className="menu-estructura" ref={menu}>
        <summary>Filas y columnas</summary>
        <div className="menu-estructura-lista">
          <button type="button" onClick={cerrarY(acciones.filaArriba)}>Insertar fila arriba</button>
          <button type="button" onClick={cerrarY(acciones.filaAbajo)}>Insertar fila abajo</button>
          <button type="button" onClick={cerrarY(acciones.borrarFila)}>Eliminar fila</button>
          <hr />
          <button type="button" onClick={cerrarY(acciones.columnaIzq)}>Insertar columna a la izquierda</button>
          <button type="button" onClick={cerrarY(acciones.columnaDer)}>Insertar columna a la derecha</button>
          <button type="button" onClick={cerrarY(acciones.borrarColumna)}>Eliminar columna</button>
        </div>
      </details>
    </div>
  );
}
