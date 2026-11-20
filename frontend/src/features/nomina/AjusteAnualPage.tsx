import { useMemo, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { ajusteAnualApi, nominaApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { useAuth } from '../../auth/AuthContext';
import { Aviso } from '../../components/Aviso';
import { Modal } from '../../components/Modal';
import type { AjusteAnualRespuesta } from '../../types';
import { dinero, fecha, hoyISO } from '../../utils/formato';
import { descargarCSV } from '../../utils/exportar';

/**
 * v13 — Cálculo anual del ISR (art. 97 LISR) con las nóminas autorizadas del año.
 * Diferencia a cargo → se descuenta (D003); a favor → se devuelve como otro pago (O001), en una quincena abierta.
 */
export function AjusteAnualPage() {
  const { puede } = useAuth();
  const qc = useQueryClient();
  const hoy = hoyISO();
  // En enero y febrero normalmente se trabaja el ejercicio anterior
  const [anio, setAnio] = useState(Number(hoy.slice(0, 4)) - (Number(hoy.slice(5, 7)) <= 2 ? 1 : 0));
  const { data, isLoading } = useQuery({ queryKey: ['ajuste-anual', anio], queryFn: () => ajusteAnualApi.obtener(anio) });
  const [aviso, setAviso] = useState<{ tipo: 'exito' | 'error' | 'advertencia'; texto: string } | null>(null);
  const [trabajando, setTrabajando] = useState(false);
  const [aplicar, setAplicar] = useState(false);
  const [detalle, setDetalle] = useState<AjusteAnualRespuesta['filas'][number] | null>(null);
  const autoriza = puede('nomina.autorizar');
  const poner = (d: AjusteAnualRespuesta) => qc.setQueryData(['ajuste-anual', anio], d);
  const correr = async (fn: () => Promise<void>) => {
    setTrabajando(true); setAviso(null);
    try { await fn(); } catch (e) { setAviso({ tipo: 'error', texto: mensajeDeError(e) }); } finally { setTrabajando(false); }
  };
  const aplicados = data?.filas.filter((f) => f.estado === 'aplicado') ?? [];
  const actual = Number(hoy.slice(0, 4));

  const exportar = () => data && descargarCSV(`calculo-anual-isr-${anio}`, [
    ['Núm.', 'Empleado', '¿Aplica?', 'Motivo', 'Ingresos del año', 'Gravado', 'ISR anual', 'Subsidio aplicado', 'ISR retenido', 'Diferencia', 'Resultado', 'Estado'],
    ...data.filas.map((f) => [f.numero_empleado, f.nombre, f.aplica ? 'Sí' : 'No', f.motivo ?? '', f.datos.ingresos_totales, f.datos.gravado, f.datos.isr_anual,
      f.datos.subsidio_aplicado, f.datos.isr_retenido, f.diferencia, { a_cargo: 'A cargo', a_favor: 'A favor', cero: 'Sin diferencia' }[f.resultado], f.estado]),
  ]);

  return (
    <section>
      <div className="encabezado-pagina">
        <div>
          <h1>Cálculo anual de ISR</h1>
          <p className="tenue">Compara el ISR del año completo (tarifa anual) contra lo retenido en cada nómina. Art. 97 de la Ley del ISR.</p>
        </div>
        {data && data.filas.length > 0 && <button className="btn btn-sec" onClick={exportar}>Exportar a Excel</button>}
      </div>
      <div className="historial-barra">
        <select aria-label="Ejercicio" value={anio} onChange={(e) => setAnio(Number(e.target.value))}>{[actual - 1, actual].map((a) => <option key={a}>{a}</option>)}</select>
        {autoriza && <button className="btn" disabled={trabajando} onClick={() => void correr(async () => { poner(await ajusteAnualApi.calcular(anio)); setAviso({ tipo: 'exito', texto: 'Cálculo anual actualizado con las nóminas autorizadas.' }); })}>
          {data?.filas.length ? 'Recalcular' : `Calcular ${anio}`}</button>}
        {autoriza && data && data.totales.aplican > 0 && aplicados.length === 0 && <button className="btn btn-sec" onClick={() => setAplicar(true)}>Aplicar en una nómina…</button>}
        {autoriza && aplicados.length > 0 && <button className="btn btn-sec" disabled={trabajando} onClick={() => void correr(async () => { poner(await ajusteAnualApi.quitar(anio)); setAviso({ tipo: 'exito', texto: 'Se quitó la aplicación; recalcula esa nómina.' }); })}>Quitar aplicación</button>}
      </div>

      {aviso && <Aviso tipo={aviso.tipo}>{aviso.texto}</Aviso>}
      {data && data.periodos_sin_autorizar > 0 && data.filas.length > 0 && (
        <Aviso tipo="advertencia">Hay {data.periodos_sin_autorizar} periodo(s) de {anio} sin autorizar. Lo normal es calcular después de autorizar la última nómina de diciembre (y el aguinaldo); si calculas antes, recalcula al final.</Aviso>
      )}
      {aplicados.length > 0 && <Aviso tipo="info">Aplicado en {aplicados[0].periodo}. Calcula y autoriza esa nómina para que se pague. <Link to="/nomina">Ir a la nómina</Link></Aviso>}
      {isLoading && <p>Cargando…</p>}
      {data && data.filas.length === 0 && <div className="vacio">Aún no hay cálculo anual de {anio}. {autoriza ? 'Pulsa «Calcular» cuando estén autorizadas las nóminas del año.' : 'Lo hace el administrador al cierre del año.'}</div>}

      {data && data.filas.length > 0 && <>
        <div className="kpis">
          <div className="kpi"><span className="tenue">Empleados con cálculo</span><strong>{data.totales.aplican} de {data.filas.length}</strong></div>
          <div className="kpi"><span className="tenue">ISR a cargo (se descuenta)</span><strong>{dinero(data.totales.a_cargo)}</strong></div>
          <div className="kpi"><span className="tenue">ISR a favor (se devuelve)</span><strong>{dinero(data.totales.a_favor)}</strong></div>
        </div>
        <div className="tabla-scroll">
          <table className="tabla">
            <thead><tr><th>Empleado</th><th className="num">Gravado del año</th><th className="num">ISR anual</th><th className="num">Retenido</th><th className="num">Diferencia</th><th>Resultado</th>
              <th title="El trabajador avisó por escrito que presentará su declaración anual">Declara por su cuenta</th><th /></tr></thead>
            <tbody>
              {data.filas.map((f) => (
                <tr key={f.id} className={f.aplica ? '' : 'fila-baja'}>
                  <td className="col-nombre">{f.nombre}<small className="tenue linea-sub">{f.numero_empleado}{f.motivo ? ` · No aplica: ${f.motivo}` : ''}</small></td>
                  <td className="num">{dinero(f.datos.gravado)}</td>
                  <td className="num">{dinero(f.datos.isr_despues_subsidio)}</td>
                  <td className="num">{dinero(f.datos.isr_retenido)}</td>
                  <td className={`num ${f.aplica ? (f.resultado === 'a_cargo' ? 'texto-peligro' : f.resultado === 'a_favor' ? 'texto-ok' : '') : 'tenue'}`}><strong>{dinero(Math.abs(f.diferencia))}</strong></td>
                  <td>{!f.aplica ? <span className="etiqueta etiqueta-neutro">No aplica</span>
                    : f.resultado === 'a_cargo' ? <span className="etiqueta etiqueta-mal">A cargo</span>
                    : f.resultado === 'a_favor' ? <span className="etiqueta etiqueta-ok">A favor</span> : <span className="etiqueta etiqueta-neutro">Sin diferencia</span>}
                    {f.estado === 'aplicado' && <span className="etiqueta etiqueta-info">Aplicado</span>}</td>
                  <td><input type="checkbox" aria-label={`${f.nombre} declara por su cuenta`} checked={f.declara_por_su_cuenta} disabled={!autoriza || f.estado === 'aplicado' || trabajando}
                    onChange={(e) => void correr(async () => poner(await ajusteAnualApi.declaracion(f.id, e.target.checked)))} /></td>
                  <td className="acciones"><button className="btn btn-sec btn-sm" onClick={() => setDetalle(f)}>Detalle</button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <p className="tenue">No se calcula a quien ingresó después del 1 de enero, se fue antes del 1 de diciembre, ganó más de $400,000 en el año o avisó por escrito que declarará por su cuenta. El subsidio para el empleo reduce el ISR anual pero su excedente no se devuelve.</p>
      </>}

      {aplicar && <DialogoAplicar anio={anio} onCerrar={() => setAplicar(false)} onListo={(texto, d) => { poner(d); setAplicar(false); setAviso({ tipo: 'exito', texto }); void qc.invalidateQueries({ queryKey: ['captura'] }); }} />}
      {detalle && <DetalleAnual f={detalle} anio={anio} onCerrar={() => setDetalle(null)} />}
    </section>
  );
}

function DialogoAplicar({ anio, onCerrar, onListo }: { anio: number; onCerrar: () => void; onListo: (texto: string, d: AjusteAnualRespuesta) => void }) {
  const { data: empresa } = useQuery({ queryKey: ['empresa'], queryFn: nominaApi.empresa, staleTime: 300_000 });
  const tipo = empresa?.periodicidad ?? 'quincenal';
  const { data: esteAnio = [] } = useQuery({ queryKey: ['periodos', tipo, anio], queryFn: () => nominaApi.periodos(tipo, anio), enabled: !!empresa });
  const { data: siguiente = [] } = useQuery({ queryKey: ['periodos', tipo, anio + 1], queryFn: () => nominaApi.periodos(tipo, anio + 1), enabled: !!empresa });
  // Diciembre del ejercicio o los primeros meses del siguiente, sin autorizar
  const opciones = useMemo(() => [...esteAnio.filter((p) => p.fin.slice(5, 7) === '12'), ...siguiente.filter((p) => p.inicio.slice(5, 7) <= '03')]
    .filter((p) => p.estado !== 'autorizado'), [esteAnio, siguiente]);
  const [periodo, setPeriodo] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const elegido = periodo ?? opciones[0]?.id ?? null;
  const aplicar = async () => {
    try {
      const r = await ajusteAnualApi.aplicar(anio, elegido!);
      onListo(`Aplicado a ${r.aplicados} empleado(s) en ${r.periodo}.${r.fuera.length ? ` No están en esa nómina: ${r.fuera.join(', ')}.` : ''}`, r.ajuste);
    } catch (e) { setError(mensajeDeError(e)); }
  };
  return (
    <Modal titulo={`Aplicar el cálculo anual ${anio}`} abierto onCerrar={onCerrar} ancho="sm"
      pie={<><button className="btn btn-sec" onClick={onCerrar}>Cancelar</button><button className="btn" disabled={!elegido} onClick={() => void aplicar()}>Aplicar</button></>}>
      {error && <Aviso tipo="error">{error}</Aviso>}
      <p>Los importes se escriben en la nómina elegida: <strong>a cargo</strong> como deducción «ISR a cargo por ajuste anual» y <strong>a favor</strong> como otro pago «ISR a favor por ajuste anual». Después calcula y autoriza esa nómina.</p>
      {opciones.length === 0 ? <Aviso tipo="advertencia">No hay nóminas abiertas de diciembre {anio} ni de enero–marzo {anio + 1}. Genera el calendario del año siguiente en Configuración.</Aviso> : (
        <label>Nómina<select value={elegido ?? ''} onChange={(e) => setPeriodo(Number(e.target.value))}>
          {opciones.map((p) => <option key={p.id} value={p.id}>{p.etiqueta}</option>)}</select></label>
      )}
    </Modal>
  );
}

function DetalleAnual({ f, anio, onCerrar }: { f: AjusteAnualRespuesta['filas'][number]; anio: number; onCerrar: () => void }) {
  const d = f.datos;
  return (
    <Modal titulo={`${f.nombre} · cálculo anual ${anio}`} abierto onCerrar={onCerrar} ancho="sm" pie={<button className="btn" onClick={onCerrar}>Cerrar</button>}>
      {!f.aplica && <Aviso tipo="info">No aplica: {f.motivo}. Se muestra solo como referencia.</Aviso>}
      <table className="tabla tabla-compacta">
        <tbody>
          <tr><td>Ingresos del año (nóminas autorizadas)</td><td className="num">{dinero(d.ingresos_totales)}</td></tr>
          <tr><td>Ingresos gravados</td><td className="num">{dinero(d.gravado)}</td></tr>
          <tr><td>(−) Límite inferior · (×) {d.tarifa.porcentaje} % · (+) cuota fija {dinero(d.tarifa.cuota_fija)}</td><td className="num">{dinero(d.tarifa.limite_inferior)}</td></tr>
          <tr><td>(=) ISR anual (tarifa art. 152)</td><td className="num">{dinero(d.isr_anual)}</td></tr>
          <tr><td>(−) Subsidio para el empleo aplicado en el año</td><td className="num">{dinero(d.subsidio_aplicado)}</td></tr>
          <tr><td>(=) ISR del año</td><td className="num">{dinero(d.isr_despues_subsidio)}</td></tr>
          <tr><td>(−) ISR retenido en las nóminas</td><td className="num">{dinero(d.isr_retenido)}</td></tr>
          <tr className="fila-total"><td>{d.diferencia > 0 ? 'A cargo del trabajador' : d.diferencia < 0 ? 'A favor del trabajador' : 'Sin diferencia'}</td><td className="num">{dinero(Math.abs(d.diferencia))}</td></tr>
        </tbody>
      </table>
      <p className="tenue">Calculado el {fecha(hoyISO())} con la tarifa anual vigente al 31/12/{anio}.</p>
    </Modal>
  );
}
