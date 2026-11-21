import { useMemo, useRef, useState, type DragEvent } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { cfdiApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { Aviso } from '../../components/Aviso';
import type { EstadoImportacion, FilaImportacion, ResultadoImportacion } from '../../types';
import { dinero, fecha } from '../../utils/formato';

const CLASE: Record<EstadoImportacion, string> = {
  listo: 'etiqueta-info', ligado: 'etiqueta-ok', ya_ligado: 'etiqueta-neutro', diferencia: 'etiqueta-alerta', conflicto: 'etiqueta-mal',
  uuid_en_otro: 'etiqueta-mal', sin_recibo: 'etiqueta-alerta', emisor_distinto: 'etiqueta-mal', no_es_nomina: 'etiqueta-neutro',
  sin_timbre: 'etiqueta-mal', invalido: 'etiqueta-mal', repetido: 'etiqueta-neutro',
};
const ORDEN: EstadoImportacion[] = ['diferencia', 'conflicto', 'uuid_en_otro', 'sin_recibo', 'emisor_distinto', 'sin_timbre', 'invalido', 'no_es_nomina', 'repetido', 'listo', 'ligado', 'ya_ligado'];

/**
 * v14 — Importar los XML que timbró el departamento de nóminas y ligar cada folio fiscal (UUID) con su recibo autorizado.
 * Paso 1: analizar (no cambia nada). Paso 2: ligar los que coinciden y, si RH lo decide, los que traen diferencias.
 */
export function ImportarXmlPage() {
  const qc = useQueryClient();
  const entrada = useRef<HTMLInputElement>(null);
  const [archivos, setArchivos] = useState<File[]>([]);
  const [encima, setEncima] = useState(false);
  const [r, setR] = useState<ResultadoImportacion | null>(null);
  const [forzar, setForzar] = useState<string[]>([]);
  const [trabajando, setTrabajando] = useState<'analizar' | 'ligar' | null>(null);
  const [progreso, setProgreso] = useState(0);
  const [error, setError] = useState<string | null>(null);

  const agregar = (lista: FileList | null) => {
    const nuevos = Array.from(lista ?? []).filter((f) => /\.(xml|zip)$/i.test(f.name));
    setArchivos((a) => [...a, ...nuevos.filter((n) => !a.some((x) => x.name === n.name && x.size === n.size))].slice(0, 200));
    setR(null); setForzar([]);
  };
  const soltar = (e: DragEvent) => { e.preventDefault(); setEncima(false); agregar(e.dataTransfer.files); };
  const correr = async (aplicar: boolean) => {
    setTrabajando(aplicar ? 'ligar' : 'analizar'); setError(null); setProgreso(0);
    try {
      const x = await cfdiApi.importar(archivos, aplicar, forzar, setProgreso);
      setR(x);
      if (aplicar) { setForzar([]); void qc.invalidateQueries({ queryKey: ['captura'] }); void qc.invalidateQueries({ queryKey: ['historial'] }); void qc.invalidateQueries({ queryKey: ['recibo'] }); }
    } catch (e) { setError(mensajeDeError(e)); } finally { setTrabajando(null); }
  };

  const filas = useMemo(() => [...(r?.filas ?? [])].sort((a, b) => ORDEN.indexOf(a.estado) - ORDEN.indexOf(b.estado)), [r]);
  const listos = r?.conteo.listo ?? 0;
  const porLigar = listos + forzar.length;

  return (
    <section>
      <div className="encabezado-pagina">
        <div>
          <h1>Importar XML timbrados</h1>
          <p className="tenue">Cuando el departamento de nóminas timbra en su sistema, suba aquí sus XML (o un ZIP) para ligar cada folio fiscal con el recibo autorizado y comparar los importes.</p>
        </div>
      </div>

      <div className={`zona-archivo tarjeta ${encima ? 'encima' : ''} ${archivos.length ? 'con-archivo' : ''}`}
        onDragOver={(e) => { e.preventDefault(); setEncima(true); }} onDragLeave={() => setEncima(false)} onDrop={soltar}
        onClick={() => entrada.current?.click()} role="button" tabIndex={0} onKeyDown={(e) => e.key === 'Enter' && entrada.current?.click()}>
        <p className="zona-icono" aria-hidden>⇪</p>
        <span><strong>{archivos.length ? `${archivos.length} archivo(s) seleccionados` : 'Arrastre los XML o un ZIP, o haga clic para elegirlos'}</strong>
          <small className="tenue">CFDI de nómina 4.0 (también 3.3). Hasta 200 archivos por carga; un ZIP puede traer hasta 2,000 XML.</small></span>
        <input ref={entrada} type="file" multiple accept=".xml,.zip" hidden onChange={(e) => { agregar(e.target.files); e.target.value = ''; }} />
      </div>
      {archivos.length > 0 && (
        <div className="fila-botones importar-acciones">
          <button className="btn" disabled={!!trabajando} onClick={() => void correr(false)}>{trabajando === 'analizar' ? `Analizando… ${progreso < 100 ? progreso + ' %' : ''}` : '1. Analizar'}</button>
          {r && !r.aplicado && <button className="btn btn-autorizar" disabled={!!trabajando || porLigar === 0} onClick={() => void correr(true)}>
            {trabajando === 'ligar' ? 'Ligando…' : `2. Ligar ${porLigar} recibo(s)`}</button>}
          <button className="btn btn-sec" disabled={!!trabajando} onClick={() => { setArchivos([]); setR(null); setForzar([]); }}>Quitar archivos</button>
        </div>
      )}
      {error && <Aviso tipo="error">{error}</Aviso>}

      {r && <>
        <div className="kpis">
          {ORDEN.filter((k) => r.conteo[k]).map((k) => <div key={k} className="kpi"><span className="tenue">{r.estados[k]}</span><strong>{r.conteo[k]}</strong></div>)}
        </div>
        {r.aplicado
          ? <Aviso tipo="exito">Listo: {r.conteo.ligado ?? 0} recibo(s) ligados a su folio fiscal. Ya aparecen como timbrados en el historial y se puede descargar su XML.</Aviso>
          : <Aviso tipo="info">Análisis terminado; todavía no se cambió nada. Revise las filas en ámbar: un XML con importes distintos solo se liga si lo marca.</Aviso>}
        <div className="tabla-scroll">
          <table className="tabla tabla-importacion">
            <thead><tr><th>Estado</th><th>Trabajador (XML)</th><th>Pago</th><th className="num">Total XML</th><th>Recibo de la plataforma</th><th>Detalle</th></tr></thead>
            <tbody>{filas.map((f) => <Fila key={f.archivo} f={f} r={r} forzado={!!f.uuid && forzar.includes(f.uuid)}
              onForzar={(si) => f.uuid && setForzar((x) => si ? [...x, f.uuid!] : x.filter((u) => u !== f.uuid))} />)}</tbody>
          </table>
        </div>
      </>}
      <p className="tenue ayuda-captura">Cómo se empareja: RFC (o CURP) del trabajador + fechas del periodo del complemento de nómina; el aguinaldo (nómina extraordinaria) con la nómina especial de esa fecha.
        Solo se ligan recibos <strong>autorizados</strong>. Para quitar una liga use «Cancelar / desligar» en el recibo. <Link to="/nomina/configuracion?pestana=timbrado">Configuración del timbrado</Link></p>
    </section>
  );
}

function Fila({ f, r, forzado, onForzar }: { f: FilaImportacion; r: ResultadoImportacion; forzado: boolean; onForzar: (si: boolean) => void }) {
  return (
    <tr>
      <td><span className={`etiqueta ${CLASE[f.estado]}`}>{r.estados[f.estado]}</span></td>
      <td>{f.receptor ?? <span className="tenue">—</span>}<small className="tenue linea-sub mono">{f.rfc ?? ''}</small><small className="tenue linea-sub">{f.archivo}</small></td>
      <td>{f.fecha_pago ? fecha(f.fecha_pago) : '—'}{f.tipo_nomina && <small className="tenue linea-sub">{f.tipo_nomina === 'E' ? 'Extraordinaria' : 'Ordinaria'}</small>}</td>
      <td className="num">{f.total != null ? dinero(f.total) : '—'}</td>
      <td>{f.recibo ? <>{f.recibo.nombre}<small className="tenue linea-sub">{f.recibo.periodo} · neto {dinero(f.recibo.neto)}</small></> : <span className="tenue">—</span>}</td>
      <td>
        {f.mensaje}
        {f.diferencias.length > 0 && <ul className="diferencias">{f.diferencias.map((d) => (
          <li key={d.concepto}>{d.concepto}: recibo {dinero(d.recibo)} · XML {dinero(d.xml)} <strong className="texto-peligro">({d.diferencia > 0 ? '+' : ''}{dinero(d.diferencia)})</strong></li>
        ))}</ul>}
        {f.estado === 'diferencia' && !r.aplicado && <label className="check"><input type="checkbox" checked={forzado} onChange={(e) => onForzar(e.target.checked)} /> Ligar de todos modos (RH revisó la diferencia)</label>}
        {f.uuid && <small className="mono tenue linea-sub">{f.uuid}</small>}
      </td>
    </tr>
  );
}
