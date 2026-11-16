import { useState, type ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { indicadoresApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { useAuth } from '../../auth/AuthContext';
import { Aviso } from '../../components/Aviso';
import { Grafica } from '../../components/Graficas';
import type { IndicadoresRespuesta } from '../../types';
import { dinero, hoyISO, numero } from '../../utils/formato';
import { descargarCSV } from '../../utils/exportar';

const SERIES = ['var(--serie-1)', 'var(--serie-2)', 'var(--serie-3)', 'var(--serie-4)'];
const pesos = (n: number) => (n >= 1000 ? `$${numero(n / 1000, n >= 10000 ? 0 : 1)}k` : `$${Math.round(n)}`);

/**
 * v12 — Indicadores de Vagón COM por quincena o por mes: faltas, retardos, domingos, bonos pagados,
 * altas, bajas y plantilla. Lo de nómina sale de los periodos AUTORIZADOS; altas y bajas, de la trayectoria.
 */
export function IndicadoresPage() {
  const { puede } = useAuth();
  const actual = Number(hoyISO().slice(0, 4));
  const [anio, setAnio] = useState(actual);
  const [agrupar, setAgrupar] = useState<'quincena' | 'mes'>('quincena');
  const [tabla, setTabla] = useState(false);
  const { data, isLoading, isError, error } = useQuery({ queryKey: ['indicadores', anio, agrupar], queryFn: () => indicadoresApi.obtener(anio, agrupar) });

  return (
    <section>
      <div className="encabezado-pagina">
        <div>
          <h1>Indicadores</h1>
          <p className="tenue">Cómo se comporta la plantilla de Vagón COM a lo largo del año.</p>
        </div>
        {data && <button className="btn btn-sec" onClick={() => exportar(data, puede('nomina.ver'))}>Exportar a Excel</button>}
      </div>
      <div className="historial-barra">
        <select aria-label="Ejercicio" value={anio} onChange={(e) => setAnio(Number(e.target.value))}>{[actual - 1, actual].map((a) => <option key={a}>{a}</option>)}</select>
        <div className="segmentado segmentado-sm" role="tablist" aria-label="Agrupar por">
          <button role="tab" aria-selected={agrupar === 'quincena'} className={agrupar === 'quincena' ? 'activo' : ''} onClick={() => setAgrupar('quincena')}>Por quincena</button>
          <button role="tab" aria-selected={agrupar === 'mes'} className={agrupar === 'mes' ? 'activo' : ''} onClick={() => setAgrupar('mes')}>Por mes</button>
        </div>
        <label className="check"><input type="checkbox" checked={tabla} onChange={(e) => setTabla(e.target.checked)} /> Ver datos en tabla</label>
      </div>

      {isLoading && <p>Cargando…</p>}
      {isError && <Aviso tipo="error">{mensajeDeError(error)}</Aviso>}
      {data && <Contenido d={data} tabla={tabla} montos={puede('nomina.ver')} />}
    </section>
  );
}

function Contenido({ d, tabla, montos }: { d: IndicadoresRespuesta; tabla: boolean; montos: boolean }) {
  const s = d.serie;
  const et = s.map((x) => x.etiqueta);
  // Por quincena: el nombre del mes centrado entre sus dos quincenas
  const eje = d.agrupar === 'quincena' ? et.map((e) => (e.startsWith('1.ª') ? e.slice(4) : '')) : undefined;
  const ultimo = [...s].reverse().find((x) => x.recibos > 0 || x.altas > 0 || x.bajas > 0) ?? s.at(-1);
  if (tabla) return <TablaDatos d={d} />;
  return (
    <>
      {!d.con_nomina && <Aviso tipo="info">Las faltas, los domingos y los bonos aparecen cuando se autorizan nóminas de {d.ejercicio}. <Link to="/nomina">Ir a la nómina del periodo</Link>. Altas y bajas ya se muestran.</Aviso>}
      <div className="kpis">
        <div className="kpi"><span className="tenue">Faltas en {d.ejercicio}</span><strong>{d.totales.faltas}</strong><small className="tenue">{d.totales.retardos} retardos</small></div>
        <div className="kpi"><span className="tenue">Domingos trabajados</span><strong>{d.totales.domingos}</strong></div>
        {montos && <div className="kpi"><span className="tenue">Bonos pagados</span><strong>{dinero(d.totales.bonos)}</strong></div>}
        <div className="kpi"><span className="tenue">Altas / bajas</span><strong>{d.totales.altas} / {d.totales.bajas}</strong><small className="tenue">Plantilla actual: {ultimo?.plantilla ?? 0}</small></div>
      </div>
      <div className="rejilla-graficas">
        <Tarjeta titulo="Faltas y retardos" nota="Suma de lo capturado en las nóminas autorizadas.">
          <Grafica ejeX={eje} etiquetas={et} resumen={`Faltas y retardos por ${d.agrupar} en ${d.ejercicio}`}
            series={[{ nombre: 'Faltas', color: SERIES[0], valores: s.map((x) => x.faltas) }, { nombre: 'Retardos', color: SERIES[1], valores: s.map((x) => x.retardos) }]}
            extra={(i) => <span className="tenue">{s[i].recibos} recibo(s)</span>} />
        </Tarjeta>
        <Tarjeta titulo="Domingos trabajados" nota="Cada domingo genera prima dominical (25 % del salario diario).">
          <Grafica ejeX={eje} etiquetas={et} resumen={`Domingos trabajados por ${d.agrupar}`} series={[{ nombre: 'Domingos', color: SERIES[0], valores: s.map((x) => x.domingos) }]}
            extra={montos ? (i) => <span>Prima dominical: <b>{dinero(s[i].prima_dominical)}</b></span> : undefined} />
        </Tarjeta>
        {montos && (
          <Tarjeta titulo="Bonos pagados" nota="Los bonos se pagan en el periodo que cierra el mes.">
            <Grafica ejeX={eje} etiquetas={et} apilado formato={pesos} resumen={`Bonos pagados por ${d.agrupar}`}
              series={d.bonos.slice(0, 4).map((b, k) => ({ nombre: b.nombre, color: SERIES[k], valores: s.map((x) => x.bonos[b.clave] ?? 0) }))} />
          </Tarjeta>
        )}
        <Tarjeta titulo="Altas y bajas" nota="Incluye reingresos. Rotación = bajas ÷ plantilla al cierre.">
          <Grafica ejeX={eje} etiquetas={et} resumen={`Altas y bajas por ${d.agrupar}`}
            series={[{ nombre: 'Altas', color: SERIES[0], valores: s.map((x) => x.altas) }, { nombre: 'Bajas', color: SERIES[1], valores: s.map((x) => x.bajas) }]}
            extra={(i) => <span>Rotación: <b>{numero(s[i].rotacion, 1)} %</b></span>} />
        </Tarjeta>
        <Tarjeta titulo="Plantilla al cierre" nota="Empleados contratados al último día de cada corte.">
          <Grafica tipo="linea" ejeX={eje} etiquetas={et} resumen={`Plantilla al cierre de cada ${d.agrupar}`} series={[{ nombre: 'Plantilla', color: SERIES[0], valores: s.map((x) => x.plantilla) }]} />
        </Tarjeta>
      </div>
    </>
  );
}

function Tarjeta({ titulo, nota, children }: { titulo: string; nota: string; children: ReactNode }) {
  return <div className="tarjeta tarjeta-grafica"><h2 className="seccion-titulo">{titulo}</h2><p className="tenue">{nota}</p>{children}</div>;
}

function TablaDatos({ d }: { d: IndicadoresRespuesta }) {
  return (
    <div className="tabla-scroll">
      <table className="tabla tabla-compacta">
        <thead><tr><th>{d.agrupar === 'mes' ? 'Mes' : 'Quincena'}</th><th className="num">Faltas</th><th className="num">Retardos</th><th className="num">Domingos</th>
          {d.bonos.map((b) => <th key={b.clave} className="num">{b.nombre}</th>)}<th className="num">Altas</th><th className="num">Bajas</th><th className="num">Plantilla</th><th className="num">Rotación</th></tr></thead>
        <tbody>{d.serie.map((x) => (
          <tr key={x.clave}><td>{x.etiqueta}</td><td className="num">{x.faltas}</td><td className="num">{x.retardos}</td><td className="num">{x.domingos}</td>
            {d.bonos.map((b) => <td key={b.clave} className="num">{dinero(x.bonos[b.clave] ?? 0)}</td>)}
            <td className="num">{x.altas}</td><td className="num">{x.bajas}</td><td className="num">{x.plantilla}</td><td className="num">{numero(x.rotacion, 1)} %</td></tr>
        ))}</tbody>
      </table>
    </div>
  );
}

function exportar(d: IndicadoresRespuesta, montos: boolean) {
  const bonos = montos ? d.bonos : [];
  descargarCSV(`indicadores-${d.ejercicio}-${d.agrupar}`, [
    [d.agrupar === 'mes' ? 'Mes' : 'Quincena', 'Faltas', 'Retardos', 'Domingos', ...bonos.map((b) => b.nombre), ...(montos ? ['Prima dominical'] : []), 'Altas', 'Bajas', 'Plantilla', 'Rotación %'],
    ...d.serie.map((x) => [x.etiqueta, x.faltas, x.retardos, x.domingos, ...bonos.map((b) => x.bonos[b.clave] ?? 0), ...(montos ? [x.prima_dominical] : []), x.altas, x.bajas, x.plantilla, x.rotacion]),
  ]);
}
