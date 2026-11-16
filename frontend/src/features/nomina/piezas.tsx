import { useQuery } from '@tanstack/react-query';
import { nominaApi, nominaPeriodoApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { Aviso } from '../../components/Aviso';
import { Modal } from '../../components/Modal';
import type { PeriodoNomina, Recibo } from '../../types';
import { dinero, fecha } from '../../utils/formato';
import { ReciboDetalle } from './ReciboDetalle';

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

const CFDI = { timbrado: 'Timbrado', pendiente: 'Pendiente de timbrar', desactivado: 'Sin timbrar (timbrado desactivado)', no_aplica: 'Pre-nómina (no se timbra)' };

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
        <dt>Salario diario / SDI</dt><dd>{dinero(r.salario_diario)} / {dinero(r.sdi)}</dd>
        <dt>Días pagados</dt><dd>{r.dias_pagados} de {r.periodo.dias}{r.faltas ? ` · ${r.faltas} falta(s)` : ''}{r.retardos ? ` · ${r.retardos} retardo(s)` : ''}{r.domingos ? ` · ${r.domingos} domingo(s)` : ''}</dd>
        <dt>Folio fiscal (UUID)</dt><dd>{r.cfdi.uuid ? <span className="mono">{r.cfdi.uuid}</span> : <span className="tenue">{CFDI[r.cfdi.estado]}</span>}</dd>
      </dl>
      {r.integridad === 'alterado' && <Aviso tipo="error">Este recibo cambió en la base de datos después de autorizarse (su huella no coincide). Avisa al administrador.</Aviso>}
      <ReciboDetalle calculo={r.calculo} tipo={r.periodo.tipo} />
    </article>
  );
}

export function ReciboModal({ id, onCerrar }: { id: number; onCerrar: () => void }) {
  const { data, isLoading, isError, error } = useQuery({ queryKey: ['recibo', id], queryFn: () => nominaPeriodoApi.recibo(id) });
  return (
    <Modal titulo="Recibo" abierto onCerrar={onCerrar} ancho="lg"
      pie={<><button className="btn btn-sec" onClick={() => window.print()}>Imprimir / PDF</button><button className="btn" onClick={onCerrar}>Cerrar</button></>}>
      {isLoading && <p>Cargando…</p>}
      {isError && <Aviso tipo="error">{mensajeDeError(error)}</Aviso>}
      {data && <SobreRecibo r={data} />}
    </Modal>
  );
}
