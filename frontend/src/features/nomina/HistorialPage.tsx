import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { nominaApi, nominaPeriodoApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { useAuth } from '../../auth/AuthContext';
import { Aviso } from '../../components/Aviso';
import type { Periodicidad } from '../../types';
import { dinero, fecha, hoyISO, PERIODICIDADES } from '../../utils/formato';
import { EtiquetaEstado } from './piezas';

/**
 * v12 — Historial de nóminas: archivo acumulativo que se alimenta cada vez que se autoriza un periodo.
 * Desde aquí se abren los periodos, sus recibos y sus reportes; y las herramientas de respaldo y verificación.
 */
export function HistorialPage() {
  const { puede } = useAuth();
  const { data: empresa } = useQuery({ queryKey: ['empresa'], queryFn: nominaApi.empresa, staleTime: 300_000 });
  const [tipo, setTipo] = useState<Periodicidad | null>(null);
  const actual = Number(hoyISO().slice(0, 4));
  const [anio, setAnio] = useState(actual);
  const t = tipo ?? empresa?.periodicidad ?? 'quincenal';
  const { data = [], isLoading } = useQuery({ queryKey: ['historial', t, anio], queryFn: () => nominaPeriodoApi.historial(anio, t), enabled: !!empresa });
  const conNomina = data.filter((p) => p.recibos > 0);
  const autorizados = data.filter((p) => p.estado === 'autorizado');

  return (
    <section>
      <div className="encabezado-pagina">
        <div>
          <h1>Historial de nóminas</h1>
          <p className="tenue">Cada nómina autorizada queda guardada tal como se pagó y no se puede modificar.</p>
        </div>
        <div className="barra-acciones">
          <Link className="btn btn-sec" to="/nomina/recibos">Consultar recibos</Link>
          <Link className="btn btn-sec" to="/nomina/reportes">Reportes</Link>
        </div>
      </div>
      <div className="historial-barra">
        <div className="segmentado segmentado-sm">
          {(Object.keys(PERIODICIDADES) as Periodicidad[]).map((p) => <button key={p} className={t === p ? 'activo' : ''} onClick={() => setTipo(p)}>{PERIODICIDADES[p]}</button>)}
        </div>
        <select aria-label="Ejercicio" value={anio} onChange={(e) => setAnio(Number(e.target.value))}>
          {[actual - 2, actual - 1, actual, actual + 1].map((a) => <option key={a}>{a}</option>)}
        </select>
      </div>

      {autorizados.length > 0 && (
        <div className="kpis">
          <div className="kpi"><span className="tenue">Periodos autorizados</span><strong>{autorizados.length} de {data.length}</strong></div>
          <div className="kpi"><span className="tenue">Neto pagado en {anio}</span><strong>{dinero(autorizados.reduce((s, p) => s + p.neto, 0))}</strong></div>
          <div className="kpi"><span className="tenue">Percepciones en {anio}</span><strong>{dinero(autorizados.reduce((s, p) => s + p.percepciones, 0))}</strong></div>
        </div>
      )}

      {isLoading && <p>Cargando…</p>}
      {!isLoading && conNomina.length === 0 && <div className="vacio">Aún no hay nóminas calculadas en {anio}. <Link to="/nomina">Ir a la nómina del periodo</Link></div>}
      {conNomina.length > 0 && (
        <div className="tabla-scroll">
          <table className="tabla">
            <thead><tr><th>Núm.</th><th>Periodo</th><th>Pago</th><th>Estado</th><th className="num">Recibos</th><th className="num">Neto</th><th>Autorizó</th><th /></tr></thead>
            <tbody>
              {conNomina.slice().reverse().map((p) => (
                <tr key={p.id}>
                  <td className="mono">{String(p.numero).padStart(2, '0')}</td>
                  <td>{fecha(p.inicio)} – {fecha(p.fin)}</td>
                  <td>{fecha(p.fecha_pago)}</td>
                  <td><EtiquetaEstado estado={p.estado} />{p.timbrados > 0 && <span className="etiqueta etiqueta-info">{p.timbrados} timbrado(s)</span>}</td>
                  <td className="num">{p.recibos}</td>
                  <td className="num">{dinero(p.neto)}</td>
                  <td>{p.autorizado_por ? <>{p.autorizado_por}<small className="tenue linea-sub">{p.autorizado_at}</small></> : <span className="tenue">—</span>}</td>
                  <td className="acciones">
                    <Link className="btn btn-sec btn-sm" to={`/nomina?periodo=${p.id}`}>Abrir</Link>
                    <Link className="btn btn-sec btn-sm" to={`/nomina/reportes?pestana=raya&periodo=${p.id}`}>Lista de raya</Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {puede('nomina.autorizar') && <Herramientas anio={anio} />}
    </section>
  );
}

/** Respaldos de la base de datos y verificación del historial (como «Reconstrucción del historial» de CONTPAQi). */
function Herramientas({ anio }: { anio: number }) {
  const qc = useQueryClient();
  const { data: respaldos = [], isError: sinRespaldos } = useQuery({ queryKey: ['respaldos'], queryFn: nominaPeriodoApi.respaldos });
  const [aviso, setAviso] = useState<{ tipo: 'exito' | 'error' | 'advertencia'; texto: string } | null>(null);
  const [trabajando, setTrabajando] = useState(false);
  const correr = async (fn: () => Promise<void>) => {
    setTrabajando(true); setAviso(null);
    try { await fn(); } catch (e) { setAviso({ tipo: 'error', texto: mensajeDeError(e) }); } finally { setTrabajando(false); }
  };
  return (
    <div className="expediente-rejilla herramientas">
      <div className="tarjeta">
        <div className="seccion-cab"><h2 className="seccion-titulo">Respaldos</h2>
          <button className="btn btn-sec btn-sm" disabled={trabajando} onClick={() => void correr(async () => {
            const r = await nominaPeriodoApi.crearRespaldo('manual'); setAviso({ tipo: 'exito', texto: `Respaldo creado: ${r.archivo}` }); void qc.invalidateQueries({ queryKey: ['respaldos'] });
          })}>Crear respaldo</button></div>
        {aviso && <Aviso tipo={aviso.tipo}>{aviso.texto}</Aviso>}
        {sinRespaldos || respaldos.length === 0
          ? <p className="tenue">Sin respaldos todavía. Se sugiere uno antes de cada autorización; también se crea uno diario si el programador de tareas está activo.</p>
          : <ul className="lista-simple lista-respaldos">{respaldos.slice(0, 8).map((r) => (
              <li key={r.archivo}><span className="mono">{r.archivo}</span><small className="tenue">{r.fecha} · {(r.tamano / 1024).toFixed(0)} KB</small>
                <button className="btn-link" onClick={() => void nominaPeriodoApi.descargarRespaldo(r.archivo)}>Descargar</button></li>))}</ul>}
      </div>
      <div className="tarjeta">
        <h2 className="seccion-titulo">Verificar historial</h2>
        <p className="tenue">Revisa la huella de cada recibo autorizado de {anio} (detecta cambios hechos directamente en la base de datos) y vuelve a armar los acumulados del año. No modifica ningún recibo.</p>
        <button className="btn btn-sec btn-sm" disabled={trabajando} onClick={() => void correr(async () => {
          const r = await nominaPeriodoApi.reconstruir(anio);
          setAviso(r.alterados.length
            ? { tipo: 'advertencia', texto: `${r.alterados.length} recibo(s) alterado(s): ${r.alterados.map((a) => `${a.empleado} (${a.periodo})`).join('; ')}` }
            : { tipo: 'exito', texto: `${r.recibos} recibo(s) íntegros; ${r.acumulados} acumulado(s) reconstruidos.` });
        })}>Verificar y reconstruir acumulados</button>
      </div>
    </div>
  );
}
