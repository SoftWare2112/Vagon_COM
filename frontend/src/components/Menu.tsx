import { useEffect, useRef, useState, type ReactNode } from 'react';

export interface OpcionMenu {
  texto: string;
  detalle?: string;
  icono?: string;
  onClick: () => void;
  peligro?: boolean;
  deshabilitada?: boolean;
}

/**
 * v9 — Botón con menú desplegable ("Importar ▾", "Exportar ▾"). Se cierra al elegir, con Esc o al hacer clic fuera.
 * En celular el menú se abre como panel desde abajo (CSS).
 */
export function Menu({ etiqueta, opciones, clase = 'btn btn-sec', titulo, alinear = 'der' }: {
  etiqueta: ReactNode; opciones: OpcionMenu[]; clase?: string; titulo?: string; alinear?: 'izq' | 'der';
}) {
  const [abierto, setAbierto] = useState(false);
  const caja = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!abierto) return;
    const fuera = (e: globalThis.MouseEvent) => { if (!caja.current?.contains(e.target as Node)) setAbierto(false); };
    const esc = (e: globalThis.KeyboardEvent) => { if (e.key === 'Escape') setAbierto(false); };
    document.addEventListener('mousedown', fuera);
    document.addEventListener('keydown', esc);
    return () => { document.removeEventListener('mousedown', fuera); document.removeEventListener('keydown', esc); };
  }, [abierto]);

  const visibles = opciones.filter(Boolean);
  if (visibles.length === 0) return null;

  return (
    <div className="menu" ref={caja}>
      <button type="button" className={clase} aria-haspopup="menu" aria-expanded={abierto} title={titulo} onClick={() => setAbierto((a) => !a)}>
        {etiqueta} <span className="menu-flecha" aria-hidden>▾</span>
      </button>
      {abierto && (
        <div className={`menu-lista menu-${alinear}`} role="menu">
          {visibles.map((o) => (
            <button key={o.texto} type="button" role="menuitem" className={o.peligro ? 'texto-peligro' : ''} disabled={o.deshabilitada}
              onClick={() => { setAbierto(false); o.onClick(); }}>
              {o.icono && <span className="menu-icono" aria-hidden>{o.icono}</span>}
              <span><strong>{o.texto}</strong>{o.detalle && <small>{o.detalle}</small>}</span>
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
