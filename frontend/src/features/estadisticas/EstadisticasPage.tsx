import { useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { catalogosApi, estadisticasApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { Aviso } from '../../components/Aviso';
import { dinero } from '../../utils/formato';
import { BarrasAgrupadas, BarrasHorizontales, COLORES } from './Graficas';

const entero = (n: number) => n.toLocaleString('es-MX', { maximumFractionDigits: 1 });

/**
 * v6 — Gráficas: lo que RH necesita ver de un vistazo. Son conteos y sumas de lo cargado
 * (no cambian ningún dato). El selector de quincena va arriba y aplica a todo lo de abajo.
 */
export function EstadisticasPage() {
  const [periodoId, setPeriodoId] = useState<number | undefined>(undefined);
  const { data: periodos = [] } = useQuery({ queryKey: ['periodos'], queryFn: catalogosApi.periodos });
  const { data, isLoading, isError, error, isFetching } = useQuery({
    queryKey: ['estadisticas', periodoId],
    queryFn: () => estadisticasApi.obtener(periodoId),
    placeholderData: keepPreviousData,
  });

  if (isLoading) return <p>Cargando gráficas…</p>;
  if (isError || !data) return <Aviso tipo="error">{mensajeDeError(error)}</Aviso>;
  if (!data.periodo || !data.kpis) return <Aviso tipo="info">Aún no hay quincenas cargadas. Las gráficas aparecen después de la primera carga.</Aviso>;

  const s = data.serie;
  const cortos = s.map((p) => p.corto);
  const nombres = s.map((p) => p.nombre);
  const k = data.kpis;

  return (
    <div className="pagina-graficas" style={{ opacity: isFetching ? 0.6 : 1 }}>
      <header className="cabecera-pagina">
        <div>
          <h1>Gráficas</h1>
          <p className="tenue">{data.periodo.nombre} · últimas {s.length} quincena(s)</p>
        </div>
        {periodos.length > 0 && (
          <label>Quincena{' '}
            <select value={periodoId ?? data.periodo.id} onChange={(e) => setPeriodoId(Number(e.target.value))} style={{ width: 'auto' }}>
              {periodos.map((p) => <option key={p.id} value={p.id}>{p.etiqueta}</option>)}
            </select>
          </label>
        )}
      </header>

      <section className="indicadores" aria-label="Indicadores de la quincena">
        <Indicador etiqueta="Empleados en la quincena" valor={entero(k.empleados)} />
        <Indicador etiqueta="Total de faltas" valor={entero(k.faltas_total)} />
        <Indicador etiqueta="Empleados con al menos una falta" valor={`${entero(k.pct_con_faltas)}%`} nota={`${k.con_faltas} de ${k.empleados}`} />
        <Indicador etiqueta="Monto de bonos" valor={dinero(k.monto_bonos)} nota="3 meses + puntualidad + asistencia" />
        <Indicador etiqueta="Ingresos en la quincena" valor={entero(k.nuevos)} nota="fecha de ingreso dentro del periodo" />
      </section>

      <div className="rejilla-graficas">
        <Tarjeta titulo="Faltas por quincena" subtitulo="Total de faltas registradas en cada quincena">
          <BarrasAgrupadas categorias={cortos} detalles={nombres} formato={entero} etiquetarUltimo
            series={[{ nombre: 'Faltas', color: COLORES[0], valores: s.map((p) => p.faltas_total) }]}
            extra={(i) => `${s[i].con_faltas} empleado(s) con faltas (${entero(s[i].pct_con_faltas)}%)`} />
        </Tarjeta>

        <Tarjeta titulo={`Empleados con más faltas en ${data.periodo.ejercicio}`} subtitulo="Suma de faltas del año; los 10 primeros">
          <BarrasHorizontales encabezados={['Empleado', 'Faltas']} formato={entero}
            items={data.top_faltas.map((t) => ({
              etiqueta: t.nombre, valor: t.faltas,
              detalle: `${t.nombre} (${t.numero_empleado}) · ${t.faltas} falta(s) en ${t.quincenas} quincena(s)`,
            }))} />
        </Tarjeta>

        <Tarjeta titulo="Bonos por quincena" subtitulo="Monto pagado de cada concepto">
          <BarrasAgrupadas categorias={cortos} detalles={nombres} formato={dinero}
            series={[
              { nombre: 'Prima vacacional', color: COLORES[0], valores: s.map((p) => p.prima_vacacional) },
              { nombre: 'Bono 3 meses', color: COLORES[1], valores: s.map((p) => p.bono_3_meses) },
              { nombre: 'Bono puntualidad', color: COLORES[2], valores: s.map((p) => p.bono_puntualidad) },
              { nombre: 'Bono asistencia', color: COLORES[3], valores: s.map((p) => p.bono_asistencia) },
            ]}
            extra={(i) => `${s[i].acreditan_bono} empleado(s) acreditan bono de puntualidad`} />
        </Tarjeta>

        <Tarjeta titulo="Domingos" subtitulo="Programados contra trabajados en cada quincena">
          <BarrasAgrupadas categorias={cortos} detalles={nombres} formato={entero}
            series={[
              { nombre: 'Programados', color: COLORES[0], valores: s.map((p) => p.domingos_programados) },
              { nombre: 'Trabajados', color: COLORES[1], valores: s.map((p) => p.domingos_trabajados) },
            ]} />
        </Tarjeta>

        <Tarjeta titulo="Plantilla por puesto" subtitulo={`Empleados en ${data.periodo.nombre}`}>
          <BarrasHorizontales encabezados={['Puesto', 'Empleados']} formato={entero}
            items={data.por_puesto.map((p) => ({ etiqueta: p.puesto, valor: p.empleados }))} />
        </Tarjeta>

        <Tarjeta titulo="Ingresos por quincena" subtitulo="Empleados cuya fecha de ingreso cae dentro de la quincena">
          <BarrasAgrupadas categorias={cortos} detalles={nombres} formato={entero} etiquetarUltimo
            series={[{ nombre: 'Ingresos', color: COLORES[0], valores: s.map((p) => p.nuevos) }]} />
        </Tarjeta>
      </div>
    </div>
  );
}

function Indicador({ etiqueta, valor, nota }: { etiqueta: string; valor: string; nota?: string }) {
  return (
    <div className="indicador">
      <span className="indicador-etiqueta">{etiqueta}</span>
      <strong className="indicador-valor">{valor}</strong>
      {nota && <span className="indicador-nota">{nota}</span>}
    </div>
  );
}

function Tarjeta({ titulo, subtitulo, children }: { titulo: string; subtitulo: string; children: React.ReactNode }) {
  return (
    <section className="tarjeta-grafica">
      <h2>{titulo}</h2>
      <p className="tenue">{subtitulo}</p>
      {children}
    </section>
  );
}
