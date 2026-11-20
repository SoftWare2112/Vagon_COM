import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { vacacionesApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { useAuth } from '../../auth/AuthContext';
import { Aviso } from '../../components/Aviso';
import { Modal } from '../../components/Modal';
import type { Empleado, SaldoVacaciones } from '../../types';
import { fecha, hoyISO } from '../../utils/formato';

const ESTADO: Record<SaldoVacaciones['anios'][number]['estado'], { texto: string; clase: string }> = {
  vigente: { texto: 'Vigente', clase: 'etiqueta-ok' }, por_vencer: { texto: 'Por vencer', clase: 'etiqueta-mal' },
  vencido: { texto: 'Vencido', clase: 'etiqueta-neutro' }, agotado: { texto: 'Disfrutado', clase: 'etiqueta-neutro' },
};

/**
 * v13 — Vacaciones del empleado: saldo por año de servicio (art. 76 LFT) y días registrados.
 * La prima vacacional se paga sola en la nómina del periodo donde inician las vacaciones.
 */
export function VacacionesCard({ empleado }: { empleado: Empleado }) {
  const { puede } = useAuth();
  const qc = useQueryClient();
  const { data, isLoading } = useQuery({ queryKey: ['vacaciones', empleado.id], queryFn: () => vacacionesApi.obtener(empleado.id) });
  const [abierto, setAbierto] = useState(false);
  const [aviso, setAviso] = useState<string | null>(null);
  const gestiona = puede('empleados.gestionar') && empleado.estado === 'activo';
  if (isLoading || !data) return <div className="tarjeta"><h2 className="seccion-titulo">Vacaciones</h2><p>Cargando…</p></div>;
  const guardar = (d: SaldoVacaciones) => { qc.setQueryData(['vacaciones', empleado.id], d); void qc.invalidateQueries({ queryKey: ['captura'] }); };
  const cancelar = async (id: number) => {
    if (!confirm('¿Cancelar estas vacaciones? Los días regresan a su saldo.')) return;
    try { guardar(await vacacionesApi.cancelar(id)); } catch (e) { setAviso(mensajeDeError(e)); }
  };

  return (
    <div className="tarjeta vacaciones">
      <div className="seccion-cab">
        <div>
          <h2 className="seccion-titulo">Vacaciones</h2>
          <p className="tenue">{data.disponibles > 0 ? <><strong className="saldo-vacaciones">{data.disponibles} día(s) disponibles</strong></> : 'Sin días disponibles'} · su siguiente periodo: {data.proximo.derecho} días desde el {fecha(data.proximo.desde)}</p>
        </div>
        {gestiona && <button className="btn btn-sec btn-sm" disabled={data.disponibles === 0} onClick={() => setAbierto(true)}>Registrar vacaciones</button>}
      </div>
      {aviso && <Aviso tipo="error">{aviso}</Aviso>}
      {data.anios.length === 0
        ? <p className="tenue">Aún no cumple su primer año de servicio. La ley le da {data.proximo.derecho} días a partir del {fecha(data.proximo.desde)}.</p>
        : (
          <table className="tabla tabla-compacta">
            <thead><tr><th>Año de servicio</th><th>Desde</th><th className="num">Derecho</th><th className="num">Tomados</th><th className="num">Pendientes</th><th>Estado</th></tr></thead>
            <tbody>{data.anios.slice().reverse().map((a) => (
              <tr key={a.anio}><td>{a.anio}.º</td><td>{fecha(a.desde)}</td><td className="num">{a.derecho}</td><td className="num">{a.tomados}</td>
                <td className="num"><strong>{a.pendientes}</strong></td><td><span className={`etiqueta ${ESTADO[a.estado].clase}`}>{ESTADO[a.estado].texto}</span></td></tr>
            ))}</tbody>
          </table>
        )}
      {data.anios.some((a) => a.estado === 'por_vencer') && <p className="tenue">«Por vencer»: pasaron más de 6 meses desde su aniversario (art. 81 LFT pide disfrutarlas en ese plazo).</p>}
      {data.registros.length > 0 && (
        <ul className="lista-simple lista-vacaciones">
          {data.registros.map((v) => (
            <li key={v.id}>
              <span><strong>{fecha(v.desde)} al {fecha(v.hasta)}</strong> · {v.dias} día(s) del {v.anio_servicio}.º año<small className="tenue linea-sub">{v.prima_pagada ? 'Prima vacacional pagada' : 'La prima se paga en la nómina donde inician'}{v.notas ? ` · ${v.notas}` : ''}{v.por ? ` · ${v.por}` : ''}</small></span>
              {gestiona && !v.prima_pagada && <button className="btn-link texto-peligro" onClick={() => void cancelar(v.id)}>Cancelar</button>}
            </li>
          ))}
        </ul>
      )}
      {abierto && <DialogoVacaciones empleado={empleado} saldo={data} onCerrar={() => setAbierto(false)} onListo={(d) => { guardar(d); setAbierto(false); }} />}
    </div>
  );
}

function DialogoVacaciones({ empleado, saldo, onCerrar, onListo }: { empleado: Empleado; saldo: SaldoVacaciones; onCerrar: () => void; onListo: (d: SaldoVacaciones) => void }) {
  const [desde, setDesde] = useState(hoyISO());
  const [hasta, setHasta] = useState(hoyISO());
  const [dias, setDias] = useState(1);
  const [notas, setNotas] = useState('');
  const [error, setError] = useState<string | null>(null);
  // Sugerencia: días del rango que no son de descanso del empleado
  const sugeridos = (() => {
    if (!desde || !hasta || hasta < desde) return 0;
    let n = 0;
    for (let d = new Date(`${desde}T12:00:00`); d <= new Date(`${hasta}T12:00:00`); d.setDate(d.getDate() + 1)) {
      if (!(empleado.dias_descanso ?? []).includes(d.getDay())) n++;
    }
    return n;
  })();
  const guardar = async () => {
    setError(null);
    try { onListo(await vacacionesApi.registrar(empleado.id, { desde, hasta, dias, notas: notas || undefined })); } catch (e) { setError(mensajeDeError(e)); }
  };
  return (
    <Modal titulo="Registrar vacaciones" abierto onCerrar={onCerrar} ancho="sm"
      pie={<><button className="btn btn-sec" onClick={onCerrar}>Cancelar</button><button className="btn" disabled={dias < 1 || hasta < desde} onClick={() => void guardar()}>Registrar</button></>}>
      {error && <Aviso tipo="error">{error}</Aviso>}
      <p className="tenue">Disponibles: <strong>{saldo.disponibles} día(s)</strong>. Se descuentan del año de servicio más antiguo con saldo.</p>
      <div className="rejilla-form">
        <label>Desde<input type="date" value={desde} onChange={(e) => { setDesde(e.target.value); if (hasta < e.target.value) setHasta(e.target.value); }} /></label>
        <label>Hasta (último día de vacaciones)<input type="date" value={hasta} min={desde} onChange={(e) => setHasta(e.target.value)} /></label>
      </div>
      <label>Días hábiles que se descuentan<input type="number" min={1} max={60} value={dias} onChange={(e) => setDias(Number(e.target.value))} />
        {sugeridos > 0 && sugeridos !== dias && <small className="tenue">Sin contar sus días de descanso son {sugeridos}. <button type="button" className="btn-link" onClick={() => setDias(sugeridos)}>Usar {sugeridos}</button></small>}</label>
      <label>Notas<input value={notas} onChange={(e) => setNotas(e.target.value)} placeholder="Opcional" /></label>
      <Aviso tipo="info">Los días de vacaciones se pagan como sueldo normal: no los captures como faltas. La prima vacacional se paga en la nómina del periodo donde inician.</Aviso>
    </Modal>
  );
}
