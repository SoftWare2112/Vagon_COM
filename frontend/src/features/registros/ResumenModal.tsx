import { useQuery } from '@tanstack/react-query';
import { registrosApi } from '../../api/endpoints';
import { Modal } from '../../components/Modal';
import { CeldaValor } from '../../components/CeldaValor';
import { FaltasBadge } from '../../components/FaltasBadge';

/** "VISTA EN RESUMEN": la fila de un empleado con la misma estructura del reporte, en una ventana. */
export function ResumenModal({ registroId, onCerrar, onEditar }: { registroId: number | null; onCerrar: () => void; onEditar?: () => void }) {
  const { data, isLoading } = useQuery({
    queryKey: ['resumen', registroId],
    queryFn: () => registrosApi.resumen(registroId!),
    enabled: registroId != null,
  });

  return (
    <Modal titulo="Vista en resumen" abierto={registroId != null} onCerrar={onCerrar}
      pie={onEditar && <button className="btn" onClick={onEditar}>Editar registro</button>}>
      {isLoading || !data ? <p>Cargando…</p> : (
        <div className="resumen">
          <div className="resumen-cab">
            <h3>{String(data.valores.nombre_completo ?? '')}</h3>
            <p>{data.numero_empleado} · Ingreso {String(data.valores.fecha_ingreso ?? '')}</p>
            <p className="tenue">{data.periodo}</p>
          </div>
          <dl className="resumen-grid">
            <div><dt>Faltas en el mes</dt><dd><FaltasBadge faltas={data.faltas_mes} /></dd></div>
            {data.columnas.filter((c) => !['nombre_completo', 'fecha_ingreso'].includes(c.clave)).map((c) => (
              <div key={c.clave} className={c.tipo === 'texto_largo' ? 'col-completa' : undefined}>
                <dt>{c.etiqueta}</dt>
                <dd><CeldaValor col={c} valor={data.valores[c.clave]} /> {data.valores[c.clave] == null && c.clave !== 'num_faltas' && <span className="tenue">—</span>}</dd>
              </div>
            ))}
          </dl>
        </div>
      )}
    </Modal>
  );
}
