import { useState, type ReactNode } from 'react';

/**
 * v12 — Gráficas ligeras en SVG (sin librerías): barras (agrupadas o apiladas) y línea.
 * Reglas de la guía de visualización: un solo eje, color por serie en orden fijo (--serie-1, --serie-2…),
 * marcas delgadas con extremo redondeado, leyenda cuando hay 2+ series, cuadrícula tenue y tooltip al pasar el cursor.
 */
export interface Serie { nombre: string; color: string; valores: number[] }

interface Props {
  etiquetas: string[];
  series: Serie[];
  apilado?: boolean;
  formato?: (n: number) => string;
  extra?: (i: number) => ReactNode;   // líneas adicionales del tooltip
  tipo?: 'barras' | 'linea';
  ejeX?: string[];                    // texto bajo cada barra (vacío = sin texto); por defecto, las etiquetas
  alto?: number;
  resumen: string;                    // descripción para lectores de pantalla
}

const W = 520, M = { izq: 40, der: 8, arr: 10, aba: 26 };

function escala(max: number): number[] {
  if (max <= 0) return [0, 1];
  const paso0 = max / 4;
  const mag = 10 ** Math.floor(Math.log10(paso0));
  const paso = [1, 2, 2.5, 5, 10].map((m) => m * mag).find((p) => p >= paso0) ?? 10 * mag;
  return Array.from({ length: Math.ceil(max / paso) + 1 }, (_, i) => i * paso);
}

export function Grafica({ etiquetas, series, apilado = false, formato = (n) => String(n), extra, tipo = 'barras', alto = 200, resumen, ejeX }: Props) {
  const [hover, setHover] = useState<number | null>(null);
  const n = etiquetas.length;
  const totales = etiquetas.map((_, i) => series.reduce((s, x) => s + (x.valores[i] ?? 0), 0));
  const max = apilado ? Math.max(0, ...totales) : Math.max(0, ...series.flatMap((s) => s.valores));
  const ticks = escala(max);
  const tope = ticks.at(-1) ?? 1;
  const H = alto, ancho = W - M.izq - M.der, altoUtil = H - M.arr - M.aba;
  const banda = ancho / Math.max(1, n);
  const x0 = (i: number) => M.izq + i * banda;
  const y = (v: number) => M.arr + altoUtil - (v / tope) * altoUtil;
  const grosor = Math.min(28, (banda - 6) / (apilado || tipo === 'linea' ? 1 : series.length));

  return (
    <div className="grafica" onMouseLeave={() => setHover(null)}>
      {series.length > 1 && (
        <ul className="grafica-leyenda">
          {series.map((s) => <li key={s.nombre}><span className="grafica-muestra" style={{ background: s.color }} />{s.nombre}</li>)}
        </ul>
      )}
      <div className="grafica-lienzo">
        <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label={resumen}>
          {ticks.map((t) => (
            <g key={t}>
              <line x1={M.izq} x2={W - M.der} y1={y(t)} y2={y(t)} className={t === 0 ? 'grafica-base' : 'grafica-rejilla'} />
              <text x={M.izq - 6} y={y(t) + 4} textAnchor="end" className="grafica-eje">{formato(t)}</text>
            </g>
          ))}
          {hover !== null && <rect x={x0(hover)} y={M.arr} width={banda} height={altoUtil} className="grafica-foco" />}
          {tipo === 'barras' && etiquetas.map((_, i) => {
            let acumulado = 0;
            return series.map((s, k) => {
              const v = s.valores[i] ?? 0;
              if (v <= 0) return null;
              const bx = apilado ? x0(i) + (banda - grosor) / 2 : x0(i) + (banda - grosor * series.length) / 2 + k * grosor;
              const base = apilado ? acumulado : 0;
              acumulado += v;
              const yt = y(base + v), yb = y(base);
              const h = Math.max(1, yb - yt);
              const ultimo = !apilado || series.slice(k + 1).every((o) => (o.valores[i] ?? 0) <= 0);
              const r = Math.min(4, grosor / 2, h);
              const ancho_ = Math.max(1, grosor - (apilado ? 0 : 2));
              // Extremo de datos redondeado (arriba) y anclado a la base; separación de 2px entre segmentos apilados
              const d = ultimo
                ? `M${bx},${yb} V${yt + r} Q${bx},${yt} ${bx + r},${yt} H${bx + ancho_ - r} Q${bx + ancho_},${yt} ${bx + ancho_},${yt + r} V${yb} Z`
                : `M${bx},${yb} V${yt + 1} H${bx + ancho_} V${yb} Z`;
              return <path key={`${i}-${k}`} d={d} fill={s.color} />;
            });
          })}
          {tipo === 'linea' && series.map((s) => (
            <g key={s.nombre}>
              <polyline fill="none" stroke={s.color} strokeWidth={2} points={s.valores.map((v, i) => `${x0(i) + banda / 2},${y(v)}`).join(' ')} />
              {hover !== null && <circle cx={x0(hover) + banda / 2} cy={y(s.valores[hover] ?? 0)} r={4.5} fill={s.color} stroke="var(--surface)" strokeWidth={2} />}
            </g>
          ))}
          {(ejeX ?? etiquetas).map((e, i) => e && (
            <text key={e + i} x={x0(i) + (ejeX ? banda : banda / 2)} y={H - 8} textAnchor="middle" className="grafica-eje">{e}</text>
          ))}
          {etiquetas.map((_, i) => (
            <rect key={`h${i}`} x={x0(i)} y={0} width={banda} height={H} fill="transparent" onMouseEnter={() => setHover(i)} onClick={() => setHover(i)} />
          ))}
        </svg>
        {hover !== null && (
          <div className="grafica-tooltip" style={{ left: `${((x0(hover) + banda / 2) / W) * 100}%` }} role="status">
            <strong>{etiquetas[hover]}</strong>
            {series.map((s) => <span key={s.nombre}><i className="grafica-muestra" style={{ background: s.color }} />{s.nombre}: <b>{formato(s.valores[hover] ?? 0)}</b></span>)}
            {apilado && series.length > 1 && <span>Total: <b>{formato(totales[hover])}</b></span>}
            {extra?.(hover)}
          </div>
        )}
      </div>
    </div>
  );
}
