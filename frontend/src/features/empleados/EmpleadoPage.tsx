import { useState, type ReactNode } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { empleadosApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { useAuth } from '../../auth/AuthContext';
import { Aviso } from '../../components/Aviso';
import { Modal } from '../../components/Modal';
import type { Empleado, TipoContrato } from '../../types';
import { CONTRATOS, DIAS_SEMANA, dinero, fecha, hoyISO, numero, PERIODICIDADES } from '../../utils/formato';
import { Simulador } from './Simulador';
import { VacacionesCard } from './VacacionesCard';

type Dialogo = 'salario' | 'contrato' | 'baja' | null;

const MOVIMIENTOS: Record<Empleado['movimientos'][number]['tipo'], string> = {
  alta: 'Alta', baja: 'Baja', reingreso: 'Reingreso', salario: 'Cambio de salario', contrato: 'Contrato', puesto: 'Cambio de puesto',
};

/** v11 — Expediente del empleado: datos, prestaciones calculadas, historial y simulador de nómina. */
export function EmpleadoPage() {
  const id = Number(useParams().id);
  const { puede } = useAuth();
  const qc = useQueryClient();
  const { data: e, isLoading, isError, error } = useQuery({ queryKey: ['empleado', id], queryFn: () => empleadosApi.obtener(id) });
  const [dialogo, setDialogo] = useState<Dialogo>(null);
  const [aviso, setAviso] = useState<{ tipo: 'exito' | 'error'; texto: string } | null>(null);

  if (isLoading) return <p>Cargando…</p>;
  if (isError || !e) return <Aviso tipo="error">{mensajeDeError(error)}</Aviso>;
  const gestiona = puede('empleados.gestionar');
  const c = e.calculos;

  const actualizado = (nuevo: Empleado, texto: string) => {
    qc.setQueryData(['empleado', id], nuevo);
    void qc.invalidateQueries({ queryKey: ['empleados'] });
    void qc.invalidateQueries({ queryKey: ['simulacion', id] });
    setDialogo(null);
    setAviso({ tipo: 'exito', texto });
  };
  const reactivar = async () => {
    try { actualizado(await empleadosApi.reactivar(e.id, hoyISO()), 'Empleado reactivado: el reingreso quedó en su trayectoria.'); } catch (x) { setAviso({ tipo: 'error', texto: mensajeDeError(x) }); }
  };

  return (
    <section className="expediente">
      <Link to="/empleados" className="btn-link">← Empleados</Link>
      <header className="expediente-cab">
        <div>
          <p className="tenue mono">{e.numero_empleado}</p>
          <h1>{e.nombre}</h1>
          <p>{e.puesto ?? 'Sin puesto'}{e.departamento ? ` · ${e.departamento}` : ''} · {CONTRATOS[e.tipo_contrato]}
            {e.estado === 'baja' && <span className="etiqueta etiqueta-neutro">Baja desde {fecha(e.fecha_baja)}</span>}</p>
        </div>
        {gestiona && (
          <div className="barra-acciones">
            <Link className="btn btn-sec" to={`/empleados/${e.id}/editar`}>Editar datos</Link>
            {e.estado === 'activo' ? <>
              <button className="btn btn-sec" onClick={() => setDialogo('salario')}>Cambiar salario</button>
              <button className="btn btn-sec" onClick={() => setDialogo('contrato')}>Nuevo contrato</button>
              <button className="btn btn-sec texto-peligro" onClick={() => setDialogo('baja')}>Dar de baja</button>
            </> : <button className="btn" onClick={() => void reactivar()}>Reactivar</button>}
          </div>
        )}
      </header>

      {aviso && <Aviso tipo={aviso.tipo}>{aviso.texto} <button className="btn-link" onClick={() => setAviso(null)}>Cerrar</button></Aviso>}
      {Object.entries(e.validaciones_omitidas ?? {}).map(([campo, v]) => v && (
        <Aviso key={campo} tipo="info">El {campo.toUpperCase()} no pasa el dígito verificador y se aceptó como correcto: «{v.motivo}» ({v.por_nombre ?? 'sin usuario'}, {fecha(v.fecha)}). Revísalo si el SAT o el banco lo rechazan.</Aviso>
      ))}
      {e.faltantes.length > 0 && <Aviso tipo="advertencia">Expediente incompleto: falta {e.faltantes.join(', ')}. Sin estos datos no se podrá timbrar su recibo.</Aviso>}

      <div className="kpis">
        <Kpi titulo="Sueldo mensual" valor={dinero(e.salario_mensual)} nota={PERIODICIDADES[e.periodicidad]} />
        <Kpi titulo="Salario diario" valor={dinero(c.salario_diario)} nota="Sueldo ÷ días del divisor" />
        <Kpi titulo="SDI (IMSS)" valor={dinero(c.sdi)} nota={`Factor ${numero(c.factor_integracion, 6)}`} />
        <Kpi titulo="Antigüedad" valor={c.antiguedad_anios > 0 ? `${c.antiguedad_anios} año(s)` : `${c.antiguedad_meses} mes(es)`} nota={`Desde ${fecha(e.fecha_ingreso)}`} />
      </div>

      <div className="expediente-rejilla">
        <div className="tarjeta">
          <h2 className="seccion-titulo">Datos fiscales</h2>
          <dl className="datos">
            <Dato t="RFC" v={e.rfc} mono /><Dato t="CURP" v={e.curp} mono /><Dato t="NSS" v={e.nss} mono />
            <Dato t="C.P. fiscal" v={e.codigo_postal} /><Dato t="Correo" v={e.email} /><Dato t="Teléfono" v={e.telefono} />
            <Dato t="Banco" v={e.banco} /><Dato t="CLABE" v={e.clabe} mono />
          </dl>
        </div>
        <div className="tarjeta">
          <h2 className="seccion-titulo">Prestaciones</h2>
          <dl className="datos">
            <Dato t="Vacaciones" v={`${c.dias_vacaciones} días ${c.antiguedad_anios < 1 ? `(a partir del ${fecha(c.vacaciones_desde)})` : 'por año de servicio (saldo abajo)'}`} />
            <Dato t="Prima vacacional" v={`25 % de los días de vacaciones (${dinero((c.salario_diario ?? 0) * c.dias_vacaciones * 0.25)})`} />
            <Dato t="Aguinaldo" v={`${numero(c.aguinaldo_dias, 2)} días proporcionales este año (${dinero((c.salario_diario ?? 0) * c.aguinaldo_dias)})`} />
            <Dato t="Jornada" v={`${e.jornada_horas ?? '—'} h · descansa ${e.dias_descanso?.length ? e.dias_descanso.map((d) => DIAS_SEMANA[d]).join(', ') : '(sin definir)'}`} />
          </dl>
        </div>
      </div>

      <Simulador empleado={e} />

      <VacacionesCard empleado={e} />

      <div className="expediente-rejilla">
        <div className="tarjeta">
          <h2 className="seccion-titulo">Contratos</h2>
          <ul className="linea-tiempo">
            {e.contratos.map((k) => (
              <li key={k.id}><strong>{CONTRATOS[k.tipo]}</strong><small className="tenue">{fecha(k.inicio)} → {k.fin ? fecha(k.fin) : 'sin fecha de término'}</small>{k.notas && <small>{k.notas}</small>}</li>
            ))}
          </ul>
        </div>
        <div className="tarjeta">
          <h2 className="seccion-titulo">Historial de sueldo</h2>
          <ul className="linea-tiempo">
            {e.salarios.map((s) => <li key={s.id}><strong>{dinero(s.salario_mensual)}</strong><small className="tenue">Desde {fecha(s.desde)}{s.motivo ? ` · ${s.motivo}` : ''}</small></li>)}
          </ul>
        </div>
      </div>
      <div className="expediente-rejilla">
        <div className="tarjeta">
          <h2 className="seccion-titulo">Trayectoria</h2>
          {e.movimientos.length === 0 ? <p className="tenue">Sin movimientos registrados.</p> : (
            <ul className="linea-tiempo">
              {e.movimientos.map((m) => (
                <li key={m.id} className={`mov-${m.tipo}`}><strong>{MOVIMIENTOS[m.tipo]} · {fecha(m.fecha)}</strong><small className="tenue">{m.descripcion}{m.por ? ` · ${m.por}` : ''}</small></li>
              ))}
            </ul>
          )}
        </div>
        {puede('nomina.ver') && (
          <div className="tarjeta">
            <div className="seccion-cab"><h2 className="seccion-titulo">Recibos de nómina</h2>
              {e.recibos.length > 0 && <Link className="btn-link" to={`/nomina/recibos?empleado=${e.id}`}>Ver todos</Link>}</div>
            {e.recibos.length === 0 ? <p className="tenue">Aún no tiene nóminas autorizadas.</p> : (
              <ul className="lista-simple">
                {e.recibos.slice(0, 6).map((r) => (
                  <li key={r.id} className="fila-recibo"><Link to={`/nomina/recibos?empleado=${e.id}&recibo=${r.id}`}>Periodo {String(r.numero).padStart(2, '0')}/{r.ejercicio}</Link>
                    <small className="tenue">pago {fecha(r.fecha_pago)}{r.uuid ? ' · timbrado' : ''}</small><strong className="num">{dinero(r.neto)}</strong></li>
                ))}
              </ul>
            )}
          </div>
        )}
      </div>
      {e.notas && <div className="tarjeta"><h2 className="seccion-titulo">Notas</h2><p>{e.notas}</p></div>}

      {dialogo === 'salario' && <DialogoSalario e={e} onCerrar={() => setDialogo(null)} onListo={actualizado} />}
      {dialogo === 'contrato' && <DialogoContrato e={e} onCerrar={() => setDialogo(null)} onListo={actualizado} />}
      {dialogo === 'baja' && <DialogoBaja e={e} onCerrar={() => setDialogo(null)} onListo={actualizado} />}
    </section>
  );
}

function Kpi({ titulo, valor, nota }: { titulo: string; valor: ReactNode; nota?: string }) {
  return <div className="kpi"><span className="tenue">{titulo}</span><strong>{valor}</strong>{nota && <small className="tenue">{nota}</small>}</div>;
}
function Dato({ t, v, mono }: { t: string; v: ReactNode; mono?: boolean }) {
  return <><dt>{t}</dt><dd className={mono ? 'mono' : ''}>{v || <span className="tenue">—</span>}</dd></>;
}

interface PropsDialogo { e: Empleado; onCerrar: () => void; onListo: (e: Empleado, texto: string) => void }

function useEnvio(onListo: PropsDialogo['onListo']) {
  const [error, setError] = useState<string | null>(null);
  const [enviando, setEnviando] = useState(false);
  const enviar = async (fn: () => Promise<Empleado>, texto: string) => {
    setError(null); setEnviando(true);
    try { onListo(await fn(), texto); } catch (x) { setError(mensajeDeError(x)); } finally { setEnviando(false); }
  };
  return { error, enviando, enviar };
}

function DialogoSalario({ e, onCerrar, onListo }: PropsDialogo) {
  const [monto, setMonto] = useState(e.salario_mensual ?? 0);
  const [desde, setDesde] = useState(hoyISO());
  const [motivo, setMotivo] = useState('');
  const { error, enviando, enviar } = useEnvio(onListo);
  const cambio = e.salario_mensual ? ((monto - e.salario_mensual) / e.salario_mensual) * 100 : 0;
  return (
    <Modal titulo="Cambiar salario" abierto onCerrar={onCerrar} ancho="sm"
      pie={<><button className="btn btn-sec" onClick={onCerrar}>Cancelar</button>
        <button className="btn" disabled={enviando || monto <= 0 || monto === e.salario_mensual}
          onClick={() => void enviar(() => empleadosApi.salario(e.id, { salario_mensual: monto, desde, motivo: motivo || undefined }), 'Salario actualizado; el anterior queda en el historial.')}>Guardar</button></>}>
      {error && <Aviso tipo="error">{error}</Aviso>}
      <label>Nuevo sueldo mensual<input type="number" min={1} step="0.01" value={monto} onChange={(x) => setMonto(Number(x.target.value))} />
        <small className="tenue">Actual {dinero(e.salario_mensual)}{monto !== e.salario_mensual && e.salario_mensual ? ` · ${cambio > 0 ? '+' : ''}${numero(cambio, 1)} %` : ''}</small></label>
      <label>Aplica desde<input type="date" value={desde} onChange={(x) => setDesde(x.target.value)} /></label>
      <label>Motivo<input value={motivo} placeholder="Ej. cambio de puesto, ajuste anual" onChange={(x) => setMotivo(x.target.value)} /></label>
    </Modal>
  );
}

const SIGUIENTE: Record<TipoContrato, TipoContrato> = { capacitacion: 'prueba', prueba: 'temporal', temporal: 'indeterminado', indeterminado: 'indeterminado' };

function DialogoContrato({ e, onCerrar, onListo }: PropsDialogo) {
  const inicioSugerido = e.contrato_fin ? new Date(new Date(`${e.contrato_fin}T12:00:00`).getTime() + 86_400_000).toISOString().slice(0, 10) : hoyISO();
  const [tipo, setTipo] = useState<TipoContrato>(SIGUIENTE[e.tipo_contrato]);
  const [inicio, setInicio] = useState(inicioSugerido);
  const finSugerido = (t: TipoContrato, i: string) => {
    if (t === 'indeterminado') return '';
    const d = new Date(`${i}T12:00:00`);
    if (t === 'capacitacion') d.setDate(d.getDate() + 13); else { d.setMonth(d.getMonth() + 3); d.setDate(d.getDate() - 1); }
    return d.toISOString().slice(0, 10);
  };
  const [fin, setFin] = useState(finSugerido(tipo, inicio));
  const [notas, setNotas] = useState('');
  const { error, enviando, enviar } = useEnvio(onListo);
  return (
    <Modal titulo="Registrar nuevo contrato" abierto onCerrar={onCerrar} ancho="sm"
      pie={<><button className="btn btn-sec" onClick={onCerrar}>Cancelar</button>
        <button className="btn" disabled={enviando || !inicio || (tipo !== 'indeterminado' && !fin)}
          onClick={() => void enviar(() => empleadosApi.contrato(e.id, { tipo, inicio, fin: fin || null, notas: notas || undefined }), `Contrato ${CONTRATOS[tipo].toLowerCase()} registrado.`)}>Registrar</button></>}>
      {error && <Aviso tipo="error">{error}</Aviso>}
      <p className="tenue">Actual: {CONTRATOS[e.tipo_contrato]}{e.contrato_fin ? `, termina el ${fecha(e.contrato_fin)}` : ''}. El anterior se cierra un día antes del nuevo.</p>
      <label>Tipo<select value={tipo} onChange={(x) => { const t = x.target.value as TipoContrato; setTipo(t); setFin(finSugerido(t, inicio)); }}>
        {Object.entries(CONTRATOS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></label>
      <label>Inicia<input type="date" value={inicio} onChange={(x) => { setInicio(x.target.value); setFin(finSugerido(tipo, x.target.value)); }} /></label>
      {tipo !== 'indeterminado' && <label>Termina<input type="date" value={fin} onChange={(x) => setFin(x.target.value)} /></label>}
      <label>Notas<input value={notas} onChange={(x) => setNotas(x.target.value)} /></label>
    </Modal>
  );
}

function DialogoBaja({ e, onCerrar, onListo }: PropsDialogo) {
  const [f, setF] = useState(hoyISO());
  const [motivo, setMotivo] = useState('Renuncia voluntaria');
  const { error, enviando, enviar } = useEnvio(onListo);
  return (
    <Modal titulo={`Dar de baja a ${e.nombre}`} abierto onCerrar={onCerrar} ancho="sm"
      pie={<><button className="btn btn-sec" onClick={onCerrar}>Cancelar</button>
        <button className="btn btn-peligro" disabled={enviando || !motivo.trim()}
          onClick={() => void enviar(() => empleadosApi.baja(e.id, f, motivo), 'Empleado dado de baja. Su expediente se conserva.')}>Dar de baja</button></>}>
      {error && <Aviso tipo="error">{error}</Aviso>}
      <p>Deja de aparecer en las nóminas a partir de la fecha de baja. El expediente y su historial se conservan y se puede reactivar.</p>
      <label>Fecha de baja<input type="date" value={f} onChange={(x) => setF(x.target.value)} /></label>
      <label>Motivo<select value={motivo} onChange={(x) => setMotivo(x.target.value)}>
        {['Renuncia voluntaria', 'Término de contrato', 'Rescisión', 'Abandono de trabajo', 'Otro'].map((m) => <option key={m}>{m}</option>)}</select></label>
      <Aviso tipo="info">El cálculo del finiquito llegará en una versión posterior.</Aviso>
    </Modal>
  );
}
