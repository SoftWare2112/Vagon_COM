import { Fragment, useEffect, useState, type FormEvent } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useSearchParams } from 'react-router-dom';
import { nominaApi } from '../../api/endpoints';
import { erroresDeCampo, mensajeDeError } from '../../api/http';
import { useAuth } from '../../auth/AuthContext';
import { Aviso } from '../../components/Aviso';
import { Modal } from '../../components/Modal';
import type { ConceptoNomina, Empresa, Periodicidad, TablaFiscal } from '../../types';
import { dinero, fecha, hoyISO, numero, PERIODICIDADES } from '../../utils/formato';
import { TimbradoConfig } from '../cfdi/TimbradoConfig';

type Pestana = 'empresa' | 'conceptos' | 'tablas' | 'periodos' | 'timbrado';
const PESTANAS: { id: Pestana; texto: string }[] = [
  { id: 'empresa', texto: 'Empresa' }, { id: 'conceptos', texto: 'Percepciones y deducciones' },
  { id: 'tablas', texto: 'Tablas fiscales' }, { id: 'periodos', texto: 'Calendario de pagos' },
  { id: 'timbrado', texto: 'Timbrado (CFDI)' },   // v14: solo con el permiso «nomina.timbrar»
];

/** v11 — Configuración de nómina: datos patronales, conceptos con sus reglas, tablas del SAT/IMSS y calendario de periodos. */
export function ConfiguracionPage() {
  const [params, setParams] = useSearchParams();
  const { puede } = useAuth();
  const visibles = PESTANAS.filter((p) => p.id !== 'timbrado' || puede('nomina.timbrar'));
  const pestana = (visibles.find((p) => p.id === params.get('pestana'))?.id ?? 'empresa') as Pestana;
  const edita = puede('nomina.configurar');
  return (
    <section>
      <div className="encabezado-pagina">
        <div>
          <h1>Configuración de nómina</h1>
          <p className="tenue">Las reglas con las que se calcula cada recibo. {edita ? '' : 'Solo lectura: pide el permiso «Configurar nómina» para cambiarlas.'}</p>
        </div>
      </div>
      <div className="segmentado pestanas-config" role="tablist">
        {visibles.map((p) => (
          <button key={p.id} role="tab" aria-selected={pestana === p.id} className={pestana === p.id ? 'activo' : ''} onClick={() => setParams({ pestana: p.id }, { replace: true })}>{p.texto}</button>
        ))}
      </div>
      {pestana === 'empresa' && <PestanaEmpresa edita={edita} />}
      {pestana === 'conceptos' && <PestanaConceptos edita={edita} />}
      {pestana === 'tablas' && <PestanaTablas edita={edita} />}
      {pestana === 'periodos' && <PestanaPeriodos edita={edita} />}
      {pestana === 'timbrado' && <TimbradoConfig edita={puede('nomina.timbrar')} />}
    </section>
  );
}

// ───────────── Empresa ─────────────
function PestanaEmpresa({ edita }: { edita: boolean }) {
  const qc = useQueryClient();
  const { data, isLoading } = useQuery({ queryKey: ['empresa'], queryFn: nominaApi.empresa });
  const [d, setD] = useState<Empresa | null>(null);
  const [errores, setErrores] = useState<Record<string, string>>({});
  const [aviso, setAviso] = useState<{ tipo: 'exito' | 'error'; texto: string } | null>(null);
  useEffect(() => { if (data) setD(data); }, [data]);
  if (isLoading || !d) return <p>Cargando…</p>;
  const set = (c: Partial<Empresa>) => setD({ ...d, ...c });
  const err = (c: string) => errores[c] && <small className="texto-peligro">{errores[c]}</small>;
  const guardar = async (e: FormEvent) => {
    e.preventDefault(); setErrores({}); setAviso(null);
    try { qc.setQueryData(['empresa'], await nominaApi.guardarEmpresa(d)); setAviso({ tipo: 'exito', texto: 'Datos de la empresa guardados.' }); }
    catch (x) { setErrores(erroresDeCampo(x)); setAviso({ tipo: 'error', texto: mensajeDeError(x) }); }
  };
  return (
    <form className="tarjeta seccion-form" onSubmit={(e) => void guardar(e)}>
      {aviso && <Aviso tipo={aviso.tipo}>{aviso.texto}</Aviso>}
      <fieldset disabled={!edita}>
        <h2 className="seccion-titulo">Datos patronales</h2>
        <div className="rejilla-form">
          <label>Nombre comercial<input value={d.nombre} onChange={(e) => set({ nombre: e.target.value })} />{err('nombre')}</label>
          <label>Razón social<input value={d.razon_social ?? ''} onChange={(e) => set({ razon_social: e.target.value })} /></label>
          <label>RFC de la empresa<input className="mono" maxLength={13} value={d.rfc ?? ''} onChange={(e) => set({ rfc: e.target.value.toUpperCase() })} />{err('rfc')}</label>
          <label>Régimen fiscal (SAT)<input inputMode="numeric" maxLength={3} placeholder="601" value={d.regimen_fiscal ?? ''} onChange={(e) => set({ regimen_fiscal: e.target.value })} />{err('regimen_fiscal')}</label>
          <label>Registro patronal IMSS<input className="mono" maxLength={11} value={d.registro_patronal ?? ''} onChange={(e) => set({ registro_patronal: e.target.value.toUpperCase() })} />{err('registro_patronal')}</label>
          <label>C.P. del lugar de expedición<input inputMode="numeric" maxLength={5} value={d.codigo_postal ?? ''} onChange={(e) => set({ codigo_postal: e.target.value })} />{err('codigo_postal')}</label>
        </div>
        <p className="tenue">Se usarán en el recibo timbrado (CFDI de nómina). Escríbelos como en la Constancia de Situación Fiscal de la empresa.</p>
        <h2 className="seccion-titulo">Timbrado del recibo (CFDI)</h2>
        <p className="linea-timbrado">
          <span className={`etiqueta ${d.timbrado_activo ? 'etiqueta-ok' : 'etiqueta-neutro'}`}>{d.timbrado_activo ? 'Activo' : 'Desactivado'}</span>{' '}
          <span className="tenue">{d.timbrado_activo
            ? 'Las nóminas autorizadas se timbran desde «Nómina del periodo».'
            : 'Las nóminas se calculan, autorizan y guardan sin folio fiscal; no cambia ningún cálculo.'}
            {' '}El CSD, el PAC y la activación están en la pestaña «Timbrado (CFDI)».</span>
        </p>
        {err('timbrado_activo')}
        <h2 className="seccion-titulo">Reglas de cálculo</h2>
        <div className="rejilla-form">
          <label>Periodicidad por defecto<select value={d.periodicidad} onChange={(e) => set({ periodicidad: e.target.value as Periodicidad })}>
            {Object.entries(PERIODICIDADES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></label>
          <label>Divisor del salario diario<input type="number" min={28} max={31} step="0.1" value={d.divisor_salario_diario} onChange={(e) => set({ divisor_salario_diario: Number(e.target.value) })} />
            <small className="tenue">Salario diario = sueldo mensual ÷ {d.divisor_salario_diario}. Otras empresas usan 30.4.</small>{err('divisor_salario_diario')}</label>
          <label>Días de aguinaldo<input type="number" min={15} max={90} value={d.dias_aguinaldo} onChange={(e) => set({ dias_aguinaldo: Number(e.target.value) })} />{err('dias_aguinaldo')}</label>
          <label>Prima vacacional (%)<input type="number" min={25} max={100} value={d.prima_vacacional} onChange={(e) => set({ prima_vacacional: Number(e.target.value) })} />{err('prima_vacacional')}</label>
          <label>ISR del aguinaldo<select value={d.metodo_isr_extraordinario ?? 'art174'} onChange={(e) => set({ metodo_isr_extraordinario: e.target.value as Empresa['metodo_isr_extraordinario'] })}>
            <option value="art174">Art. 174 RLISR (tasa promedio del pago)</option><option value="art96">Art. 96 LISR (se suma al ingreso del mes)</option></select>
            <small className="tenue">Los dos son legales; el recibo de aguinaldo muestra ambos para comparar.</small></label>
          <label>Zona de salario mínimo<select value={d.zona_salario_minimo} onChange={(e) => set({ zona_salario_minimo: e.target.value as Empresa['zona_salario_minimo'] })}>
            <option value="general">General</option><option value="frontera_norte">Zona Libre de la Frontera Norte</option></select></label>
        </div>
      </fieldset>
      {edita && <div className="editor-pub-pie"><button className="btn" type="submit">Guardar</button></div>}
    </form>
  );
}

// ───────────── Conceptos ─────────────
const METODOS: Record<ConceptoNomina['metodo'], string> = {
  sueldo: 'Salario diario × días pagados', fijo: 'Monto fijo', por_evento: '% del salario diario por evento', captura: 'Se captura en cada nómina',
  isr: 'Tarifa del ISR menos subsidio', imss: 'Cuotas obreras sobre el SBC',
  prima_vacacional: '% de prima vacacional sobre los días de vacaciones', aguinaldo: 'Nómina especial de aguinaldo',
};
function reglaTexto(c: ConceptoNomina): string {
  const r = c.regla ?? {};
  const partes: string[] = [];
  if (c.metodo === 'fijo') partes.push(dinero(c.monto));
  if (c.metodo === 'por_evento') partes.push(`${numero(c.porcentaje)} % del salario diario por domingo`);
  if (r.solo_fin_de_mes) partes.push('en el periodo que cierra el mes');
  if (r.sin_faltas) partes.push('si no faltó');
  if (r.sin_retardos) partes.push('si no tuvo retardos');
  if (r.antiguedad_meses_min) partes.push(`desde ${r.antiguedad_meses_min} meses de antigüedad`);
  if (r.frecuencia === 'unico') partes.push('una sola vez');
  if (r.solo_ajuste) return 'Lo llena «Cálculo anual de ISR» al aplicarlo';
  if (c.metodo === 'prima_vacacional') return METODOS.prima_vacacional;
  if (c.metodo === 'aguinaldo') return METODOS.aguinaldo;
  return partes.length ? partes.join(' · ') : METODOS[c.metodo];
}

function PestanaConceptos({ edita }: { edita: boolean }) {
  const qc = useQueryClient();
  const { data = [], isLoading } = useQuery({ queryKey: ['conceptos'], queryFn: nominaApi.conceptos });
  const [editando, setEditando] = useState<Partial<ConceptoNomina> | null>(null);
  const [aviso, setAviso] = useState<string | null>(null);
  if (isLoading) return <p>Cargando…</p>;
  const eliminar = async (c: ConceptoNomina) => {
    if (!confirm(`¿Eliminar el concepto «${c.nombre}»?`)) return;
    try { await nominaApi.eliminarConcepto(c.id); void qc.invalidateQueries({ queryKey: ['conceptos'] }); } catch (x) { setAviso(mensajeDeError(x)); }
  };
  const tabla = (tipo: 'percepcion' | 'deduccion' | 'otro_pago') => (
    <div className="tabla-scroll">
      <table className="tabla">
        <thead><tr><th>Clave</th><th>Concepto</th><th>SAT</th><th>Cómo se calcula</th><th>Estado</th>{edita && <th />}</tr></thead>
        <tbody>
          {data.filter((c) => c.tipo === tipo).map((c) => (
            <tr key={c.id} className={c.activo ? '' : 'fila-baja'}>
              <td className="mono">{c.clave}</td>
              <td><strong>{c.nombre}</strong>{c.descripcion && <small className="tenue linea-sub">{c.descripcion}</small>}</td>
              <td className="mono">{c.clave_sat ?? '—'}</td>
              <td>{reglaTexto(c)}</td>
              <td>{c.activo ? <span className="etiqueta etiqueta-ok">Activo</span> : <span className="etiqueta etiqueta-neutro">Inactivo</span>}
                {c.sistema && <span className="etiqueta etiqueta-info">Sistema</span>}</td>
              {edita && <td className="acciones">
                <button className="btn btn-sec btn-sm" onClick={() => setEditando(c)}>Editar</button>
                {!c.sistema && <button className="btn-icono peligro" aria-label={`Eliminar ${c.nombre}`} onClick={() => void eliminar(c)}>🗑</button>}
              </td>}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
  return (
    <div className="tarjeta">
      {aviso && <Aviso tipo="error">{aviso}</Aviso>}
      <div className="seccion-cab">
        <h2 className="seccion-titulo">Percepciones</h2>
        {edita && <button className="btn btn-sm" onClick={() => setEditando({ tipo: 'percepcion', metodo: 'fijo', monto: 0, porcentaje: 0, regla: { frecuencia: 'recurrente' }, exencion: { tipo: 'ninguna' }, activo: true })}>+ Nuevo concepto</button>}
      </div>
      {tabla('percepcion')}
      <h2 className="seccion-titulo">Deducciones</h2>
      {tabla('deduccion')}
      <h2 className="seccion-titulo">Otros pagos</h2>
      {tabla('otro_pago')}
      <p className="tenue">Infonavit, Fonacot y pensión alimenticia se agregarán en la v15. Mientras tanto puedes crear una deducción «Se captura en cada nómina».</p>
      {editando && <EditorConcepto c={editando} onCerrar={() => setEditando(null)} onListo={() => { setEditando(null); void qc.invalidateQueries({ queryKey: ['conceptos'] }); }} />}
    </div>
  );
}

function EditorConcepto({ c: inicial, onCerrar, onListo }: { c: Partial<ConceptoNomina>; onCerrar: () => void; onListo: () => void }) {
  const [c, setC] = useState(inicial);
  const [error, setError] = useState<string | null>(null);
  const nuevo = !inicial.id;
  const r = c.regla ?? {};
  const set = (x: Partial<ConceptoNomina>) => setC({ ...c, ...x });
  const setR = (x: Partial<NonNullable<ConceptoNomina['regla']>>) => set({ regla: { ...r, ...x } });
  const calculoLibre = !c.sistema;
  const guardar = async () => {
    setError(null);
    const datos: Partial<ConceptoNomina> = { nombre: c.nombre, clave_sat: c.clave_sat || null, descripcion: c.descripcion || null, activo: c.activo };
    if (calculoLibre) Object.assign(datos, { monto: c.monto, porcentaje: c.porcentaje, regla: c.regla, exencion: c.exencion });
    if (nuevo) Object.assign(datos, { clave: c.clave, tipo: c.tipo, metodo: c.metodo });
    try { if (nuevo) await nominaApi.crearConcepto(datos); else await nominaApi.actualizarConcepto(inicial.id!, datos); onListo(); }
    catch (x) { setError(mensajeDeError(x)); }
  };
  return (
    <Modal titulo={nuevo ? 'Nuevo concepto' : `Editar ${inicial.clave} · ${inicial.nombre}`} abierto onCerrar={onCerrar} ancho="md"
      pie={<><button className="btn btn-sec" onClick={onCerrar}>Cancelar</button><button className="btn" disabled={!c.nombre || (nuevo && !c.clave)} onClick={() => void guardar()}>Guardar</button></>}>
      {error && <Aviso tipo="error">{error}</Aviso>}
      <div className="rejilla-form">
        {nuevo && <label>Clave<input className="mono" maxLength={10} placeholder="P006" value={c.clave ?? ''} onChange={(e) => set({ clave: e.target.value.toUpperCase() })} /></label>}
        <label>Nombre<input value={c.nombre ?? ''} onChange={(e) => set({ nombre: e.target.value })} /></label>
        {nuevo && <label>Tipo<select value={c.tipo} onChange={(e) => set({ tipo: e.target.value as ConceptoNomina['tipo'] })}><option value="percepcion">Percepción</option><option value="deduccion">Deducción</option></select></label>}
        <label>Clave SAT<input className="mono" inputMode="numeric" maxLength={3} value={c.clave_sat ?? ''} onChange={(e) => set({ clave_sat: e.target.value })} />
          <small className="tenue">Catálogo c_Tipo{c.tipo === 'deduccion' ? 'Deduccion' : 'Percepcion'} del CFDI de nómina.</small></label>
        {nuevo && <label>Cálculo<select value={c.metodo} onChange={(e) => set({ metodo: e.target.value as ConceptoNomina['metodo'] })}>
          {(['fijo', 'por_evento', 'captura'] as const).filter((m) => c.tipo === 'percepcion' || m !== 'por_evento').map((m) => <option key={m} value={m}>{METODOS[m]}</option>)}</select></label>}
        {calculoLibre && c.metodo === 'fijo' && <label>Monto por pago<input type="number" min={0} step="0.01" value={c.monto ?? 0} onChange={(e) => set({ monto: Number(e.target.value) })} /></label>}
        {calculoLibre && c.metodo === 'por_evento' && <label>% del salario diario<input type="number" min={0} step="0.01" value={c.porcentaje ?? 0} onChange={(e) => set({ porcentaje: Number(e.target.value) })} /></label>}
      </div>
      {c.sistema && <Aviso tipo="info">Concepto del sistema: su cálculo sigue la ley (tablas fiscales). Puedes cambiar el nombre, la clave SAT o desactivarlo.</Aviso>}
      {calculoLibre && c.metodo === 'fijo' && (
        <fieldset className="reglas">
          <legend>Cuándo se paga</legend>
          <label className="check"><input type="checkbox" checked={!!r.solo_fin_de_mes} onChange={(e) => setR({ solo_fin_de_mes: e.target.checked })} /> Solo en el periodo que cierra el mes (2.ª quincena)</label>
          <label className="check"><input type="checkbox" checked={!!r.sin_faltas} onChange={(e) => setR({ sin_faltas: e.target.checked })} /> Solo si no tuvo faltas</label>
          <label className="check"><input type="checkbox" checked={!!r.sin_retardos} onChange={(e) => setR({ sin_retardos: e.target.checked })} /> Solo si no tuvo retardos</label>
          <label className="en-linea">A partir de <input type="number" min={0} max={600} value={r.antiguedad_meses_min ?? ''} placeholder="0" onChange={(e) => setR({ antiguedad_meses_min: e.target.value ? Number(e.target.value) : null })} /> meses de antigüedad</label>
          <div className="segmentado segmentado-sm">
            <button type="button" className={r.frecuencia !== 'unico' ? 'activo' : ''} onClick={() => setR({ frecuencia: 'recurrente' })}>Cada mes</button>
            <button type="button" className={r.frecuencia === 'unico' ? 'activo' : ''} onClick={() => setR({ frecuencia: 'unico' })}>Una sola vez</button>
          </div>
          {r.frecuencia === 'unico' && <small className="tenue">Se paga solo en el periodo en que cumple la antigüedad indicada.</small>}
        </fieldset>
      )}
      {calculoLibre && c.tipo === 'percepcion' && (
        <label>Exención de ISR<select value={c.exencion?.tipo ?? 'ninguna'} onChange={(e) => set({ exencion: { tipo: e.target.value as NonNullable<ConceptoNomina['exencion']>['tipo'], umas: c.exencion?.umas ?? 1 } })}>
          <option value="ninguna">Gravada al 100 %</option><option value="total">Exenta al 100 %</option>
          <option value="uma_por_evento">Exenta hasta N UMA por evento</option><option value="uma_tope">Exenta hasta N UMA en el periodo</option></select></label>
      )}
      <label>Descripción<input value={c.descripcion ?? ''} onChange={(e) => set({ descripcion: e.target.value })} /></label>
      <label className="check"><input type="checkbox" checked={c.activo ?? true} onChange={(e) => set({ activo: e.target.checked })} /> Activo (se incluye en el cálculo)</label>
    </Modal>
  );
}

// ───────────── Tablas fiscales ─────────────
const ETIQUETAS: Record<string, string> = { diaria: 'Diaria', mensual: 'Mensual', anual: 'Anual', general: 'General', frontera_norte: 'Frontera norte',
  porcentaje: '% de la UMA mensual', uma_mensual: 'UMA mensual usada', limite_mensual: 'Ingreso mensual máximo' };

function PestanaTablas({ edita }: { edita: boolean }) {
  const { data = [], isLoading } = useQuery({ queryKey: ['tablas'], queryFn: nominaApi.tablas });
  const [nueva, setNueva] = useState<TablaFiscal | null>(null);
  const [verTodas, setVerTodas] = useState(false);
  if (isLoading) return <p>Cargando…</p>;
  const visibles = verTodas ? data : data.filter((t) => t.vigente);
  return (
    <div>
      <Aviso tipo="info">Cada tabla guarda su fecha de vigencia: al calcular un periodo se usa la versión vigente en esa fecha. Cuando el SAT o el INEGI publiquen nuevos valores, agrega una versión nueva en lugar de cambiar la anterior.</Aviso>
      <label className="check"><input type="checkbox" checked={verTodas} onChange={(e) => setVerTodas(e.target.checked)} /> Mostrar versiones anteriores y futuras</label>
      <div className="rejilla-tablas">
        {visibles.map((t) => (
          <div key={t.id} className="tarjeta tabla-fiscal">
            <div className="seccion-cab">
              <div><h3>{t.nombre}</h3><small className="tenue">Vigente desde {fecha(t.vigente_desde)}{t.fuente ? ` · ${t.fuente}` : ''}</small></div>
              {t.vigente ? <span className="etiqueta etiqueta-ok">Vigente</span> : <span className="etiqueta etiqueta-neutro">Otra versión</span>}
            </div>
            <DatosTabla t={t} />
            {edita && <button className="btn btn-sec btn-sm" onClick={() => setNueva(t)}>Nueva versión</button>}
          </div>
        ))}
      </div>
      {nueva && <EditorTabla base={nueva} onCerrar={() => setNueva(null)} />}
    </div>
  );
}

function DatosTabla({ t }: { t: TablaFiscal }) {
  const d = t.datos as Record<string, unknown>;
  if (Array.isArray(d.renglones)) {
    return (
      <table className="tabla tabla-compacta">
        <thead><tr><th className="num">Límite inferior</th><th className="num">Cuota fija</th><th className="num">% excedente</th></tr></thead>
        <tbody>{(d.renglones as number[][]).map((r, i) => <tr key={i}><td className="num">{dinero(r[0])}</td><td className="num">{dinero(r[1])}</td><td className="num">{numero(r[2], 2)} %</td></tr>)}</tbody>
      </table>
    );
  }
  if (t.tipo === 'imss_obrero') {
    return <ul className="lista-simple">{Object.values(d as Record<string, { nombre: string; tasa: number }>).map((r) => <li key={r.nombre}><span>{r.nombre}</span><strong>{numero(r.tasa, 3)} %</strong></li>)}</ul>;
  }
  return <dl className="datos">{Object.entries(d).map(([k, v]) => <Fragment key={k}><dt>{ETIQUETAS[k] ?? k}</dt><dd>{k === 'porcentaje' ? `${numero(Number(v), 2)} %` : dinero(Number(v))}</dd></Fragment>)}</dl>;
}

function EditorTabla({ base, onCerrar }: { base: TablaFiscal; onCerrar: () => void }) {
  const qc = useQueryClient();
  const [desde, setDesde] = useState(hoyISO());
  const [fuente, setFuente] = useState('');
  const esIsr = Array.isArray((base.datos as Record<string, unknown>).renglones);
  const [texto, setTexto] = useState(esIsr ? ((base.datos as { renglones: number[][] }).renglones).map((r) => r.join('\t')).join('\n') : '');
  const [datos, setDatos] = useState<Record<string, unknown>>(structuredClone(base.datos));
  const [error, setError] = useState<string | null>(null);
  const guardar = async () => {
    setError(null);
    let d: unknown = datos;
    if (esIsr) {
      const renglones = texto.trim().split('\n').map((l) => l.replace(/[$,%]/g, '').trim().split(/[\s;]+/).map(Number));
      if (renglones.some((r) => r.length !== 3 || r.some(Number.isNaN))) { setError('Cada renglón debe tener 3 números: límite inferior, cuota fija y % sobre excedente.'); return; }
      d = { renglones };
    }
    try { await nominaApi.guardarTabla({ tipo: base.tipo, vigente_desde: desde, datos: d, fuente: fuente || undefined }); void qc.invalidateQueries({ queryKey: ['tablas'] }); onCerrar(); }
    catch (x) { setError(mensajeDeError(x)); }
  };
  return (
    <Modal titulo={`Nueva versión: ${base.nombre}`} abierto onCerrar={onCerrar} ancho="md"
      pie={<><button className="btn btn-sec" onClick={onCerrar}>Cancelar</button><button className="btn" onClick={() => void guardar()}>Guardar versión</button></>}>
      {error && <Aviso tipo="error">{error}</Aviso>}
      <div className="rejilla-form">
        <label>Vigente desde<input type="date" value={desde} onChange={(e) => setDesde(e.target.value)} /></label>
        <label>Fuente<input value={fuente} placeholder="Ej. DOF 28/12/2026, Anexo 8 RMF" onChange={(e) => setFuente(e.target.value)} /></label>
      </div>
      {esIsr ? (
        <label>Renglones (uno por línea: límite inferior, cuota fija, % sobre excedente)
          <textarea className="mono" rows={12} value={texto} onChange={(e) => setTexto(e.target.value)} />
          <small className="tenue">Puedes pegar las columnas copiadas del DOF o de Excel; se ignoran los signos $ , y %.</small></label>
      ) : base.tipo === 'imss_obrero' ? (
        Object.entries(datos as Record<string, { nombre: string; tasa: number }>).map(([k, r]) => (
          <label key={k} className="en-linea">{r.nombre}<input type="number" step="0.001" min={0} value={r.tasa}
            onChange={(e) => setDatos({ ...datos, [k]: { ...r, tasa: Number(e.target.value) } })} /> %</label>
        ))
      ) : (
        <div className="rejilla-form">
          {Object.entries(datos).map(([k, v]) => <label key={k}>{ETIQUETAS[k] ?? k}<input type="number" step="0.01" min={0} value={Number(v)} onChange={(e) => setDatos({ ...datos, [k]: Number(e.target.value) })} /></label>)}
        </div>
      )}
    </Modal>
  );
}

// ───────────── Periodos ─────────────
function PestanaPeriodos({ edita }: { edita: boolean }) {
  const qc = useQueryClient();
  const { data: empresa } = useQuery({ queryKey: ['empresa'], queryFn: nominaApi.empresa });
  const [tipo, setTipo] = useState<Periodicidad | null>(null);
  const [anio, setAnio] = useState(new Date().getFullYear());
  const t = tipo ?? empresa?.periodicidad ?? 'quincenal';
  const { data = [], isLoading } = useQuery({ queryKey: ['periodos', t, anio], queryFn: () => nominaApi.periodos(t, anio) });
  const [aviso, setAviso] = useState<{ tipo: 'exito' | 'error'; texto: string } | null>(null);
  const hoy = hoyISO();
  const generar = async () => {
    try { const r = await nominaApi.generarPeriodos({ tipo: t, ejercicio: anio }); setAviso({ tipo: 'exito', texto: r.message }); void qc.invalidateQueries({ queryKey: ['periodos'] }); }
    catch (x) { setAviso({ tipo: 'error', texto: mensajeDeError(x) }); }
  };
  const cambiarPago = async (id: number, f: string) => {
    try { await nominaApi.fechaPago(id, f); void qc.invalidateQueries({ queryKey: ['periodos'] }); } catch (x) { setAviso({ tipo: 'error', texto: mensajeDeError(x) }); }
  };
  return (
    <div className="tarjeta">
      <div className="historial-barra">
        <div className="segmentado segmentado-sm">
          {(Object.keys(PERIODICIDADES) as Periodicidad[]).map((p) => <button key={p} className={t === p ? 'activo' : ''} onClick={() => setTipo(p)}>{PERIODICIDADES[p]}</button>)}
        </div>
        <select aria-label="Ejercicio" value={anio} onChange={(e) => setAnio(Number(e.target.value))}>
          {[anio - 1, anio, anio + 1].filter((v, i, a) => a.indexOf(v) === i).map((a) => <option key={a}>{a}</option>)}
        </select>
        {edita && <button className="btn btn-sm" onClick={() => void generar()}>{data.length ? 'Completar calendario' : `Generar calendario ${anio}`}</button>}
      </div>
      {aviso && <Aviso tipo={aviso.tipo}>{aviso.texto}</Aviso>}
      {isLoading && <p>Cargando…</p>}
      {!isLoading && data.length === 0 && <div className="vacio">No hay periodos {PERIODICIDADES[t].toLowerCase()}es para {anio}. {edita ? 'Genéralos con el botón de arriba.' : ''}</div>}
      {data.length > 0 && (
        <div className="tabla-scroll">
          <table className="tabla">
            <thead><tr><th>Núm.</th><th>Periodo</th><th className="num">Días</th><th>Fecha de pago</th><th>Bonos de fin de mes</th><th>Estado</th></tr></thead>
            <tbody>
              {data.map((p) => (
                <tr key={p.id} className={p.inicio <= hoy && hoy <= p.fin ? 'fila-actual' : ''}>
                  <td className="mono">{String(p.numero).padStart(2, '0')}</td>
                  <td>{fecha(p.inicio)} – {fecha(p.fin)}</td>
                  <td className="num">{p.dias}</td>
                  <td>{edita && p.estado === 'abierto'
                    ? <input type="date" className="input-tabla" value={p.fecha_pago} onChange={(e) => e.target.value && void cambiarPago(p.id, e.target.value)} />
                    : fecha(p.fecha_pago)}</td>
                  <td>{p.fin_de_mes ? <span className="etiqueta etiqueta-info">Sí</span> : <span className="tenue">—</span>}</td>
                  <td><span className={`etiqueta ${p.estado === 'abierto' ? 'etiqueta-neutro' : 'etiqueta-ok'}`}>{p.estado === 'abierto' ? 'Abierto' : p.estado}</span></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
