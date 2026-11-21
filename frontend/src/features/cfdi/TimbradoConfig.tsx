import { useEffect, useState, type FormEvent } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { cfdiApi } from '../../api/endpoints';
import { erroresDeCampo, mensajeDeError } from '../../api/http';
import { Aviso } from '../../components/Aviso';
import type { EstadoTimbrado } from '../../types';
import { fecha } from '../../utils/formato';

type Datos = { clave_ent_fed: string; riesgo_puesto: string; tipo_jornada: string; serie_cfdi: string; curp: string;
  pac: 'simulado' | 'sw'; pac_modo: 'pruebas' | 'produccion'; pac_usuario: string; pac_password: string };

const desde = (e: EstadoTimbrado): Datos => ({
  clave_ent_fed: e.empresa.clave_ent_fed ?? '', riesgo_puesto: e.empresa.riesgo_puesto ?? '1', tipo_jornada: e.empresa.tipo_jornada ?? '01',
  serie_cfdi: e.empresa.serie_cfdi ?? 'N', curp: e.empresa.curp ?? '', pac: e.pac.proveedor, pac_modo: e.pac.modo, pac_usuario: e.pac.usuario ?? '', pac_password: '',
});

/**
 * v14 — Pestaña «Timbrado (CFDI)» de Configuración de nómina.
 * El timbrado es OPCIONAL: RH decide si la plataforma timbra, o si el departamento de nóminas timbra en su sistema y aquí solo se importan sus XML.
 */
export function TimbradoConfig({ edita }: { edita: boolean }) {
  const qc = useQueryClient();
  const { data: e, isLoading, isError, error } = useQuery({ queryKey: ['cfdi-config'], queryFn: cfdiApi.configuracion });
  const [d, setD] = useState<Datos | null>(null);
  const [errores, setErrores] = useState<Record<string, string>>({});
  const [aviso, setAviso] = useState<{ tipo: 'exito' | 'error' | 'info' | 'advertencia'; texto: string } | null>(null);
  const [csd, setCsd] = useState<{ cer: File | null; key: File | null; password: string }>({ cer: null, key: null, password: '' });
  const [ocupado, setOcupado] = useState<string | null>(null);
  useEffect(() => { if (e) setD(desde(e)); }, [e]);

  if (isError) return <Aviso tipo="error">{mensajeDeError(error)}</Aviso>;
  if (isLoading || !e || !d) return <p>Cargando…</p>;
  const set = (c: Partial<Datos>) => setD({ ...d, ...c });
  const err = (c: string) => errores[c] && <small className="texto-peligro">{errores[c]}</small>;
  const listo = (x: EstadoTimbrado, texto: string, tipo: 'exito' | 'info' = 'exito') => { qc.setQueryData(['cfdi-config'], x); void qc.invalidateQueries({ queryKey: ['empresa'] }); setAviso({ tipo, texto }); };
  const tarea = async (nombre: string, f: () => Promise<void>) => {
    setOcupado(nombre); setErrores({}); setAviso(null);
    try { await f(); } catch (x) { setErrores(erroresDeCampo(x)); setAviso({ tipo: 'error', texto: mensajeDeError(x) }); } finally { setOcupado(null); }
  };

  const guardar = (ev: FormEvent) => { ev.preventDefault(); void tarea('guardar', async () => listo(await cfdiApi.guardar({ ...d, curp: d.curp || null }), 'Configuración del timbrado guardada.')); };
  const activar = (activo: boolean) => void tarea('activar', async () => listo(await cfdiApi.guardar({ timbrado_activo: activo }),
    activo ? 'Timbrado activado: las nóminas autorizadas se pueden timbrar desde «Nómina del periodo».' : 'Timbrado desactivado. Los recibos se siguen autorizando sin folio fiscal.', 'info'));
  const subirCsd = () => void tarea('csd', async () => {
    if (!csd.cer || !csd.key) throw new Error('Seleccione el archivo .cer y el archivo .key.');
    const r = await cfdiApi.cargarCsd(csd.cer, csd.key, csd.password);
    setCsd({ cer: null, key: null, password: '' });
    listo(r.estado, 'CSD cargado y verificado.');
  });
  const probar = () => void tarea('probar', async () => { const r = await cfdiApi.probar(); setAviso({ tipo: r.ok ? 'exito' : 'error', texto: r.mensaje }); });

  const personaFisica = (e.empresa.rfc ?? '').length === 13;
  const cambioPac = d.pac !== e.pac.proveedor || d.pac_modo !== e.pac.modo || d.pac_usuario !== (e.pac.usuario ?? '') || d.pac_password !== '';

  return (
    <div className="timbrado">
      {aviso && <Aviso tipo={aviso.tipo}>{aviso.texto}</Aviso>}

      <section className="tarjeta seccion-form">
        <div className="timbrado-cab">
          <div>
            <h2 className="seccion-titulo">Timbrado del recibo (CFDI 4.0 + Complemento de Nómina 1.2)</h2>
            <p className="tenue">Es opcional. Si el departamento de nóminas timbra en su propio sistema, déjelo desactivado e importe sus XML en <Link to="/nomina/importar-xml">Importar XML timbrados</Link> para ligar cada folio fiscal con su recibo.</p>
          </div>
          <span className={`etiqueta ${e.timbrado_activo ? 'etiqueta-ok' : 'etiqueta-neutro'}`}>{e.timbrado_activo ? 'Activo' : 'Desactivado'}</span>
        </div>
        <ul className="revision">
          {e.revision.map((r) => <li key={r.clave} className={r.ok ? 'ok' : 'falta'}><span aria-hidden>{r.ok ? '✓' : '○'}</span> {r.texto}</li>)}
        </ul>
        <p className="tenue">RFC, razón social, régimen fiscal, código postal y registro patronal se capturan en la pestaña <Link to="?pestana=empresa">Empresa</Link>,
          tal como aparecen en la Constancia de Situación Fiscal (la razón social sin «S.A. de C.V.»).</p>
        {edita && <div className="fila-botones">
          {e.timbrado_activo
            ? <button className="btn btn-sec" disabled={!!ocupado} onClick={() => activar(false)}>Desactivar timbrado</button>
            : <button className="btn" disabled={!!ocupado || !e.listo} onClick={() => activar(true)} title={e.listo ? '' : 'Complete la lista de arriba'}>Activar timbrado</button>}
          <span className="tenue">{Object.entries(e.resumen.timbrados).map(([m, n]) => `${n} ${m}`).join(' · ') || 'Sin recibos timbrados'} · {e.resumen.pendientes} autorizado(s) sin timbrar</span>
        </div>}
      </section>

      <form className="tarjeta seccion-form" onSubmit={guardar}>
        <fieldset disabled={!edita}>
          <h2 className="seccion-titulo">Datos del complemento de nómina</h2>
          <div className="rejilla-form">
            <label>Entidad donde se presta el servicio<select value={d.clave_ent_fed} onChange={(x) => set({ clave_ent_fed: x.target.value })}>
              <option value="">— Elegir —</option>{Object.entries(e.catalogos.estados).map(([k, v]) => <option key={k} value={k}>{v} ({k})</option>)}</select>{err('clave_ent_fed')}</label>
            <label>Clase de riesgo de trabajo (IMSS)<select value={d.riesgo_puesto} onChange={(x) => set({ riesgo_puesto: x.target.value })}>
              {Object.entries(e.catalogos.riesgos).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select>
              <small className="tenue">La de la tarjeta de identificación patronal o de la última determinación de la prima de riesgo.</small></label>
            <label>Tipo de jornada<select value={d.tipo_jornada} onChange={(x) => set({ tipo_jornada: x.target.value })}>
              {Object.entries(e.catalogos.jornadas).map(([k, v]) => <option key={k} value={k}>{k} · {v}</option>)}</select></label>
            <label>Serie de los recibos<input className="mono" maxLength={10} value={d.serie_cfdi} onChange={(x) => set({ serie_cfdi: x.target.value.toUpperCase() })} />
              <small className="tenue">El folio es el número interno del recibo.</small>{err('serie_cfdi')}</label>
            {personaFisica && <label>CURP del patrón (persona física)<input className="mono" maxLength={18} value={d.curp} onChange={(x) => set({ curp: x.target.value.toUpperCase() })} />{err('curp')}</label>}
          </div>

          <h2 className="seccion-titulo">Proveedor de timbrado (PAC)</h2>
          <div className="opciones-pac" role="radiogroup">
            <label className={`opcion ${d.pac === 'simulado' ? 'elegida' : ''}`}><input type="radio" checked={d.pac === 'simulado'} onChange={() => set({ pac: 'simulado', pac_modo: 'pruebas' })} />
              <span><strong>PAC simulado</strong><small className="tenue linea-sub">Para capacitación y demostraciones: arma, sella y «timbra» dentro de la plataforma. Los recibos dicen SIN VALIDEZ FISCAL.</small></span></label>
            <label className={`opcion ${d.pac === 'sw' ? 'elegida' : ''}`}><input type="radio" checked={d.pac === 'sw'} onChange={() => set({ pac: 'sw' })} />
              <span><strong>SW Sapien</strong><small className="tenue linea-sub">PAC autorizado por el SAT, con API REST. Requiere una cuenta (la de pruebas es gratuita).</small></span></label>
          </div>
          {d.pac === 'sw' && <div className="rejilla-form">
            <label>Ambiente<select value={d.pac_modo} onChange={(x) => set({ pac_modo: x.target.value as Datos['pac_modo'] })}>
              <option value="pruebas">Pruebas (sin validez fiscal)</option><option value="produccion">Producción (timbra ante el SAT)</option></select></label>
            <label>Usuario del PAC<input autoComplete="off" value={d.pac_usuario} onChange={(x) => set({ pac_usuario: x.target.value })} />{err('pac_usuario')}</label>
            <label>Contraseña del PAC<input type="password" autoComplete="new-password" placeholder={e.pac.tiene_password ? '•••••• (guardada)' : ''} value={d.pac_password} onChange={(x) => set({ pac_password: x.target.value })} />
              <small className="tenue">Se guarda cifrada. Déjela vacía para conservar la actual.</small></label>
          </div>}
          {d.pac === 'sw' && d.pac_modo === 'produccion' && <Aviso tipo="advertencia">En producción cada recibo timbrado es un CFDI real reportado al SAT y consume folios contratados. Úselo solo cuando RH lo haya decidido.</Aviso>}
        </fieldset>
        {edita && <div className="editor-pub-pie">
          <button className="btn btn-sec" type="button" disabled={!!ocupado || cambioPac} onClick={probar} title={cambioPac ? 'Guarde primero los cambios del PAC' : ''}>{ocupado === 'probar' ? 'Probando…' : 'Probar conexión'}</button>
          <button className="btn" type="submit" disabled={!!ocupado}>{ocupado === 'guardar' ? 'Guardando…' : 'Guardar'}</button>
        </div>}
      </form>

      <section className="tarjeta seccion-form">
        <h2 className="seccion-titulo">Certificado de sello digital (CSD)</h2>
        {e.csd
          ? <dl className="datos">
            <dt>Certificado</dt><dd className="mono">{e.csd.no_certificado}</dd>
            <dt>RFC</dt><dd className="mono">{e.csd.rfc}</dd>
            <dt>Vigencia</dt><dd>{fecha(e.csd.vigente_desde)} al {fecha(e.csd.vigente_hasta)}{' '}
              {e.csd.vigente ? (e.csd.dias_restantes != null && e.csd.dias_restantes < 60 ? <span className="etiqueta etiqueta-alerta">Vence en {e.csd.dias_restantes} días</span> : <span className="etiqueta etiqueta-ok">Vigente</span>)
                : <span className="etiqueta etiqueta-mal">Vencido</span>}</dd>
          </dl>
          : <p className="tenue">Aún no se ha cargado. El CSD es distinto de la e.firma: se tramita en el portal del SAT con el programa Certifica y se compone de un archivo .cer, un .key y su contraseña.</p>}
        {edita && <div className="rejilla-form">
          <label>Archivo .cer<input type="file" accept=".cer" onChange={(x) => setCsd({ ...csd, cer: x.target.files?.[0] ?? null })} />{err('cer')}</label>
          <label>Archivo .key<input type="file" accept=".key" onChange={(x) => setCsd({ ...csd, key: x.target.files?.[0] ?? null })} />{err('key')}</label>
          <label>Contraseña de la llave privada<input type="password" autoComplete="new-password" value={csd.password} onChange={(x) => setCsd({ ...csd, password: x.target.value })} />{err('password')}</label>
        </div>}
        {edita && <div className="fila-botones">
          <button className="btn btn-sec" disabled={!!ocupado || !csd.cer || !csd.key || !csd.password} onClick={subirCsd}>{ocupado === 'csd' ? 'Verificando…' : e.csd ? 'Reemplazar CSD' : 'Cargar CSD'}</button>
          <small className="tenue">Se revisa que la llave corresponda al certificado, que esté vigente, que no sea la e.firma y que sea del RFC de la empresa.</small>
        </div>}
      </section>

      <Bitacora />
    </div>
  );
}

function Bitacora() {
  const { data = [] } = useQuery({ queryKey: ['cfdi-eventos'], queryFn: () => cfdiApi.eventos() });
  if (data.length === 0) return null;
  const TIPOS = { timbrado: 'Timbrado', error: 'Error', cancelacion: 'Cancelación', importacion: 'Importación', csd: 'CSD', prueba: 'Prueba de conexión' } as const;
  return (
    <details className="tarjeta seccion-form">
      <summary><strong>Bitácora del timbrado</strong> <span className="tenue">({data.length} movimientos recientes)</span></summary>
      <div className="tabla-scroll">
        <table className="tabla">
          <thead><tr><th>Fecha</th><th>Movimiento</th><th>Recibo</th><th>Detalle</th><th>Usuario</th></tr></thead>
          <tbody>{data.map((ev) => (
            <tr key={ev.id}>
              <td className="nowrap">{ev.fecha}</td>
              <td><span className={`etiqueta ${ev.tipo === 'error' ? 'etiqueta-mal' : ev.tipo === 'timbrado' || ev.tipo === 'importacion' ? 'etiqueta-ok' : 'etiqueta-neutro'}`}>{TIPOS[ev.tipo]}</span></td>
              <td>{ev.recibo ? `${ev.recibo.nombre}` : '—'}</td>
              <td>{ev.mensaje}{ev.uuid && <small className="mono tenue linea-sub">{ev.uuid}</small>}</td>
              <td>{ev.usuario ?? '—'}</td>
            </tr>
          ))}</tbody>
        </table>
      </div>
    </details>
  );
}
