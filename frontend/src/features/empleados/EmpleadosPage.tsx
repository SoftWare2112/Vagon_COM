import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { empleadosApi, nominaApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { useAuth } from '../../auth/AuthContext';
import { Aviso } from '../../components/Aviso';
import { CONTRATOS, dinero, fecha, PERIODICIDADES } from '../../utils/formato';

/** v11 — Lista de empleados: buscar, filtrar por estado y puesto, y dar de alta. */
export function EmpleadosPage() {
  const { puede } = useAuth();
  const [busqueda, setBusqueda] = useState('');
  const [q, setQ] = useState('');
  const [estado, setEstado] = useState<'activo' | 'baja' | 'todos'>('activo');
  const [puesto, setPuesto] = useState<number | undefined>();
  const [incompletos, setIncompletos] = useState(false);
  useEffect(() => { const t = setTimeout(() => setQ(busqueda), 300); return () => clearTimeout(t); }, [busqueda]);

  const filtros = { q, estado, puesto_id: puesto, incompletos };
  const { data: empleados = [], isLoading, isError, error } = useQuery({ queryKey: ['empleados', filtros], queryFn: () => empleadosApi.listar(filtros) });
  const { data: puestos = [] } = useQuery({ queryKey: ['puestos'], queryFn: nominaApi.puestos, staleTime: 300_000 });
  const nomina = empleados.filter((e) => e.estado === 'activo').reduce((s, e) => s + (e.salario_mensual ?? 0), 0);

  return (
    <section>
      <div className="encabezado-pagina">
        <div>
          <h1>Empleados</h1>
          <p className="tenue">Expediente de cada trabajador: datos fiscales, puesto, salario y contrato.</p>
        </div>
        {puede('empleados.gestionar') && <Link className="btn" to="/empleados/nuevo">+ Alta de empleado</Link>}
      </div>

      <div className="historial-barra">
        <div className="buscador">
          <span className="buscador-icono" aria-hidden>⌕</span>
          <input type="search" placeholder="Nombre, número o RFC…" value={busqueda} onChange={(e) => setBusqueda(e.target.value)} aria-label="Buscar empleado" />
        </div>
        <select aria-label="Puesto" value={puesto ?? ''} onChange={(e) => setPuesto(e.target.value ? Number(e.target.value) : undefined)}>
          <option value="">Todos los puestos</option>
          {puestos.map((p) => <option key={p.id} value={p.id}>{p.nombre}</option>)}
        </select>
        <div className="segmentado segmentado-sm" role="tablist" aria-label="Estado">
          {(['activo', 'baja', 'todos'] as const).map((e) => (
            <button key={e} role="tab" aria-selected={estado === e} className={estado === e ? 'activo' : ''} onClick={() => setEstado(e)}>
              {{ activo: 'Activos', baja: 'Bajas', todos: 'Todos' }[e]}
            </button>
          ))}
        </div>
        <label className="check"><input type="checkbox" checked={incompletos} onChange={(e) => setIncompletos(e.target.checked)} /> Solo expedientes incompletos</label>
      </div>

      {isError && <Aviso tipo="error">{mensajeDeError(error)}</Aviso>}
      {isLoading && <p>Cargando…</p>}
      {!isLoading && empleados.length === 0 && (
        <div className="vacio">
          {q || puesto || incompletos ? 'Nadie coincide con la búsqueda.' : estado === 'activo'
            ? <>Aún no hay empleados. {puede('empleados.gestionar') && <Link to="/empleados/nuevo">Da de alta al primero</Link>}</>
            : 'No hay empleados en esta vista.'}
        </div>
      )}

      {empleados.length > 0 && (
        <>
          <p className="tenue resumen-lista">{empleados.length} empleado(s){estado !== 'baja' && nomina > 0 ? ` · nómina mensual base ${dinero(nomina)}` : ''}</p>
          <div className="tabla-scroll">
            <table className="tabla tabla-empleados">
              <thead><tr><th>Número</th><th>Nombre</th><th>Puesto</th><th className="num">Sueldo mensual</th><th>Pago</th><th>Contrato</th><th>Ingreso</th><th /></tr></thead>
              <tbody>
                {empleados.map((e) => (
                  <tr key={e.id} className={e.estado === 'baja' ? 'fila-baja' : ''}>
                    <td className="mono">{e.numero_empleado}</td>
                    <td className="col-nombre">
                      <Link to={`/empleados/${e.id}`}>{e.nombre}</Link>
                      {e.estado === 'baja' && <span className="etiqueta etiqueta-neutro">Baja</span>}
                      {e.faltantes.length > 0 && <span className="etiqueta etiqueta-mal" title={`Falta: ${e.faltantes.join(', ')}`}>Incompleto</span>}
                    </td>
                    <td>{e.puesto ?? '—'}</td>
                    <td className="num">{dinero(e.salario_mensual)}</td>
                    <td>{PERIODICIDADES[e.periodicidad]}</td>
                    <td>{CONTRATOS[e.tipo_contrato]}{e.contrato_fin ? <small className="tenue"> · vence {fecha(e.contrato_fin)}</small> : null}</td>
                    <td>{fecha(e.fecha_ingreso)}</td>
                    <td className="acciones"><Link className="btn btn-sec btn-sm" to={`/empleados/${e.id}`}>Ver</Link></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </section>
  );
}
