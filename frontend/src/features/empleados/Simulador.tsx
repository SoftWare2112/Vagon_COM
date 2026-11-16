import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { empleadosApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { Aviso } from '../../components/Aviso';
import type { CalculoNomina, Empleado } from '../../types';
import { ReciboDetalle } from '../nomina/ReciboDetalle';
import { fecha, hoyISO, PERIODICIDADES } from '../../utils/formato';

/**
 * v11 — «¿Cuánto le toca?»: calcula el recibo de un periodo con las reglas y tablas vigentes.
 * Es solo una simulación (no guarda nada); la nómina real con captura por periodo llega en la v12.
 */
export function Simulador({ empleado }: { empleado: Empleado }) {
  const [p, setP] = useState({ fecha: hoyISO(), faltas: 0, retardos: 0, domingos: 0 });
  const { data: r, isFetching, isError, error } = useQuery({
    queryKey: ['simulacion', empleado.id, p], queryFn: () => empleadosApi.simular(empleado.id, p),
    enabled: empleado.estado === 'activo' && !!empleado.salario_mensual, placeholderData: (prev: CalculoNomina | undefined) => prev,
  });
  if (empleado.estado !== 'activo' || !empleado.salario_mensual) return null;
  const num = (k: 'faltas' | 'retardos' | 'domingos', max: number) => (
    <label>{{ faltas: 'Faltas', retardos: 'Retardos', domingos: 'Domingos trabajados' }[k]}
      <input type="number" min={0} max={max} value={p[k]} onChange={(e) => setP({ ...p, [k]: Math.max(0, Math.min(max, Number(e.target.value) || 0)) })} />
    </label>
  );
  return (
    <div className="tarjeta simulador">
      <div className="seccion-cab">
        <div>
          <h2 className="seccion-titulo">¿Cuánto le toca?</h2>
          <p className="tenue">Simulación del recibo {PERIODICIDADES[empleado.periodicidad].toLowerCase()} con las tablas fiscales vigentes. No se guarda.</p>
        </div>
      </div>
      <div className="simulador-entradas">
        <label>Periodo que incluye el día<input type="date" value={p.fecha} onChange={(e) => setP({ ...p, fecha: e.target.value || hoyISO() })} /></label>
        {num('faltas', 31)}{num('retardos', 31)}{num('domingos', 5)}
      </div>
      {isError && <Aviso tipo="error">{mensajeDeError(error)}</Aviso>}
      {r && (
        <div>
          <p className="recibo-periodo">
            <strong>Periodo {r.periodo.numero}</strong> · {fecha(r.periodo.inicio)} al {fecha(r.periodo.fin)} · {r.dias_pagados} de {r.dias_periodo} días pagados
            {r.periodo.fin_de_mes && <span className="etiqueta etiqueta-info">Cierra el mes</span>}
          </p>
          <ReciboDetalle calculo={r} tipo={r.periodo.tipo} cargando={isFetching} />
        </div>
      )}
    </div>
  );
}
