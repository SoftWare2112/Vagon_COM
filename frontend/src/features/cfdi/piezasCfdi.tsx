import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { cfdiApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { Aviso } from '../../components/Aviso';
import { Modal } from '../../components/Modal';
import type { ModoCfdi, PeriodoNomina, Recibo, ResultadoTimbradoPeriodo } from '../../types';
import { dinero } from '../../utils/formato';

export const MODOS_CFDI: Record<ModoCfdi, string> = {
  simulado: 'Simulado (sin validez fiscal)', pruebas: 'PAC en pruebas (sin validez fiscal)', produccion: 'Timbrado ante el SAT', importado: 'XML de nóminas',
};

/** v14 — Etiqueta del estado fiscal del recibo. Lo que no tiene validez fiscal se marca en ámbar. */
export function EtiquetaCfdi({ modo }: { modo?: ModoCfdi | null }) {
  if (!modo) return null;
  const clase = modo === 'produccion' || modo === 'importado' ? 'etiqueta-ok' : 'etiqueta-alerta';
  const texto = { simulado: 'Simulado', pruebas: 'Pruebas', produccion: 'CFDI', importado: 'CFDI (nóminas)' }[modo];
  return <span className={`etiqueta ${clase}`} title={MODOS_CFDI[modo]}>{texto}</span>;
}

/** Datos del timbre dentro del sobre-recibo. */
export function BloqueTimbre({ r }: { r: Recibo }) {
  const t = r.cfdi.timbre;
  if (!r.cfdi.uuid) return null;
  return (
    <div className={`bloque-timbre ${t && (t.simulado || t.pruebas) ? 'bloque-timbre-prueba' : ''}`}>
      {t?.simulado && <strong className="texto-alerta">Timbre SIMULADO — no tiene validez fiscal ni existe en el SAT.</strong>}
      {t?.pruebas && <strong className="texto-alerta">Timbrado en el ambiente de PRUEBAS del PAC — sin validez fiscal.</strong>}
      <dl className="datos datos-timbre">
        <dt>Folio fiscal</dt><dd className="mono">{r.cfdi.uuid}</dd>
        <dt>Certificación</dt><dd>{t ? t.fecha_timbrado.replace('T', ' ') : r.cfdi.timbrado_at} {r.cfdi.modo && <EtiquetaCfdi modo={r.cfdi.modo} />}</dd>
        {t && <><dt>Proveedor (PAC)</dt><dd className="mono">{t.rfc_prov_certif}</dd></>}
        {r.cfdi.total != null && Math.abs(r.cfdi.total - r.neto) > 0.009 && <><dt>Total del XML</dt><dd className="texto-peligro">{dinero(r.cfdi.total)} (el recibo dice {dinero(r.neto)})</dd></>}
      </dl>
      {t && !t.simulado && r.cfdi.modo === 'produccion' && <a href={t.url_verificacion} target="_blank" rel="noreferrer">Verificar en el portal del SAT ↗</a>}
    </div>
  );
}

/** Timbra los recibos del periodo autorizado y muestra el resultado de cada uno. */
export function DialogoTimbrarPeriodo({ periodo, onCerrar, onListo }: { periodo: PeriodoNomina; onCerrar: () => void; onListo: () => void }) {
  const { data: conf } = useQuery({ queryKey: ['cfdi-config'], queryFn: cfdiApi.configuracion });
  const [trabajando, setTrabajando] = useState(false);
  const [r, setR] = useState<ResultadoTimbradoPeriodo | null>(null);
  const [error, setError] = useState<string | null>(null);
  const timbrar = async () => {
    setTrabajando(true); setError(null);
    try { setR(await cfdiApi.timbrarPeriodo(periodo.id)); onListo(); } catch (e) { setError(mensajeDeError(e)); } finally { setTrabajando(false); }
  };
  const modo = conf?.pac.modo_timbre;
  return (
    <Modal titulo={`Timbrar ${periodo.etiqueta}`} abierto onCerrar={onCerrar} ancho="md"
      pie={<><button className="btn btn-sec" onClick={onCerrar}>{r ? 'Cerrar' : 'Cancelar'}</button>
        {!r && <button className="btn" disabled={trabajando || !conf?.listo} onClick={() => void timbrar()}>{trabajando ? 'Timbrando…' : 'Timbrar recibos'}</button>}</>}>
      {error && <Aviso tipo="error">{error}</Aviso>}
      {!r && conf && <>
        <p>Se arma el CFDI 4.0 con Complemento de Nómina 1.2 de cada recibo <strong>autorizado y sin timbrar</strong>, se sella con el CSD de la empresa
          (certificado {conf.csd?.no_certificado ?? '—'}) y se envía a <strong>{conf.pac.nombre}</strong>.</p>
        {modo === 'simulado' && <Aviso tipo="advertencia">Proveedor simulado: sirve para probar el flujo; los recibos quedan marcados «SIN VALIDEZ FISCAL».</Aviso>}
        {modo === 'pruebas' && <Aviso tipo="advertencia">Ambiente de pruebas del PAC: el timbre no tiene validez fiscal.</Aviso>}
        {modo === 'produccion' && <Aviso tipo="info">Producción: cada timbre consume un folio contratado y se reporta al SAT. Un error se corrige cancelando el CFDI.</Aviso>}
        {!conf.listo && <Aviso tipo="error">La configuración del timbrado está incompleta (Configuración de nómina › Timbrado).</Aviso>}
      </>}
      {r && <>
        <Aviso tipo={r.errores ? 'advertencia' : 'exito'}>{r.timbrados} timbrado(s){r.errores ? ` · ${r.errores} con error` : ''}.</Aviso>
        {r.resultados.length === 0 && <p className="tenue">No había recibos pendientes de timbrar.</p>}
        <ul className="lista-resultados">
          {r.resultados.map((x) => (
            <li key={x.recibo_id} className={x.ok ? '' : 'texto-peligro'}>
              <strong>{x.nombre}</strong> — {x.ok ? <span className="mono">{x.uuid}</span> : <>{x.errores.length > 1 ? <ul>{x.errores.map((m) => <li key={m}>{m}</li>)}</ul> : x.error}</>}
            </li>
          ))}
        </ul>
      </>}
    </Modal>
  );
}

/** Cancelación del CFDI de un recibo (o desligar el XML importado). */
export function DialogoCancelarCfdi({ r, onCerrar, onListo }: { r: Recibo; onCerrar: () => void; onListo: (texto: string) => void }) {
  const { data: conf } = useQuery({ queryKey: ['cfdi-config'], queryFn: cfdiApi.configuracion });
  const [motivo, setMotivo] = useState('02');
  const [folio, setFolio] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [trabajando, setTrabajando] = useState(false);
  const importado = r.cfdi.modo === 'importado';
  const cancelar = async () => {
    setTrabajando(true); setError(null);
    try { const x = await cfdiApi.cancelar(r.id, motivo, motivo === '01' ? folio.trim() : undefined); onListo(x.mensaje); }
    catch (e) { setError(mensajeDeError(e)); } finally { setTrabajando(false); }
  };
  return (
    <Modal titulo={importado ? 'Desligar el XML del recibo' : 'Cancelar el CFDI'} abierto onCerrar={onCerrar} ancho="sm"
      pie={<><button className="btn btn-sec" onClick={onCerrar}>Volver</button>
        <button className="btn btn-peligro" disabled={trabajando || (motivo === '01' && folio.trim().length !== 36)} onClick={() => void cancelar()}>
          {trabajando ? 'Procesando…' : importado ? 'Desligar' : 'Cancelar CFDI'}</button></>}>
      {error && <Aviso tipo="error">{error}</Aviso>}
      <p>Folio fiscal <span className="mono">{r.cfdi.uuid}</span> · {r.nombre}</p>
      {importado
        ? <p className="tenue">Este XML lo timbró el departamento de nóminas en su sistema: aquí solo se quita la liga con el recibo. La cancelación ante el SAT se hace en ese sistema.</p>
        : <>
          <label>Motivo (catálogo del SAT)<select value={motivo} onChange={(e) => setMotivo(e.target.value)}>
            {Object.entries(conf?.catalogos.motivos_cancelacion ?? { '02': 'Comprobante emitido con errores sin relación' }).map(([k, v]) => <option key={k} value={k}>{k} · {v}</option>)}
          </select></label>
          {motivo === '01' && <label>UUID del CFDI que lo sustituye<input className="mono" maxLength={36} value={folio} onChange={(e) => setFolio(e.target.value.toUpperCase())} /></label>}
          <p className="tenue">{r.cfdi.modo === 'simulado' ? 'Timbre simulado: se anula aquí mismo.' : 'Se envía la solicitud al PAC con el CSD de la empresa. El recibo queda «pendiente de timbrar» y se puede volver a timbrar.'}</p>
        </>}
    </Modal>
  );
}
