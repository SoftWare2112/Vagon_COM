import { FaltasBadge } from '../../components/FaltasBadge';
import type { Fila } from '../../types';

/** Vista móvil: una tarjeta por fila; el detalle completo se abre al tocar. */
export function TarjetasRegistros({ filas, onVer, onEditar }: { filas: Fila[]; onVer: (f: Fila) => void; onEditar?: (f: Fila) => void }) {
  return (
    <ul className="tarjetas">
      {filas.map((f) => (
        <li key={f.id} className="tarjeta">
          <button className="tarjeta-cuerpo" onClick={() => onVer(f)}>
            <div>
              <strong>{String(f.valores.nombre_completo ?? '')}</strong>
              <small>{f.numero_empleado}{f.valores.puesto ? ` · ${f.valores.puesto}` : ''} · Ingreso {String(f.valores.fecha_ingreso ?? '')}</small>
            </div>
            <FaltasBadge faltas={Number(f.valores.num_faltas ?? 0)} />
          </button>
          {onEditar && <button className="btn btn-sec btn-sm" onClick={() => onEditar(f)}>Editar</button>}
        </li>
      ))}
    </ul>
  );
}
