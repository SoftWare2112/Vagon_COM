import { useState } from 'react';
import { Aviso } from '../../components/Aviso';
import type { CalculoNomina, LineaNomina, Periodicidad } from '../../types';
import { dinero, numero, PERIODICIDADES } from '../../utils/formato';

/**
 * v12 — Cuerpo del recibo (percepciones, deducciones, neto y cómo se calculó el ISR y el IMSS).
 * Lo usan el simulador «¿Cuánto le toca?», la pre-nómina y el sobre-recibo del historial.
 */
export function ReciboDetalle({ calculo: r, tipo, cargando = false, abiertoDetalle = false }: {
  calculo: Omit<CalculoNomina, 'periodo'>; tipo: Periodicidad; cargando?: boolean; abiertoDetalle?: boolean;
}) {
  const [verDetalle, setVerDetalle] = useState(abiertoDetalle);
  return (
    <div className={`recibo ${cargando ? 'cargando' : ''}`}>
      <div className="recibo-columnas">
        <Columna titulo="Percepciones" lineas={r.percepciones} total={r.totales.percepciones} />
        <Columna titulo="Deducciones" lineas={r.deducciones} total={r.totales.deducciones} />
      </div>
      <div className="recibo-neto"><span>Neto a pagar</span><strong>{dinero(r.totales.neto)}</strong></div>
      {r.avisos.map((a) => <Aviso key={a} tipo="info">{a}</Aviso>)}
      <button className="btn-link no-imprimir" onClick={() => setVerDetalle(!verDetalle)}>{verDetalle ? 'Ocultar' : 'Ver'} cómo se calculó el ISR y el IMSS</button>
      {verDetalle && (
        <div className="recibo-detalle">
          <table className="tabla tabla-compacta">
            <caption>ISR (tarifa {PERIODICIDADES[tipo].toLowerCase()})</caption>
            <tbody>
              <tr><td>Base gravable</td><td className="num">{dinero(r.isr.base)}</td></tr>
              <tr><td>(−) Límite inferior</td><td className="num">{dinero(r.isr.limite_inferior)}</td></tr>
              <tr><td>(=) Excedente</td><td className="num">{dinero(r.isr.excedente)}</td></tr>
              <tr><td>(×) Tasa sobre excedente</td><td className="num">{numero(r.isr.porcentaje, 2)} %</td></tr>
              <tr><td>(=) Impuesto marginal</td><td className="num">{dinero(r.isr.impuesto_marginal)}</td></tr>
              <tr><td>(+) Cuota fija</td><td className="num">{dinero(r.isr.cuota_fija)}</td></tr>
              <tr><td>(=) ISR causado</td><td className="num">{dinero(r.isr.isr_causado)}</td></tr>
              <tr><td>(−) Subsidio para el empleo</td><td className="num">{dinero(r.isr.subsidio_aplicado)}</td></tr>
              <tr className="fila-total"><td>ISR retenido</td><td className="num">{dinero(r.isr.isr_retenido)}</td></tr>
            </tbody>
          </table>
          <table className="tabla tabla-compacta">
            <caption>IMSS · SBC {dinero(r.sbc)} diario</caption>
            <tbody>
              {r.imss.map((x) => <tr key={x.clave}><td>{x.nombre}<small className="tenue"> {numero(x.tasa, 3)} %</small></td><td className="num">{dinero(x.importe)}</td></tr>)}
              <tr className="fila-total"><td>Total cuota obrera</td><td className="num">{dinero(r.imss.reduce((s, x) => s + x.importe, 0))}</td></tr>
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

function Columna({ titulo, lineas, total }: { titulo: string; lineas: LineaNomina[]; total: number }) {
  return (
    <div className="recibo-col">
      <h3>{titulo}</h3>
      <ul>
        {lineas.map((l) => (
          <li key={l.clave}>
            <span>{l.nombre}{l.detalle && <small className="tenue">{l.detalle}</small>}</span>
            <span className="num">{dinero(l.importe)}</span>
          </li>
        ))}
        {lineas.length === 0 && <li className="tenue">—</li>}
      </ul>
      <p className="recibo-total"><span>Total</span><span className="num">{dinero(total)}</span></p>
    </div>
  );
}
