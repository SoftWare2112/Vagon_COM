import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { catalogosApi } from '../../api/endpoints';
import { esFechaValida, mascaraFecha } from '../../utils/formato';
import type { Filtros } from '../../types';

interface Props { valor: Filtros; onCambiar: (f: Filtros) => void }

type Clave = 'puesto' | 'ingreso' | 'faltas';

/**
 * v9 — Filtros más claros: el buscador por nombre siempre a la vista; los demás filtros (Puesto, Fecha de
 * ingreso, # Faltas) en un panel que se abre con "Filtros". Lo que está filtrando aparece como etiquetas
 * que se quitan con ×. En celular el panel se abre desde abajo.
 */
export function FiltrosPanel({ valor, onCambiar }: Props) {
  const [nombre, setNombre] = useState(valor.nombre ?? '');
  const [abierto, setAbierto] = useState(false);
  const [borrador, setBorrador] = useState<Filtros>(valor);
  const { data: puestos = [] } = useQuery({ queryKey: ['puestos'], queryFn: catalogosApi.puestos, staleTime: 5 * 60_000 });

  // Búsqueda por nombre con "debounce" (no consulta en cada tecla)
  useEffect(() => {
    const t = setTimeout(() => {
      if (nombre !== (valor.nombre ?? '')) onCambiar({ ...valor, nombre: nombre || undefined, page: 1 });
    }, 350);
    return () => clearTimeout(t);
  }, [nombre]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => { if (abierto) setBorrador(valor); }, [abierto]); // eslint-disable-line react-hooks/exhaustive-deps

  const fechaMal = (f?: string) => !!f && f.length === 10 && !esFechaValida(f);
  const aplicar = () => {
    if (fechaMal(borrador.ingreso_desde) || fechaMal(borrador.ingreso_hasta)) return;
    onCambiar({ ...valor, puesto: borrador.puesto || undefined, ingreso_desde: borrador.ingreso_desde || undefined,
      ingreso_hasta: borrador.ingreso_hasta || undefined, faltas: borrador.faltas || undefined, page: 1 });
    setAbierto(false);
  };
  const quitar = (c: Clave) => onCambiar({
    ...valor, page: 1,
    ...(c === 'puesto' ? { puesto: undefined } : c === 'faltas' ? { faltas: undefined } : { ingreso_desde: undefined, ingreso_hasta: undefined }),
  });
  const limpiarTodo = () => {
    setNombre('');
    onCambiar({ periodo_id: valor.periodo_id, orden: valor.orden, direccion: valor.direccion, page: 1 });
  };

  const etiquetas: { clave: Clave; texto: string }[] = [];
  if (valor.puesto) etiquetas.push({ clave: 'puesto', texto: `Puesto: ${valor.puesto}` });
  if (valor.ingreso_desde || valor.ingreso_hasta) {
    etiquetas.push({ clave: 'ingreso', texto: `Ingreso: ${valor.ingreso_desde || '…'} – ${valor.ingreso_hasta || '…'}` });
  }
  if (valor.faltas) etiquetas.push({ clave: 'faltas', texto: `Faltas: ${valor.faltas}` });
  const activos = etiquetas.length + (valor.nombre ? 1 : 0);

  return (
    <div className="filtros-barra">
      <div className="filtros-fila">
        <div className="buscador">
          <span className="buscador-icono" aria-hidden>⌕</span>
          <input type="search" placeholder="Buscar por nombre" value={nombre} aria-label="Buscar por nombre" onChange={(e) => setNombre(e.target.value)} />
        </div>
        <button type="button" className={`btn btn-sec ${abierto ? 'activo' : ''}`} aria-expanded={abierto} onClick={() => setAbierto((a) => !a)}>
          ⚲ Filtros{etiquetas.length > 0 && <span className="contador">{etiquetas.length}</span>}
        </button>
        {activos > 0 && <button type="button" className="btn-link" onClick={limpiarTodo}>Limpiar todo</button>}
      </div>

      {etiquetas.length > 0 && (
        <ul className="chips" aria-label="Filtros activos">
          {etiquetas.map((e) => (
            <li key={e.clave} className="chip">{e.texto}
              <button type="button" aria-label={`Quitar filtro ${e.texto}`} onClick={() => quitar(e.clave)}>✕</button>
            </li>
          ))}
        </ul>
      )}

      {abierto && <div className="velo-panel" onClick={() => setAbierto(false)} aria-hidden />}
      {abierto && (
        <form className="panel-desplegable" onSubmit={(e) => { e.preventDefault(); aplicar(); }} aria-label="Filtros">
          <div className="panel-desplegable-cab">
            <strong>Filtrar el listado</strong>
            <button type="button" className="btn-icono" aria-label="Cerrar filtros" onClick={() => setAbierto(false)}>✕</button>
          </div>
          <div className="filtros">
            <label>Puesto
              <input list="lista-puestos" placeholder="Selecciona o escribe" value={borrador.puesto ?? ''}
                onChange={(e) => setBorrador({ ...borrador, puesto: e.target.value })} />
              <datalist id="lista-puestos">{puestos.map((p) => <option key={p.id} value={p.nombre} />)}</datalist>
            </label>
            <label>Ingreso desde
              <input inputMode="numeric" placeholder="dd/mm/aaaa" value={borrador.ingreso_desde ?? ''} aria-invalid={fechaMal(borrador.ingreso_desde)}
                onChange={(e) => setBorrador({ ...borrador, ingreso_desde: mascaraFecha(e.target.value) })} />
            </label>
            <label>Ingreso hasta
              <input inputMode="numeric" placeholder="dd/mm/aaaa" value={borrador.ingreso_hasta ?? ''} aria-invalid={fechaMal(borrador.ingreso_hasta)}
                onChange={(e) => setBorrador({ ...borrador, ingreso_hasta: mascaraFecha(e.target.value) })} />
            </label>
            <label># Faltas
              <input type="number" min={0} max={31} inputMode="numeric" placeholder="Ej. 2" value={borrador.faltas ?? ''}
                onChange={(e) => setBorrador({ ...borrador, faltas: e.target.value })} />
            </label>
          </div>
          <div className="panel-desplegable-pie">
            <button type="button" className="btn btn-sec" onClick={() => setBorrador({ ...borrador, puesto: '', ingreso_desde: '', ingreso_hasta: '', faltas: '' })}>Borrar campos</button>
            <button type="submit" className="btn">Aplicar filtros</button>
          </div>
        </form>
      )}
    </div>
  );
}
