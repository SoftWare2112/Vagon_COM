import { useEffect, useState } from 'react';
import { CeldaValor, alinear } from '../../components/CeldaValor';
import { COLUMNAS_OBLIGATORIAS } from '../../utils/columnas';
import type { Columna, Fila, Filtros } from '../../types';

export type AccionColumna = 'izquierda' | 'derecha' | 'ocultar';

interface Props {
  filas: Fila[];
  columnas: Columna[];
  filtros: Filtros;
  onOrdenar: (clave: string) => void;
  onVer: (f: Fila) => void;
  onEditar?: (f: Fila) => void;
  onEliminar?: (f: Fila) => void;
  /** Solo si el usuario puede gestionar columnas: muestra el menú ⋮ en cada encabezado. */
  onMenuColumna?: (accion: AccionColumna, col: Columna) => void;
}

const ORDENABLES = (c: Columna) => !c.clave.startsWith('domingo:') && c.tipo !== 'texto_largo';

/** Tabla GENÉRICA: dibuja las columnas que mande el servidor, en su orden (incluye las que RH agregue). */
export function TablaRegistros({ filas, columnas, filtros, onOrdenar, onVer, onEditar, onEliminar, onMenuColumna }: Props) {
  // Menú en posición fija (calculada desde el botón) para que el scroll de la tabla no lo recorte
  const [menu, setMenu] = useState<{ clave: string; x: number; y: number } | null>(null);

  // Cerrar el menú al hacer clic fuera o con Escape
  useEffect(() => {
    if (!menu) return;
    const cerrar = () => setMenu(null);
    const esc = (e: KeyboardEvent) => e.key === 'Escape' && cerrar();
    document.addEventListener('click', cerrar);
    document.addEventListener('keydown', esc);
    window.addEventListener('scroll', cerrar, true);
    return () => {
      document.removeEventListener('click', cerrar);
      document.removeEventListener('keydown', esc);
      window.removeEventListener('scroll', cerrar, true);
    };
  }, [menu]);

  const elegir = (accion: AccionColumna, c: Columna) => {
    setMenu(null);
    onMenuColumna?.(accion, c);
  };

  return (
    <div className="tabla-scroll">
      <table className="tabla">
        <thead>
          <tr>
            <th><button type="button" className="th-orden" onClick={() => onOrdenar('numero')}>No.{filtros.orden === 'numero' ? (filtros.direccion === 'desc' ? ' ▼' : ' ▲') : ''}</button></th>
            {columnas.map((c) => {
              const activo = filtros.orden === c.clave;
              return (
                <th key={c.clave} aria-sort={activo ? (filtros.direccion === 'desc' ? 'descending' : 'ascending') : undefined}>
                  <div className="th-contenido">
                    {ORDENABLES(c)
                      ? <button type="button" className="th-orden" onClick={() => onOrdenar(c.clave)}>{c.etiqueta}{activo ? (filtros.direccion === 'desc' ? ' ▼' : ' ▲') : ''}</button>
                      : <span>{c.etiqueta}</span>}
                    {onMenuColumna && (
                      <span className="th-menu">
                        <button type="button" className="th-menu-boton" aria-haspopup="menu" aria-expanded={menu?.clave === c.clave}
                          aria-label={`Opciones de la columna ${c.etiqueta}`}
                          onClick={(e) => {
                            e.stopPropagation();
                            const r = e.currentTarget.getBoundingClientRect();
                            setMenu(menu?.clave === c.clave ? null : { clave: c.clave, x: Math.max(8, r.right - 240), y: r.bottom + 4 });
                          }}>⋮</button>
                        {menu?.clave === c.clave && (
                          <div className="menu-flotante" role="menu" style={{ left: menu.x, top: menu.y }} onClick={(e) => e.stopPropagation()}>
                            <button role="menuitem" onClick={() => elegir('izquierda', c)}>Insertar columna a la izquierda</button>
                            <button role="menuitem" onClick={() => elegir('derecha', c)}>Insertar columna a la derecha</button>
                            {!COLUMNAS_OBLIGATORIAS.includes(c.clave) && (
                              <button role="menuitem" onClick={() => elegir('ocultar', c)}>Ocultar columna</button>
                            )}
                          </div>
                        )}
                      </span>
                    )}
                  </div>
                </th>
              );
            })}
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          {filas.map((f) => (
            <tr key={f.id}>
              <td className="mono">{f.numero_empleado}</td>
              {columnas.map((c) => (
                <td key={c.clave}
                  className={[alinear(c), c.clave === 'nombre_completo' ? 'col-nombre' : '', c.tipo === 'texto_largo' ? 'col-detalle' : '',
                    f.campos_editados.includes(c.clave) ? 'editado' : ''].filter(Boolean).join(' ')}
                  title={c.tipo === 'texto_largo' ? String(f.valores[c.clave] ?? '') : undefined}>
                  <CeldaValor col={c} valor={f.valores[c.clave]} />
                </td>
              ))}
              <td className="acciones">
                <button className="btn-icono" onClick={() => onVer(f)} title="Vista en resumen" aria-label={`Ver resumen de ${f.valores.nombre_completo}`}>👁</button>
                {onEditar && <button className="btn-icono" onClick={() => onEditar(f)} title="Editar" aria-label={`Editar ${f.valores.nombre_completo}`}>✎</button>}
                {onEliminar && <button className="btn-icono peligro" onClick={() => onEliminar(f)} title="Quitar (va al Histórico)" aria-label={`Quitar ${f.valores.nombre_completo}`}>🗑</button>}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
