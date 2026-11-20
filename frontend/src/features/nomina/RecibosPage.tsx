import { useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';
import { empleadosApi, nominaPeriodoApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { Aviso } from '../../components/Aviso';
import { dinero, fecha } from '../../utils/formato';
import { AccionesRecibo, SobreRecibo } from './piezas';

/** v12 — Sobre-recibo de periodos anteriores: se elige el empleado y el ejercicio/periodo para ver exactamente qué se le pagó. */
export function RecibosPage() {
  const [params, setParams] = useSearchParams();
  const empleadoId = Number(params.get('empleado')) || undefined;
  const reciboId = Number(params.get('recibo')) || undefined;
  const { data: empleados = [] } = useQuery({ queryKey: ['empleados', { estado: 'todos' }], queryFn: () => empleadosApi.listar({ estado: 'todos' }) });
  const { data: recibos = [], isLoading } = useQuery({ queryKey: ['recibos', empleadoId], queryFn: () => nominaPeriodoApi.recibos(empleadoId!), enabled: !!empleadoId });
  const { data: recibo, isError, error } = useQuery({ queryKey: ['recibo', reciboId], queryFn: () => nominaPeriodoApi.recibo(reciboId!), enabled: !!reciboId });
  useEffect(() => {
    if (empleadoId && !reciboId && recibos.length) setParams({ empleado: String(empleadoId), recibo: String(recibos[0].id) }, { replace: true });
  }, [recibos]); // eslint-disable-line react-hooks/exhaustive-deps

  const ejercicios = [...new Set(recibos.map((r) => r.ejercicio))];
  const elegido = recibos.find((r) => r.id === reciboId);
  return (
    <section>
      <div className="encabezado-pagina no-imprimir">
        <div>
          <h1>Recibos de nómina</h1>
          <p className="tenue">Consulta qué se pagó a cada empleado en cualquier periodo autorizado.</p>
        </div>
        <Link className="btn btn-sec" to="/nomina/historial">Historial</Link>
      </div>
      <div className="historial-barra no-imprimir">
        <select aria-label="Empleado" value={empleadoId ?? ''} onChange={(e) => setParams(e.target.value ? { empleado: e.target.value } : {})}>
          <option value="">Elige un empleado…</option>
          {empleados.map((e) => <option key={e.id} value={e.id}>{e.nombre} · {e.numero_empleado}{e.estado === 'baja' ? ' (baja)' : ''}</option>)}
        </select>
        {recibos.length > 0 && <>
          <select aria-label="Ejercicio" value={elegido?.ejercicio ?? ''} onChange={(e) => {
            const r = recibos.find((x) => x.ejercicio === Number(e.target.value)); if (r) setParams({ empleado: String(empleadoId), recibo: String(r.id) });
          }}>{ejercicios.map((a) => <option key={a}>{a}</option>)}</select>
          <select aria-label="Periodo" className="select-periodo" value={reciboId ?? ''} onChange={(e) => setParams({ empleado: String(empleadoId), recibo: e.target.value })}>
            {recibos.filter((r) => r.ejercicio === (elegido?.ejercicio ?? ejercicios[0])).map((r) => (
              <option key={r.id} value={r.id}>{r.tipo === 'especial' ? 'Aguinaldo' : String(r.numero).padStart(2, '0')} · pago {fecha(r.fecha_pago)} · {dinero(r.neto)}</option>))}
          </select>
          {recibo && <AccionesRecibo r={recibo} />}
        </>}
      </div>
      {!empleadoId && <div className="vacio">Elige un empleado para ver sus recibos.</div>}
      {empleadoId && !isLoading && recibos.length === 0 && <div className="vacio">Este empleado aún no tiene nóminas autorizadas.</div>}
      {isError && <Aviso tipo="error">{mensajeDeError(error)}</Aviso>}
      {recibo && <div className="tarjeta"><SobreRecibo r={recibo} /></div>}
    </section>
  );
}
