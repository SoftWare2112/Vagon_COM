import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { catalogosApi } from '../../api/endpoints';
import { esFechaValida, mascaraFecha } from '../../utils/formato';
import type { Filtros } from '../../types';

interface Props { valor: Filtros; onCambiar: (f: Filtros) => void }

/** Los 4 filtros del cuestionario: Nombre, Puesto, Fecha de ingreso (rango dd/mm/aaaa) y #Faltas. */
export function FiltrosPanel({ valor, onCambiar }: Props) {
  const [borrador, setBorrador] = useState<Filtros>(valor);
  const { data: puestos = [] } = useQuery({ queryKey: ['puestos'], queryFn: catalogosApi.puestos, staleTime: 5 * 60_000 });

  // Búsqueda por nombre con "debounce" (no consulta en cada tecla)
  useEffect(() => {
    const t = setTimeout(() => {
      if (borrador.nombre !== valor.nombre) onCambiar({ ...valor, nombre: borrador.nombre, page: 1 });
    }, 350);
    return () => clearTimeout(t);
  }, [borrador.nombre]); // eslint-disable-line react-hooks/exhaustive-deps

  const fechaMal = (f?: string) => !!f && f.length === 10 && !esFechaValida(f);
  const aplicar = () => {
    if (fechaMal(borrador.ingreso_desde) || fechaMal(borrador.ingreso_hasta)) return;
    onCambiar({ ...borrador, page: 1 });
  };
  const limpiar = () => {
    const f: Filtros = { periodo_id: valor.periodo_id, orden: valor.orden, direccion: valor.direccion };
    setBorrador(f);
    onCambiar(f);
  };

  return (
    <form className="filtros" onSubmit={(e) => { e.preventDefault(); aplicar(); }}>
      <label>Nombre
        <input type="search" placeholder="Ingresa el nombre" value={borrador.nombre ?? ''}
          onChange={(e) => setBorrador({ ...borrador, nombre: e.target.value })} />
      </label>
      <label>Puesto
        <input list="lista-puestos" placeholder="Selecciona o escribe" value={borrador.puesto ?? ''}
          onChange={(e) => setBorrador({ ...borrador, puesto: e.target.value })} />
        <datalist id="lista-puestos">{puestos.map((p) => <option key={p.id} value={p.nombre} />)}</datalist>
      </label>
      <label>Ingreso desde
        <input inputMode="numeric" placeholder="dd/mm/aaaa" value={borrador.ingreso_desde ?? ''}
          aria-invalid={fechaMal(borrador.ingreso_desde)}
          onChange={(e) => setBorrador({ ...borrador, ingreso_desde: mascaraFecha(e.target.value) })} />
      </label>
      <label>hasta
        <input inputMode="numeric" placeholder="dd/mm/aaaa" value={borrador.ingreso_hasta ?? ''}
          aria-invalid={fechaMal(borrador.ingreso_hasta)}
          onChange={(e) => setBorrador({ ...borrador, ingreso_hasta: mascaraFecha(e.target.value) })} />
      </label>
      <label># Faltas
        <input type="number" min={0} max={31} inputMode="numeric" placeholder="Ej. 2" value={borrador.faltas ?? ''}
          onChange={(e) => setBorrador({ ...borrador, faltas: e.target.value })} />
      </label>
      <div className="filtros-acciones">
        <button type="submit" className="btn">Filtrar</button>
        <button type="button" className="btn btn-sec" onClick={limpiar}>Limpiar</button>
      </div>
    </form>
  );
}
