import { useEffect, useMemo, useState, type KeyboardEvent } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';
import { nominaApi, nominaPeriodoApi, type FilaIncidencia } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { useAuth } from '../../auth/AuthContext';
import { Aviso } from '../../components/Aviso';
import { Modal } from '../../components/Modal';
import type { Captura, FilaCaptura, Periodicidad, PeriodoNomina } from '../../types';
import { dinero, fecha, hoyISO, PERIODICIDADES } from '../../utils/formato';
import { EstadoPeriodo, ReciboModal } from './piezas';

type Cambios = Record<number, FilaIncidencia>;

/**
 * v12 — Nómina del periodo, al estilo CONTPAQi:
 *   1. Capturar incidencias (faltas, retardos, domingos y montos variables) en una cuadrícula
 *   2. Calcular la pre-nómina y revisar cada recibo
 *   3. Autorizar: los recibos se guardan en el historial y quedan de solo lectura
 */
export function NominaPage() {
  const { puede } = useAuth();
  const qc = useQueryClient();
  const [params, setParams] = useSearchParams();
  const { data: empresa } = useQuery({ queryKey: ['empresa'], queryFn: nominaApi.empresa, staleTime: 300_000 });
  const [tipo, setTipo] = useState<Periodicidad | null>(null);
  const [anio, setAnio] = useState(Number(hoyISO().slice(0, 4)));
  const t = tipo ?? empresa?.periodicidad ?? 'quincenal';
  const { data: periodos = [], isLoading: cargandoPeriodos } = useQuery({ queryKey: ['periodos', t, anio], queryFn: () => nominaApi.periodos(t, anio), enabled: !!empresa });

  // Periodo elegido: el de la URL, o el primero sin autorizar que ya empezó (el "periodo vigente")
  const vigente = useMemo(() => {
    const hoy = hoyISO();
    return periodos.find((p) => p.estado !== 'autorizado' && p.inicio <= hoy && hoy <= p.fin)
      ?? periodos.find((p) => p.estado !== 'autorizado' && p.inicio <= hoy)
      ?? periodos.find((p) => p.inicio <= hoy && hoy <= p.fin) ?? periodos[0];
  }, [periodos]);
  const periodoId = Number(params.get('periodo')) || vigente?.id;

  const { data: captura, isLoading, isError, error } = useQuery({
    queryKey: ['captura', periodoId], queryFn: () => nominaPeriodoApi.captura(periodoId!), enabled: !!periodoId,
  });
  useEffect(() => {
    if (captura && (captura.periodo.tipo !== t || captura.periodo.ejercicio !== anio)) { setTipo(captura.periodo.tipo); setAnio(captura.periodo.ejercicio); }
  }, [captura]); // eslint-disable-line react-hooks/exhaustive-deps

  const [cambios, setCambios] = useState<Cambios>({});
  const [avisos, setAvisos] = useState<string[]>([]);
  const [mensaje, setMensaje] = useState<{ tipo: 'exito' | 'error' | 'info'; texto: string } | null>(null);
  const [trabajando, setTrabajando] = useState<string | null>(null);
  const [recibo, setRecibo] = useState<number | null>(null);
  const [dialogo, setDialogo] = useState<'autorizar' | 'reabrir' | null>(null);
  useEffect(() => { setCambios({}); setAvisos([]); setMensaje(null); }, [periodoId]);

  const elegir = (id: number) => setParams({ periodo: String(id) }, { replace: true });
  const actualizar = (c: Captura) => { qc.setQueryData(['captura', c.periodo.id], c); void qc.invalidateQueries({ queryKey: ['periodos'] }); void qc.invalidateQueries({ queryKey: ['historial'] }); };

  if (!empresa || cargandoPeriodos) return <p>Cargando…</p>;
  if (periodos.length === 0) {
    return (
      <section>
        <Encabezado />
        <Selector tipo={t} anio={anio} periodos={[]} periodoId={undefined} onTipo={setTipo} onAnio={setAnio} onPeriodo={elegir} />
        <div className="vacio">No hay calendario de pagos {PERIODICIDADES[t].toLowerCase()} para {anio}. {puede('nomina.configurar')
          ? <Link to="/nomina/configuracion?pestana=periodos">Genéralo en Configuración de nómina</Link> : 'Pide a un administrador que lo genere.'}</div>
      </section>
    );
  }

  const autorizado = captura?.periodo.estado === 'autorizado';
  const editable = !!captura && !autorizado && puede('nomina.calcular');
  const pendientes = Object.keys(cambios).length;
  const valor = (f: FilaCaptura, campo: 'faltas' | 'retardos' | 'domingos') => cambios[f.empleado_id]?.[campo] ?? f[campo];
  const capturado = (f: FilaCaptura, clave: string) => cambios[f.empleado_id]?.capturados?.[clave] ?? f.capturados[clave] ?? 0;
  const cambiar = (f: FilaCaptura, c: Partial<FilaIncidencia>) => setCambios((x) => {
    const base = x[f.empleado_id] ?? { empleado_id: f.empleado_id, faltas: f.faltas, retardos: f.retardos, domingos: f.domingos, capturados: { ...f.capturados } };
    return { ...x, [f.empleado_id]: { ...base, ...c, capturados: { ...base.capturados, ...(c.capturados ?? {}) } } };
  });

  const guardar = async (): Promise<boolean> => {
    if (!pendientes || !captura) return true;
    try {
      actualizar(await nominaPeriodoApi.incidencias(captura.periodo.id, Object.values(cambios)));
      setCambios({});
      return true;
    } catch (e) { setMensaje({ tipo: 'error', texto: mensajeDeError(e) }); return false; }
  };
  const accion = async (nombre: string, fn: () => Promise<void>) => {
    setTrabajando(nombre); setMensaje(null);
    try { await fn(); } catch (e) { setMensaje({ tipo: 'error', texto: mensajeDeError(e) }); } finally { setTrabajando(null); }
  };
  const calcular = () => accion('calcular', async () => {
    if (!(await guardar())) return;
    const r = await nominaPeriodoApi.calcular(captura!.periodo.id);
    actualizar(r.captura);
    setAvisos(r.avisos);
    setMensaje({ tipo: 'exito', texto: `Pre-nómina calculada: ${r.captura.totales?.empleados ?? 0} recibo(s). Revisa los importes antes de autorizar.` });
  });

  // Enter / flechas: baja a la misma columna del siguiente empleado (como en una hoja de cálculo)
  const navegar = (e: KeyboardEvent<HTMLInputElement>) => {
    const fila = Number(e.currentTarget.dataset.fila);
    const col = e.currentTarget.dataset.col;
    const destino = e.key === 'Enter' || e.key === 'ArrowDown' ? fila + 1 : e.key === 'ArrowUp' ? fila - 1 : null;
    if (destino === null) return;
    e.preventDefault();
    document.querySelector<HTMLInputElement>(`input[data-fila="${destino}"][data-col="${col}"]`)?.focus();
  };
  const celda = (f: FilaCaptura, i: number, col: string, v: number, max: number, set: (n: number) => void, dinero_ = false) => editable
    ? <input type="number" className={`input-celda ${cambios[f.empleado_id] ? 'editada' : ''}`} min={0} max={max} step={dinero_ ? '0.01' : 1} value={v || ''} placeholder="0"
        data-fila={i} data-col={col} onKeyDown={navegar} onFocus={(e) => e.currentTarget.select()} aria-label={`${col} de ${f.nombre}`}
        onChange={(e) => set(Math.max(0, Math.min(max, Number(e.target.value) || 0)))} />
    : <span className={v ? '' : 'tenue'}>{dinero_ ? (v ? dinero(v) : '—') : v}</span>;

  return (
    <section className="nomina">
      <Encabezado />
      <Selector tipo={t} anio={anio} periodos={periodos} periodoId={periodoId} onTipo={(x) => { setTipo(x); setParams({}, { replace: true }); }}
        onAnio={(x) => { setAnio(x); setParams({}, { replace: true }); }} onPeriodo={elegir} />

      {isError && <Aviso tipo="error">{mensajeDeError(error)}</Aviso>}
      {(isLoading || !captura) && !isError && <p>Cargando…</p>}
      {captura && (
        <>
          <div className="tarjeta periodo-cab">
            <div>
              <h2>{captura.periodo.etiqueta}</h2>
              <p className="tenue">Pago el {fecha(captura.periodo.fecha_pago)} · {captura.periodo.dias} días{captura.periodo.fin_de_mes ? ' · cierra el mes (bonos de fin de mes)' : ''}</p>
            </div>
            <EstadoPeriodo p={captura.periodo} pendiente={captura.pendiente_recalcular || pendientes > 0} />
          </div>

          {mensaje && <Aviso tipo={mensaje.tipo}>{mensaje.texto} <button className="btn-link" onClick={() => setMensaje(null)}>Cerrar</button></Aviso>}
          {autorizado && <Aviso tipo="info">Nómina autorizada el {captura.periodo.autorizado_at}{captura.periodo.autorizado_por ? ` por ${captura.periodo.autorizado_por}` : ''}. Es de solo lectura: los recibos están en el historial.</Aviso>}
          {captura.pendiente_recalcular && !autorizado && <Aviso tipo="advertencia">Cambiaron las incidencias después del último cálculo. Vuelve a calcular antes de autorizar.</Aviso>}

          <div className="barra-acciones barra-nomina">
            {editable && <>
              <button className="btn btn-sec" disabled={!pendientes || !!trabajando} onClick={() => void accion('guardar', async () => { if (await guardar()) setMensaje({ tipo: 'exito', texto: 'Captura guardada.' }); })}>
                {pendientes ? `Guardar captura (${pendientes})` : 'Captura guardada'}
              </button>
              <button className="btn" disabled={!!trabajando || captura.filas.length === 0} onClick={() => void calcular()}>
                {trabajando === 'calcular' ? 'Calculando…' : captura.totales ? 'Recalcular pre-nómina' : 'Calcular pre-nómina'}
              </button>
            </>}
            {puede('nomina.autorizar') && captura.periodo.estado === 'calculado' && pendientes === 0 && (
              <button className="btn btn-autorizar" onClick={() => setDialogo('autorizar')}>Autorizar nómina…</button>
            )}
            {puede('nomina.autorizar') && autorizado && <button className="btn btn-sec" onClick={() => setDialogo('reabrir')}>Reabrir…</button>}
            {captura.totales && <Link className="btn btn-sec" to={`/nomina/reportes?pestana=raya&periodo=${captura.periodo.id}`}>Lista de raya</Link>}
          </div>

          {captura.totales && (
            <div className="kpis kpis-nomina">
              <div className="kpi"><span className="tenue">Recibos</span><strong>{captura.totales.empleados}</strong></div>
              <div className="kpi"><span className="tenue">Percepciones</span><strong>{dinero(captura.totales.percepciones)}</strong></div>
              <div className="kpi"><span className="tenue">ISR retenido</span><strong>{dinero(captura.totales.isr)}</strong></div>
              <div className="kpi"><span className="tenue">IMSS obrero</span><strong>{dinero(captura.totales.imss)}</strong></div>
              <div className="kpi kpi-destacado"><span className="tenue">Neto a pagar</span><strong>{dinero(captura.totales.neto)}</strong></div>
            </div>
          )}

          {avisos.length > 0 && (
            <details className="tarjeta avisos-calculo">
              <summary>{avisos.length} aviso(s) del cálculo</summary>
              <ul>{avisos.map((a) => <li key={a}>{a}</li>)}</ul>
            </details>
          )}

          {captura.filas.length === 0
            ? <div className="vacio">Ningún empleado con pago {PERIODICIDADES[captura.periodo.tipo].toLowerCase()} estuvo contratado en este periodo. {puede('empleados.gestionar') && <Link to="/empleados/nuevo">Dar de alta</Link>}</div>
            : (
              <div className="tabla-scroll">
                <table className="tabla tabla-captura">
                  <thead>
                    <tr>
                      <th>Núm.</th><th>Empleado</th><th className="num">Días</th>
                      <th className="num">Faltas</th><th className="num">Retardos</th><th className="num">Domingos</th>
                      {captura.columnas.map((c) => <th key={c.clave} className="num" title={c.tipo === 'deduccion' ? 'Deducción' : 'Percepción'}>{c.nombre}</th>)}
                      {captura.totales && <><th className="num">Percepciones</th><th className="num">Deducciones</th><th className="num">Neto</th><th /></>}
                    </tr>
                  </thead>
                  <tbody>
                    {captura.filas.map((f, i) => {
                      const desactualizado = !!f.recibo && (captura.pendiente_recalcular || !!cambios[f.empleado_id]);
                      return (
                        <tr key={f.empleado_id}>
                          <td className="mono">{f.numero_empleado}</td>
                          <td className="col-nombre"><Link to={`/empleados/${f.empleado_id}`}>{f.nombre}</Link><small className="tenue linea-sub">{f.puesto ?? '—'}</small></td>
                          <td className="num">{captura.periodo.dias - f.dias_fuera}{f.dias_fuera > 0 && <small className="tenue linea-sub" title="Ingresó o se dio de baja dentro del periodo">de {captura.periodo.dias}</small>}</td>
                          <td className="num">{celda(f, i, 'faltas', valor(f, 'faltas'), captura.periodo.dias - f.dias_fuera, (n) => cambiar(f, { faltas: n }))}</td>
                          <td className="num">{celda(f, i, 'retardos', valor(f, 'retardos'), 31, (n) => cambiar(f, { retardos: n }))}</td>
                          <td className="num">{celda(f, i, 'domingos', valor(f, 'domingos'), 5, (n) => cambiar(f, { domingos: n }))}</td>
                          {captura.columnas.map((c) => (
                            <td key={c.clave} className="num">{celda(f, i, c.clave, capturado(f, c.clave), 999999, (n) => cambiar(f, { capturados: { [c.clave]: n } }), true)}</td>
                          ))}
                          {captura.totales && (f.recibo ? <>
                            <td className={`num ${desactualizado ? 'tenue' : ''}`}>{dinero(f.recibo.percepciones)}</td>
                            <td className={`num ${desactualizado ? 'tenue' : ''}`}>{dinero(f.recibo.deducciones)}</td>
                            <td className={`num neto ${desactualizado ? 'tenue' : ''}`}>{dinero(f.recibo.neto)}{f.recibo.avisos.length > 0 && <span className="marca-aviso" title={f.recibo.avisos.join('\n')}> ⚠</span>}</td>
                            <td className="acciones"><button className="btn btn-sec btn-sm" onClick={() => setRecibo(f.recibo!.id)}>Recibo</button></td>
                          </> : <td colSpan={4} className="tenue">Sin calcular</td>)}
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          {editable && captura.filas.length > 0 && <p className="tenue ayuda-captura">Escribe solo lo que cambió; lo vacío cuenta como 0. Enter baja al siguiente empleado. Los bonos y la prima dominical se calculan solos con las reglas de Configuración de nómina.</p>}
        </>
      )}

      {recibo !== null && <ReciboModal id={recibo} onCerrar={() => setRecibo(null)} />}
      {dialogo === 'autorizar' && captura && <DialogoAutorizar captura={captura} onCerrar={() => setDialogo(null)}
        onListo={(c, texto) => { actualizar(c); setDialogo(null); setMensaje({ tipo: 'exito', texto }); }} />}
      {dialogo === 'reabrir' && captura && <DialogoReabrir periodo={captura.periodo} onCerrar={() => setDialogo(null)}
        onListo={(c, texto) => { actualizar(c); setDialogo(null); setMensaje({ tipo: 'info', texto }); }} />}
    </section>
  );
}

function Encabezado() {
  return (
    <div className="encabezado-pagina">
      <div>
        <h1>Nómina del periodo</h1>
        <p className="tenue">Captura las incidencias, calcula la pre-nómina, revísala y autorízala.</p>
      </div>
      <Link className="btn btn-sec" to="/nomina/historial">Historial de nóminas</Link>
    </div>
  );
}

function Selector({ tipo, anio, periodos, periodoId, onTipo, onAnio, onPeriodo }: {
  tipo: Periodicidad; anio: number; periodos: PeriodoNomina[]; periodoId?: number;
  onTipo: (t: Periodicidad) => void; onAnio: (a: number) => void; onPeriodo: (id: number) => void;
}) {
  const actual = Number(hoyISO().slice(0, 4));
  return (
    <div className="historial-barra">
      <div className="segmentado segmentado-sm" role="tablist" aria-label="Periodicidad">
        {(Object.keys(PERIODICIDADES) as Periodicidad[]).map((p) => <button key={p} className={tipo === p ? 'activo' : ''} onClick={() => onTipo(p)}>{PERIODICIDADES[p]}</button>)}
      </div>
      <select aria-label="Ejercicio" value={anio} onChange={(e) => onAnio(Number(e.target.value))}>
        {[actual - 1, actual, actual + 1].map((a) => <option key={a}>{a}</option>)}
      </select>
      {periodos.length > 0 && (
        <select aria-label="Periodo" className="select-periodo" value={periodoId ?? ''} onChange={(e) => onPeriodo(Number(e.target.value))}>
          {periodos.map((p) => <option key={p.id} value={p.id}>{String(p.numero).padStart(2, '0')} · {fecha(p.inicio)} – {fecha(p.fin)} · {p.estado === 'autorizado' ? 'Autorizado' : p.estado === 'calculado' ? 'Pre-nómina' : 'Abierto'}</option>)}
        </select>
      )}
    </div>
  );
}

function DialogoAutorizar({ captura, onCerrar, onListo }: { captura: Captura; onCerrar: () => void; onListo: (c: Captura, texto: string) => void }) {
  const [revisado, setRevisado] = useState(false);
  const [respaldo, setRespaldo] = useState<{ estado: 'nada' | 'creando' | 'listo' | 'error'; texto?: string }>({ estado: 'nada' });
  const [error, setError] = useState<string | null>(null);
  const [enviando, setEnviando] = useState(false);
  const t = captura.totales!;
  const respaldar = async () => {
    setRespaldo({ estado: 'creando' });
    try { const r = await nominaPeriodoApi.crearRespaldo(`antes-de-autorizar-${captura.periodo.ejercicio}-${captura.periodo.numero}`); setRespaldo({ estado: 'listo', texto: `${r.archivo} (${(r.tamano / 1024).toFixed(0)} KB)` }); }
    catch (e) { setRespaldo({ estado: 'error', texto: mensajeDeError(e) }); }
  };
  const autorizar = async () => {
    setEnviando(true); setError(null);
    try { const r = await nominaPeriodoApi.autorizar(captura.periodo.id); onListo(r.captura, r.message); }
    catch (e) { setError(mensajeDeError(e)); } finally { setEnviando(false); }
  };
  return (
    <Modal titulo={`Autorizar ${captura.periodo.etiqueta}`} abierto onCerrar={onCerrar} ancho="md"
      pie={<><button className="btn btn-sec" onClick={onCerrar}>Cancelar</button>
        <button className="btn btn-autorizar" disabled={!revisado || enviando || respaldo.estado === 'creando'} onClick={() => void autorizar()}>{enviando ? 'Autorizando…' : 'Autorizar nómina'}</button></>}>
      {error && <Aviso tipo="error">{error}</Aviso>}
      <p>Al autorizar, el sistema guarda la <strong>fotografía exacta</strong> de cada recibo (sueldos, percepciones, deducciones e incidencias), actualiza los acumulados del año y el periodo queda de <strong>solo lectura</strong>.</p>
      <dl className="datos resumen-autorizar">
        <dt>Recibos</dt><dd>{t.empleados}</dd>
        <dt>Percepciones</dt><dd>{dinero(t.percepciones)}</dd>
        <dt>Deducciones</dt><dd>{dinero(t.deducciones)}</dd>
        <dt>Neto a pagar</dt><dd><strong>{dinero(t.neto)}</strong></dd>
      </dl>
      <div className="caja-respaldo">
        <strong>Respaldo recomendado</strong>
        <p className="tenue">Crea un respaldo de la base de datos antes de avanzar: permite regresar a este punto exacto si después se detecta un error grave.</p>
        {respaldo.estado === 'nada' && <button className="btn btn-sec btn-sm" onClick={() => void respaldar()}>Crear respaldo ahora</button>}
        {respaldo.estado === 'creando' && <p>Creando respaldo…</p>}
        {respaldo.estado === 'listo' && <Aviso tipo="exito">Respaldo creado: {respaldo.texto}</Aviso>}
        {respaldo.estado === 'error' && <Aviso tipo="advertencia">No se pudo crear el respaldo: {respaldo.texto} Puedes autorizar de todos modos.</Aviso>}
      </div>
      <p className="tenue">{captura.timbrado_activo
        ? 'El timbrado está activo: los recibos quedarán «pendientes de timbrar» (el envío al PAC llega en una versión posterior).'
        : 'El timbrado está desactivado en Configuración de nómina: los recibos se guardan sin folio fiscal (UUID).'}</p>
      <label className="check"><input type="checkbox" checked={revisado} onChange={(e) => setRevisado(e.target.checked)} /> Revisé la pre-nómina y los importes son correctos</label>
    </Modal>
  );
}

function DialogoReabrir({ periodo, onCerrar, onListo }: { periodo: PeriodoNomina; onCerrar: () => void; onListo: (c: Captura, texto: string) => void }) {
  const [motivo, setMotivo] = useState('');
  const [error, setError] = useState<string | null>(null);
  const reabrir = async () => {
    try { const r = await nominaPeriodoApi.reabrir(periodo.id, motivo); onListo(r.captura, r.message); } catch (e) { setError(mensajeDeError(e)); }
  };
  return (
    <Modal titulo={`Reabrir ${periodo.etiqueta}`} abierto onCerrar={onCerrar} ancho="sm"
      pie={<><button className="btn btn-sec" onClick={onCerrar}>Cancelar</button><button className="btn" disabled={motivo.trim().length < 10} onClick={() => void reabrir()}>Reabrir</button></>}>
      {error && <Aviso tipo="error">{error}</Aviso>}
      <p>Solo se puede reabrir el <strong>último</strong> periodo autorizado y únicamente si ningún recibo se timbró. Los acumulados del año se descuentan y los recibos vuelven a pre-nómina.</p>
      <label>Motivo (queda en la bitácora)<textarea rows={3} value={motivo} onChange={(e) => setMotivo(e.target.value)} placeholder="Ej. Faltó capturar un domingo trabajado de un empleado" /></label>
    </Modal>
  );
}
