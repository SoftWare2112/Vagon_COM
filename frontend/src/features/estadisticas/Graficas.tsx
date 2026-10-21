import { useState } from 'react';

/**
 * Gráficas SVG ligeras (v6), sin librerías externas. Reglas de diseño:
 * barras de máximo 24px con punta redondeada de 4px y base recta, 2px de separación entre
 * barras vecinas, rejilla de 1px tenue, leyenda siempre que haya 2+ series, textos en tinta
 * (nunca del color de la serie), tooltip al pasar el mouse o con el teclado, y tabla de datos
 * para quien no distingue colores o no usa mouse.
 */

export const COLORES = ['var(--serie-1)', 'var(--serie-2)', 'var(--serie-3)', 'var(--serie-4)'];

export interface Serie {
  nombre: string;
  color: string;
  valores: number[];
}

const W = 640;
const H = 240;
const M = { izq: 52, der: 12, arr: 14, aba: 30 };

/** Máximo "redondo" y 4 divisiones: 0 / 5 / 10 / 15 / 20 */
function escala(max: number): number[] {
  if (max <= 0) return [0, 1];
  const paso0 = max / 4;
  const mag = 10 ** Math.floor(Math.log10(paso0));
  const paso = [1, 2, 2.5, 5, 10].map((m) => m * mag).find((p) => p >= paso0) ?? 10 * mag;
  return Array.from({ length: Math.ceil(max / paso) + 1 }, (_, i) => +(i * paso).toFixed(6));
}

/** Barra vertical con la punta redondeada (4px) y la base recta en el eje. */
function barra(x: number, y: number, ancho: number, alto: number): string {
  if (alto <= 0) return '';
  const r = Math.min(4, ancho / 2, alto);
  return `M${x},${y + alto}V${y + r}Q${x},${y} ${x + r},${y}H${x + ancho - r}Q${x + ancho},${y} ${x + ancho},${y + r}V${y + alto}Z`;
}

const compacto = (n: number) => (Math.abs(n) >= 1000 ? `${+(n / 1000).toFixed(1)}K` : String(+n.toFixed(1)));

/** Columnas por quincena (una o varias series agrupadas). */
export function BarrasAgrupadas({ categorias, detalles, series, formato, etiquetarUltimo = false, extra }: {
  categorias: string[];
  detalles?: string[];
  series: Serie[];
  formato: (n: number) => string;
  etiquetarUltimo?: boolean;
  extra?: (i: number) => string | null;
}) {
  const [activo, setActivo] = useState<number | null>(null);
  const n = categorias.length;
  const ticks = escala(Math.max(0, ...series.flatMap((s) => s.valores)));
  const max = ticks[ticks.length - 1];
  const anchoPlot = W - M.izq - M.der;
  const altoPlot = H - M.arr - M.aba;
  const banda = anchoPlot / Math.max(n, 1);
  const k = series.length;
  const anchoBarra = Math.max(4, Math.min(24, (banda * 0.7 - 2 * (k - 1)) / k));
  const anchoGrupo = k * anchoBarra + 2 * (k - 1);
  const y = (v: number) => M.arr + altoPlot - (v / max) * altoPlot;

  if (n === 0) return <p className="tenue">Sin datos todavía.</p>;

  return (
    <div className="grafica">
      {k > 1 && (
        <ul className="leyenda">
          {series.map((s) => <li key={s.nombre}><span className="muestra" style={{ background: s.color }} />{s.nombre}</li>)}
        </ul>
      )}
      <div className="grafica-lienzo">
        <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label={series.map((s) => s.nombre).join(', ')}>
          {ticks.map((t) => (
            <g key={t}>
              <line x1={M.izq} x2={W - M.der} y1={y(t)} y2={y(t)} className="rejilla" />
              <text x={M.izq - 6} y={y(t) + 4} textAnchor="end" className="eje">{compacto(t)}</text>
            </g>
          ))}
          {categorias.map((c, i) => {
            const x0 = M.izq + banda * i + (banda - anchoGrupo) / 2;
            return (
              <g key={i} opacity={activo === null || activo === i ? 1 : 0.45}>
                {series.map((s, j) => {
                  const v = s.valores[i] ?? 0;
                  return <path key={j} d={barra(x0 + j * (anchoBarra + 2), y(v), anchoBarra, y(0) - y(v))} fill={s.color} />;
                })}
                {(n <= 12 || i % Math.ceil(n / 12) === 0) && (
                  <text x={M.izq + banda * (i + 0.5)} y={H - 10} textAnchor="middle" className="eje">{c}</text>
                )}
                {etiquetarUltimo && k === 1 && i === n - 1 && (
                  <text x={x0 + anchoBarra / 2} y={y(series[0].valores[i] ?? 0) - 6} textAnchor="middle" className="valor">{formato(series[0].valores[i] ?? 0)}</text>
                )}
                {/* zona de contacto: toda la columna, más grande que la barra */}
                <rect x={M.izq + banda * i} y={M.arr} width={banda} height={altoPlot} fill="transparent" tabIndex={0}
                  aria-label={`${detalles?.[i] ?? c}: ${series.map((s) => `${s.nombre} ${formato(s.valores[i] ?? 0)}`).join(', ')}`}
                  onPointerEnter={() => setActivo(i)} onPointerLeave={() => setActivo(null)}
                  onFocus={() => setActivo(i)} onBlur={() => setActivo(null)} />
              </g>
            );
          })}
          <line x1={M.izq} x2={W - M.der} y1={y(0)} y2={y(0)} className="base" />
        </svg>
        {activo !== null && (
          <div className="tooltip" style={{ left: `${((M.izq + banda * (activo + 0.5)) / W) * 100}%` }}>
            <div className="tooltip-titulo">{detalles?.[activo] ?? categorias[activo]}</div>
            {series.map((s) => (
              <div key={s.nombre} className="tooltip-fila">
                <span className="clave-linea" style={{ background: s.color }} />
                <b>{formato(s.valores[activo] ?? 0)}</b> <span className="tenue">{s.nombre}</span>
              </div>
            ))}
            {extra?.(activo) && <div className="tenue">{extra(activo)}</div>}
          </div>
        )}
      </div>
      <TablaDatos encabezados={['Quincena', ...series.map((s) => s.nombre)]}
        filas={categorias.map((c, i) => [detalles?.[i] ?? c, ...series.map((s) => formato(s.valores[i] ?? 0))])} />
    </div>
  );
}

/** Ranking horizontal (empleados con más faltas, plantilla por puesto). */
export function BarrasHorizontales({ items, formato, color = COLORES[0], encabezados }: {
  items: { etiqueta: string; valor: number; detalle?: string }[];
  formato: (n: number) => string;
  color?: string;
  encabezados: [string, string];
}) {
  const [activo, setActivo] = useState<number | null>(null);
  if (items.length === 0) return <p className="tenue">Sin datos todavía.</p>;

  const fila = 30;
  const izq = 190;
  const der = 56;
  const alto = items.length * fila + 8;
  const max = Math.max(...items.map((i) => i.valor), 1);
  const largo = (v: number) => ((W - izq - der) * v) / max;

  return (
    <div className="grafica">
      <svg viewBox={`0 0 ${W} ${alto}`} role="img" aria-label={encabezados.join(' y ')}>
        {items.map((it, i) => {
          const yb = 4 + i * fila + (fila - 18) / 2;
          const l = largo(it.valor);
          const r = Math.min(4, l / 2);
          return (
            <g key={i} opacity={activo === null || activo === i ? 1 : 0.45}
              onPointerEnter={() => setActivo(i)} onPointerLeave={() => setActivo(null)}>
              <title>{it.detalle ?? `${it.etiqueta}: ${formato(it.valor)}`}</title>
              <text x={izq - 8} y={yb + 13} textAnchor="end" className="eje-nombre">
                {it.etiqueta.length > 22 ? `${it.etiqueta.slice(0, 21)}…` : it.etiqueta}
              </text>
              {l > 0 && <path d={`M${izq},${yb}H${izq + l - r}Q${izq + l},${yb} ${izq + l},${yb + r}V${yb + 18 - r}Q${izq + l},${yb + 18} ${izq + l - r},${yb + 18}H${izq}Z`} fill={color} />}
              <text x={izq + l + 6} y={yb + 13} className="valor">{formato(it.valor)}</text>
              <rect x={0} y={4 + i * fila} width={W} height={fila} fill="transparent" />
            </g>
          );
        })}
        <line x1={izq} x2={izq} y1={0} y2={alto} className="base" />
      </svg>
      <TablaDatos encabezados={encabezados} filas={items.map((i) => [i.detalle ?? i.etiqueta, formato(i.valor)])} />
    </div>
  );
}

function TablaDatos({ encabezados, filas }: { encabezados: string[]; filas: (string | number)[][] }) {
  return (
    <details className="tabla-datos">
      <summary>Ver datos en tabla</summary>
      <div className="tabla-scroll">
        <table className="tabla tabla-compacta">
          <thead><tr>{encabezados.map((h) => <th key={h}>{h}</th>)}</tr></thead>
          <tbody>{filas.map((f, i) => <tr key={i}>{f.map((v, j) => <td key={j}>{v}</td>)}</tr>)}</tbody>
        </table>
      </div>
    </details>
  );
}
