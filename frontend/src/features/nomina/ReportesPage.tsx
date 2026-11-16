import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useSearchParams } from 'react-router-dom';
import { empleadosApi, nominaApi, nominaPeriodoApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { Aviso } from '../../components/Aviso';
import type { Periodicidad } from '../../types';
import { dinero, fecha, hoyISO, PERIODICIDADES } from '../../utils/formato';
import { descargarCSV } from '../../utils/exportar';
import { EtiquetaEstado } from './piezas';

/** v12 — Reportes: lista de raya de un periodo y acumulado de conceptos por periodo del ejercicio. Exportan a Excel (CSV) y PDF. */
export function ReportesPage() {
  const [params, setParams] = useSearchParams();
  const pestana = params.get('pestana') === 'acumulados' ? 'acumulados' : 'raya';
  return (
    <section>
      <div className="encabezado-pagina no-imprimir">
        <div>
          <h1>Reportes de nómina</h1>
          <p className="tenue">Se exportan a Excel o se imprimen / guardan como PDF.</p>
        </div>
      </div>
      <div className="segmentado pestanas-config no-imprimir" role="tablist">
        <button role="tab" aria-selected={pestana === 'raya'} className={pestana === 'raya' ? 'activo' : ''} onClick={() => setParams({ pestana: 'raya' })}>Lista de raya</button>
        <button role="tab" aria-selected={pestana === 'acumulados'} className={pestana === 'acumulados' ? 'activo' : ''} onClick={() => setParams({ pestana: 'acumulados' })}>Acumulado de conceptos</button>
      </div>
      {pestana === 'raya' ? <ListaDeRaya periodoId={Number(params.get('periodo')) || undefined} onPeriodo={(id) => setParams({ pestana: 'raya', periodo: String(id) })} /> : <AcumuladosVista />}
    </section>
  );
}

function ListaDeRaya({ periodoId, onPeriodo }: { periodoId?: number; onPeriodo: (id: number) => void }) {
  const { data: empresa } = useQuery({ queryKey: ['empresa'], queryFn: nominaApi.empresa, staleTime: 300_000 });
  const anioActual = Number(hoyISO().slice(0, 4));
  const [anio, setAnio] = useState(anioActual);
  const tipo = empresa?.periodicidad ?? 'quincenal';
  const { data: historial = [] } = useQuery({ queryKey: ['historial', tipo, anio], queryFn: () => nominaPeriodoApi.historial(anio, tipo), enabled: !!empresa });
  const conNomina = historial.filter((p) => p.recibos > 0);
  const id = periodoId ?? conNomina.filter((p) => p.estado === 'autorizado').at(-1)?.id ?? conNomina.at(-1)?.id;
  const { data, isLoading, isError, error } = useQuery({ queryKey: ['lista-raya', id], queryFn: () => nominaPeriodoApi.listaDeRaya(id!), enabled: !!id });

  const exportar = () => {
    if (!data) return;
    descargarCSV(`lista-de-raya-${data.periodo.ejercicio}-${String(data.periodo.numero).padStart(2, '0')}`, [
      [`Lista de raya · ${data.periodo.etiqueta}`],
      ['Núm.', 'Empleado', 'RFC', 'Puesto', 'Días', ...data.columnas.map((c) => c.nombre), 'Percepciones', 'Deducciones', 'Neto', 'UUID'],
      ...data.filas.map((f) => [f.numero_empleado, f.nombre, f.rfc, f.puesto, f.dias, ...data.columnas.map((c) => f.importes[c.clave]), f.percepciones, f.deducciones, f.neto, f.uuid]),
      ['', 'TOTAL', '', '', '', ...data.columnas.map((c) => data.totales.importes[c.clave]), data.totales.percepciones, data.totales.deducciones, data.totales.neto, ''],
    ]);
  };
  return (
    <div>
      <div className="historial-barra no-imprimir">
        <select aria-label="Ejercicio" value={anio} onChange={(e) => setAnio(Number(e.target.value))}>{[anioActual - 1, anioActual].map((a) => <option key={a}>{a}</option>)}</select>
        {conNomina.length > 0 && <select aria-label="Periodo" className="select-periodo" value={id ?? ''} onChange={(e) => onPeriodo(Number(e.target.value))}>
          {conNomina.map((p) => <option key={p.id} value={p.id}>{String(p.numero).padStart(2, '0')} · {fecha(p.inicio)} – {fecha(p.fin)}{p.estado !== 'autorizado' ? ' · pre-nómina' : ''}</option>)}
        </select>}
        <button className="btn btn-sec" disabled={!data} onClick={exportar}>Exportar a Excel</button>
        <button className="btn btn-sec" disabled={!data} onClick={() => window.print()}>Imprimir / PDF</button>
      </div>
      {!id && <div className="vacio">No hay nóminas calculadas en {anio} ({PERIODICIDADES[tipo].toLowerCase()}).</div>}
      {isLoading && <p>Cargando…</p>}
      {isError && <Aviso tipo="error">{mensajeDeError(error)}</Aviso>}
      {data && (
        <div className="tarjeta reporte">
          <div className="seccion-cab">
            <div><h2 className="seccion-titulo">Lista de raya · {data.periodo.etiqueta}</h2><small className="tenue">{empresa?.razon_social || empresa?.nombre} · pago {fecha(data.periodo.fecha_pago)}</small></div>
            <EtiquetaEstado estado={data.periodo.estado} />
          </div>
          {data.periodo.estado !== 'autorizado' && <Aviso tipo="advertencia">Pre-nómina: los importes pueden cambiar hasta que se autorice.</Aviso>}
          <div className="tabla-scroll">
            <table className="tabla tabla-compacta tabla-reporte">
              <thead><tr><th>Núm.</th><th>Empleado</th><th className="num">Días</th>
                {data.columnas.map((c) => <th key={c.clave} className={`num ${c.tipo === 'deduccion' ? 'col-deduccion' : ''}`}>{c.nombre}</th>)}
                <th className="num">Percepciones</th><th className="num">Deducciones</th><th className="num">Neto</th></tr></thead>
              <tbody>
                {data.filas.map((f) => (
                  <tr key={f.recibo_id}><td className="mono">{f.numero_empleado}</td><td>{f.nombre}</td><td className="num">{f.dias}</td>
                    {data.columnas.map((c) => <td key={c.clave} className="num">{f.importes[c.clave] ? dinero(f.importes[c.clave]) : <span className="tenue">—</span>}</td>)}
                    <td className="num">{dinero(f.percepciones)}</td><td className="num">{dinero(f.deducciones)}</td><td className="num"><strong>{dinero(f.neto)}</strong></td></tr>
                ))}
                <tr className="fila-total"><td /><td>Total ({data.filas.length})</td><td />
                  {data.columnas.map((c) => <td key={c.clave} className="num">{dinero(data.totales.importes[c.clave])}</td>)}
                  <td className="num">{dinero(data.totales.percepciones)}</td><td className="num">{dinero(data.totales.deducciones)}</td><td className="num">{dinero(data.totales.neto)}</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}

function AcumuladosVista() {
  const { data: empresa } = useQuery({ queryKey: ['empresa'], queryFn: nominaApi.empresa, staleTime: 300_000 });
  const anioActual = Number(hoyISO().slice(0, 4));
  const [anio, setAnio] = useState(anioActual);
  const [tipo, setTipo] = useState<Periodicidad | null>(null);
  const [empleado, setEmpleado] = useState<number | undefined>();
  const t = tipo ?? empresa?.periodicidad ?? 'quincenal';
  const { data: empleados = [] } = useQuery({ queryKey: ['empleados', { estado: 'todos' }], queryFn: () => empleadosApi.listar({ estado: 'todos' }) });
  const { data, isLoading } = useQuery({ queryKey: ['acumulados', anio, t, empleado], queryFn: () => nominaPeriodoApi.acumulados({ ejercicio: anio, tipo: t, empleado_id: empleado }), enabled: !!empresa });
  const nombre = useMemo(() => empleados.find((e) => e.id === empleado)?.nombre, [empleados, empleado]);
  const exportar = () => data && descargarCSV(`acumulados-${anio}${empleado ? `-${empleado}` : ''}`, [
    [`Acumulado de conceptos ${anio}${nombre ? ` · ${nombre}` : ' · todos los empleados'}`],
    ['Clave', 'Concepto', 'Tipo', ...data.periodos.map((p) => `P${String(p.numero).padStart(2, '0')}`), 'Total'],
    ...data.filas.map((f) => [f.clave, f.nombre, f.tipo === 'percepcion' ? 'Percepción' : 'Deducción', ...data.periodos.map((p) => f.periodos[p.numero]), f.total]),
  ]);
  return (
    <div>
      <div className="historial-barra no-imprimir">
        <select aria-label="Ejercicio" value={anio} onChange={(e) => setAnio(Number(e.target.value))}>{[anioActual - 1, anioActual].map((a) => <option key={a}>{a}</option>)}</select>
        <select aria-label="Periodicidad" value={t} onChange={(e) => setTipo(e.target.value as Periodicidad)}>
          {Object.entries(PERIODICIDADES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select>
        <select aria-label="Empleado" value={empleado ?? ''} onChange={(e) => setEmpleado(e.target.value ? Number(e.target.value) : undefined)}>
          <option value="">Todos los empleados</option>{empleados.map((e) => <option key={e.id} value={e.id}>{e.nombre}</option>)}</select>
        <button className="btn btn-sec" disabled={!data?.filas.length} onClick={exportar}>Exportar a Excel</button>
        <button className="btn btn-sec" disabled={!data?.filas.length} onClick={() => window.print()}>Imprimir / PDF</button>
      </div>
      {isLoading && <p>Cargando…</p>}
      {data && data.filas.length === 0 && <div className="vacio">No hay nóminas autorizadas en {anio}.</div>}
      {data && data.filas.length > 0 && (
        <div className="tarjeta reporte">
          <h2 className="seccion-titulo">Acumulado de conceptos {anio} · {nombre ?? 'todos los empleados'}</h2>
          <div className="tabla-scroll">
            <table className="tabla tabla-compacta tabla-reporte">
              <thead><tr><th>Concepto</th>{data.periodos.map((p) => <th key={p.numero} className="num" title={`${fecha(p.inicio)} – ${fecha(p.fin)}`}>P{String(p.numero).padStart(2, '0')}</th>)}<th className="num">Total</th></tr></thead>
              <tbody>
                {data.filas.map((f) => (
                  <tr key={f.clave} className={f.tipo === 'deduccion' ? 'fila-deduccion' : ''}>
                    <td><span className="mono tenue">{f.clave}</span> {f.nombre}</td>
                    {data.periodos.map((p) => <td key={p.numero} className="num">{f.periodos[p.numero] ? dinero(f.periodos[p.numero]) : <span className="tenue">—</span>}</td>)}
                    <td className="num"><strong>{dinero(f.total)}</strong></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}
