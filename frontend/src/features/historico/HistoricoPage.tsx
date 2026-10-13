import { Fragment, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { catalogosApi, historicoApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { useAuth } from '../../auth/AuthContext';
import { Aviso } from '../../components/Aviso';
import { CeldaValor } from '../../components/CeldaValor';
import { esFechaValida, mascaraFecha } from '../../utils/formato';

/**
 * Filas quitadas de la plataforma pero conservadas en la BD.
 * Consultar NO restaura: se ve la fila tal como estaba en SU quincena.
 * Restaurar la regresa a esa quincena (no a la última carga).
 */
export function HistoricoPage() {
  const { puede } = useAuth();
  const qc = useQueryClient();
  const [f, setF] = useState({ nombre: '', periodo_id: '', desde: '', hasta: '' });
  const [aplicados, setAplicados] = useState(f);
  const [abierto, setAbierto] = useState<number | null>(null);
  const [aviso, setAviso] = useState<{ tipo: 'exito' | 'error'; texto: string } | null>(null);

  const { data: periodos = [] } = useQuery({ queryKey: ['periodos'], queryFn: catalogosApi.periodos });
  const { data, isLoading } = useQuery({ queryKey: ['historico', aplicados], queryFn: () => historicoApi.listar(aplicados) });
  const restaurar = useMutation({
    mutationFn: (id: number) => historicoApi.restaurar(id),
    onSuccess: (r) => {
      setAviso({ tipo: 'exito', texto: r.data.message });
      void qc.invalidateQueries({ queryKey: ['historico'] });
      void qc.invalidateQueries({ queryKey: ['registros'] });
    },
    onError: (e) => setAviso({ tipo: 'error', texto: mensajeDeError(e) }),
  });

  const fechaOk = (v: string) => v === '' || esFechaValida(v);
  const puedeRestaurar = puede('historico.restaurar');

  return (
    <section>
      <div className="encabezado-pagina"><h1>Histórico</h1></div>
      <p className="tenue">Filas que ya no aparecen en el Dashboard pero se conservan permanentemente. Ver una fila no la restaura.</p>
      {aviso && <Aviso tipo={aviso.tipo}>{aviso.texto}</Aviso>}
      <form className="filtros" onSubmit={(e) => { e.preventDefault(); if (fechaOk(f.desde) && fechaOk(f.hasta)) setAplicados(f); }}>
        <label>Nombre<input value={f.nombre} onChange={(e) => setF({ ...f, nombre: e.target.value })} /></label>
        <label>Quincena
          <select value={f.periodo_id} onChange={(e) => setF({ ...f, periodo_id: e.target.value })}>
            <option value="">Todas</option>
            {periodos.map((p) => <option key={p.id} value={p.id}>{p.etiqueta}</option>)}
          </select>
        </label>
        <label>Eliminado desde<input placeholder="dd/mm/aaaa" inputMode="numeric" value={f.desde} onChange={(e) => setF({ ...f, desde: mascaraFecha(e.target.value) })} /></label>
        <label>hasta<input placeholder="dd/mm/aaaa" inputMode="numeric" value={f.hasta} onChange={(e) => setF({ ...f, hasta: mascaraFecha(e.target.value) })} /></label>
        <div className="filtros-acciones"><button className="btn">Buscar</button></div>
      </form>
      {isLoading && <p>Cargando…</p>}
      {data && data.data.length === 0 && <div className="vacio">No hay filas eliminadas con estos filtros.</div>}
      {data && data.data.length > 0 && (
        <div className="tabla-scroll"><table className="tabla">
          <thead><tr><th>No.</th><th>Nombre</th><th>Quincena</th><th>Eliminado</th><th>Por</th><th>Motivo</th><th /></tr></thead>
          <tbody>{data.data.map((r) => (
            <Fragment key={r.id}>
              <tr>
                <td className="mono">{r.numero_empleado}</td><td className="col-nombre">{r.nombre_completo}</td><td>{r.periodo}</td>
                <td>{r.eliminado}</td><td>{r.eliminado_por}</td><td>{r.motivo}</td>
                <td className="acciones">
                  <button className="btn btn-sec btn-sm" onClick={() => setAbierto(abierto === r.id ? null : r.id)}>{abierto === r.id ? 'Ocultar' : 'Ver datos'}</button>
                  {puedeRestaurar && <button className="btn btn-sec btn-sm" disabled={restaurar.isPending}
                    onClick={() => confirm(`¿Restaurar a ${r.nombre_completo} en ${r.periodo}?`) && restaurar.mutate(r.id)}>Restaurar</button>}
                </td>
              </tr>
              {abierto === r.id && (
                <tr><td colSpan={7}>
                  <dl className="resumen-grid">
                    {r.columnas.map((c) => <div key={c.clave}><dt>{c.etiqueta}</dt><dd><CeldaValor col={c} valor={r.valores[c.clave]} /></dd></div>)}
                  </dl>
                </td></tr>
              )}
            </Fragment>
          ))}</tbody>
        </table></div>
      )}
    </section>
  );
}
