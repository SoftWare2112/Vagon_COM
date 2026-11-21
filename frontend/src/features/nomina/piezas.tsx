import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useAuth } from '../../auth/AuthContext';
import { nominaApi, nominaPeriodoApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { Aviso } from '../../components/Aviso';
import { Modal } from '../../components/Modal';
import type { PeriodoNomina, Recibo } from '../../types';
import { dinero, fecha } from '../../utils/formato';
import { ReciboDetalle } from './ReciboDetalle';
import { BloqueTimbre, DialogoCancelarCfdi, EtiquetaCfdi } from '../cfdi/piezasCfdi';
import { cfdiApi } from '../../api/endpoints';
import { useQueryClient } from '@tanstack/react-query';

const PASOS = [{ id: 'abierto', texto: 'Captura' }, { id: 'calculado', texto: 'Pre-nómina' }, { id: 'autorizado', texto: 'Autorizada' }] as const;

/** v12 — Los tres pasos del periodo, con el actual resaltado. */
export function EstadoPeriodo({ p, pendiente = false }: { p: PeriodoNomina; pendiente?: boolean }) {
  const actual = PASOS.findIndex((x) => x.id === p.estado);
  return (
    <ol className="pasos" aria-label="Estado del periodo">
      {PASOS.map((x, i) => (
        <li key={x.id} className={i < actual ? 'hecho' : i === actual ? 'actual' : ''} aria-current={i === actual ? 'step' : undefined}>
          <span className="pasos-num">{i < actual ? '✓' : i + 1}</span>{x.texto}
          {i === actual && pendiente && x.id !== 'autorizado' && <small className="tenue"> · cambios sin calcular</small>}
        </li>
      ))}
    </ol>
  );
}

export function EtiquetaEstado({ estado }: { estado: PeriodoNomina['estado'] }) {
  return estado === 'autorizado' ? <span className="etiqueta etiqueta-ok">Autorizado</span>
    : estado === 'calculado' ? <span className="etiqueta etiqueta-info">Pre-nómina</span> : <span className="etiqueta etiqueta-neutro">Abierto</span>;
}

const CFDI = { timbrado: 'Timbrado', simulado: 'Timbre simulado', pendiente: 'Pendiente de timbrar', desactivado: 'Sin timbrar (timbrado desactivado)', no_aplica: 'Pre-nómina (no se timbra)' };

/** v12 — Sobre-recibo: datos del trabajador y del periodo, percepciones, deducciones, neto y folio fiscal. Imprimible. */
export function SobreRecibo({ r }: { r: Recibo }) {
  const { data: empresa } = useQuery({ queryKey: ['empresa'], queryFn: nominaApi.empresa, staleTime: 300_000 });
  return (
    <article className="sobre-recibo">
      <header className="sobre-recibo-cab">
        <div>
          <strong>{empresa?.razon_social || empresa?.nombre || 'Vagón COM'}</strong>
          <small className="tenue linea-sub">{empresa?.rfc ? `RFC ${empresa.rfc}` : ''}{empresa?.registro_patronal ? ` · Reg. patronal ${empresa.registro_patronal}` : ''}</small>
        </div>
        <div className="sobre-recibo-periodo">
          <strong>Recibo de nómina · {r.periodo.etiqueta}</strong>
          <small className="tenue linea-sub">Fecha de pago {fecha(r.periodo.fecha_pago)} · {r.estado === 'autorizado' ? 'Autorizado' : 'Pre-nómina (borrador)'}</small>
        </div>
      </header>
      <dl className="datos datos-recibo">
        <dt>Empleado</dt><dd><strong>{r.nombre}</strong> <span className="mono tenue">{r.numero_empleado}</span></dd>
        <dt>Puesto</dt><dd>{r.puesto ?? '—'}</dd>
        <dt>RFC</dt><dd className="mono">{r.rfc ?? '—'}</dd>
        <dt>CURP</dt><dd className="mono">{r.curp ?? '—'}</dd>
        <dt>NSS</dt><dd className="mono">{r.nss ?? '—'}</dd>
        <dt>{r.calculo.tipo_calculo === 'aguinaldo' ? 'Salario diario' : 'Salario diario / SDI'}</dt><dd>{dinero(r.salario_diario)}{r.calculo.tipo_calculo === 'aguinaldo' ? '' : ` / ${dinero(r.sdi)}`}</dd>
        <dt>Días pagados</dt><dd>{r.calculo.tipo_calculo === 'aguinaldo' ? `${(r.calculo.dias_aguinaldo ?? 0).toFixed(2)} días de aguinaldo` : `${r.dias_pagados} de ${r.periodo.dias}`}{r.calculo.tipo_calculo !== 'aguinaldo' && r.faltas ? ` · ${r.faltas} falta(s)` : ''}{r.retardos ? ` · ${r.retardos} retardo(s)` : ''}{r.domingos ? ` · ${r.domingos} domingo(s)` : ''}</dd>
        <dt>Folio fiscal (UUID)</dt><dd>{r.cfdi.uuid ? <><span className="mono">{r.cfdi.uuid}</span> <EtiquetaCfdi modo={r.cfdi.modo} /></> : <span className="tenue">{CFDI[r.cfdi.estado]}</span>}</dd>
      </dl>
      <BloqueTimbre r={r} />
      {r.integridad === 'alterado' && <Aviso tipo="error">Este recibo cambió en la base de datos después de autorizarse (su huella no coincide). Avisa al administrador.</Aviso>}
      <ReciboDetalle calculo={r.calculo} tipo={r.periodo.tipo} />
    </article>
  );
}

export function ReciboModal({ id, onCerrar }: { id: number; onCerrar: () => void }) {
  const { data, isLoading, isError, error } = useQuery({ queryKey: ['recibo', id], queryFn: () => nominaPeriodoApi.recibo(id) });
  return (
    <Modal titulo="Recibo" abierto onCerrar={onCerrar} ancho="lg"
      pie={<>{data && <AccionesRecibo r={data} />}<button className="btn" onClick={onCerrar}>Cerrar</button></>}>
      {isLoading && <p>Cargando…</p>}
      {isError && <Aviso tipo="error">{mensajeDeError(error)}</Aviso>}
      {data && <SobreRecibo r={data} />}
    </Modal>
  );
}

/** v13: descargar el recibo en PDF (formato oficial con importe con letra) y enviarlo por correo al empleado. v14: XML, timbrar y cancelar. */
export function AccionesRecibo({ r }: { r: Recibo }) {
  const { puede } = useAuth();
  const qc = useQueryClient();
  const [estado, setEstado] = useState<{ tipo: 'exito' | 'error'; texto: string } | null>(null);
  const [cancelando, setCancelando] = useState(false);
  const [timbrando, setTimbrando] = useState(false);
  const refrescar = () => { void qc.invalidateQueries({ queryKey: ['recibo', r.id] }); void qc.invalidateQueries({ queryKey: ['captura'] }); void qc.invalidateQueries({ queryKey: ['historial'] }); };
  const timbrar = async () => {
    setTimbrando(true);
    try { const x = await cfdiApi.timbrarRecibo(r.id); setEstado({ tipo: 'exito', texto: `Timbrado: ${x.uuid}` }); refrescar(); }
    catch (e) { setEstado({ tipo: 'error', texto: mensajeDeError(e) }); } finally { setTimbrando(false); }
  };
  const nombre = `recibo-${r.numero_empleado}-${r.periodo.ejercicio}-${r.periodo.tipo === 'especial' ? 'aguinaldo' : String(r.periodo.numero).padStart(2, '0')}.pdf`;
  const enviar = async () => {
    try {
      const x = await nominaPeriodoApi.enviarRecibo(r.id);
      setEstado(x.estado === 'enviado' ? { tipo: 'exito', texto: `Enviado a ${x.email}` }
        : { tipo: 'error', texto: x.estado === 'sin_correo' ? 'El empleado no tiene correo en su expediente.' : (x.error ?? 'No se pudo enviar.') });
    } catch (e) { setEstado({ tipo: 'error', texto: mensajeDeError(e) }); }
  };
  return (
    <>
      {estado && <small className={estado.tipo === 'exito' ? 'texto-ok' : 'texto-peligro'}>{estado.texto}</small>}
      <button className="btn btn-sec" onClick={() => void nominaPeriodoApi.pdf(r.id, nombre).catch((e) => setEstado({ tipo: 'error', texto: mensajeDeError(e) }))}>Descargar PDF</button>
      {r.estado === 'autorizado' && puede('nomina.autorizar') && <button className="btn btn-sec" onClick={() => void enviar()}>Enviar por correo</button>}
      {r.cfdi.xml && <button className="btn btn-sec" onClick={() => void cfdiApi.xml(r.id, `${r.numero_empleado}-${r.periodo.ejercicio}-${r.cfdi.uuid}.xml`).catch((e) => setEstado({ tipo: 'error', texto: mensajeDeError(e) }))}>Descargar XML</button>}
      {r.cfdi.estado === 'pendiente' && puede('nomina.timbrar') && <button className="btn btn-sec" disabled={timbrando} onClick={() => void timbrar()}>{timbrando ? 'Timbrando…' : 'Timbrar'}</button>}
      {r.cfdi.uuid && puede('nomina.timbrar') && <button className="btn btn-sec" onClick={() => setCancelando(true)}>{r.cfdi.modo === 'importado' ? 'Desligar XML…' : 'Cancelar CFDI…'}</button>}
      {cancelando && <DialogoCancelarCfdi r={r} onCerrar={() => setCancelando(false)} onListo={(texto) => { setCancelando(false); setEstado({ tipo: 'exito', texto }); refrescar(); }} />}
    </>
  );
}
