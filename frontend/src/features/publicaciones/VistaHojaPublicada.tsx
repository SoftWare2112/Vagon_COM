import { useMemo, type ReactNode } from 'react';
import { ImagenSegura } from '../../components/ImagenSegura';
import type { HojaPublicada } from '../../types';
import { alineacion, mostrar } from '../hojas/motor/formato';
import { recalcular } from '../hojas/motor/libro';
import { letra } from '../hojas/motor/referencias';
import { valorDeCelda, type Celda, type Estilo } from '../hojas/motor/tipos';

const ALTO = 26;
const MAX_FILAS = 500;

/**
 * v10 — Hoja de un trabajo publicado, en solo lectura. Se recalculan las fórmulas con el mismo motor
 * del editor, así se ven los resultados aunque la hoja nunca se haya abierto después de crearla.
 */
export function VistaHojaPublicada({ hoja }: { hoja: HojaPublicada }) {
  const datos = useMemo(() => {
    const filaIds = hoja.filas.map((f) => f.id);
    const colIds = hoja.columnas.map((c) => c.id);
    const posF = new Map(filaIds.map((id, i) => [id, i]));
    const posC = new Map(colIds.map((id, i) => [id, i]));
    const mapa = new Map<string, Celda>(hoja.celdas.map((c) => [`${c.f}:${c.c}`, c]));
    const { valores } = recalcular(mapa, { filas: filaIds, columnas: colIds });

    // Hasta dónde hay contenido (no se dibujan cientos de filas vacías)
    let maxF = 0, maxC = 0;
    for (const c of hoja.celdas) { maxF = Math.max(maxF, posF.get(c.f) ?? 0); maxC = Math.max(maxC, posC.get(c.c) ?? 0); }
    const combinadas = hoja.combinadas.flatMap((m) => {
      const r1 = posF.get(m.f), c1 = posC.get(m.c), r2 = posF.get(m.f2), c2 = posC.get(m.c2);
      if (r1 === undefined || c1 === undefined || r2 === undefined || c2 === undefined) return [];
      maxF = Math.max(maxF, r2); maxC = Math.max(maxC, c2);
      return [{ r1, c1, r2, c2 }];
    });
    const imagenes = hoja.imagenes.flatMap((img) => {
      const r = posF.get(img.f), c = posC.get(img.c);
      if (r === undefined || c === undefined) return [];
      maxF = Math.max(maxF, r + Math.ceil((img.y + img.h) / ALTO)); maxC = Math.max(maxC, c);
      return [{ ...img, r, c }];
    });
    const nFilas = Math.min(MAX_FILAS, Math.min(filaIds.length, maxF + 2));
    const nCols = Math.min(colIds.length, maxC + 2);
    const anclas = new Map<string, { filas: number; cols: number }>();
    const cubiertas = new Set<string>();
    for (const m of combinadas) {
      anclas.set(`${m.r1}:${m.c1}`, { filas: m.r2 - m.r1 + 1, cols: m.c2 - m.c1 + 1 });
      for (let r = m.r1; r <= m.r2; r++) for (let c = m.c1; c <= m.c2; c++) if (r !== m.r1 || c !== m.c1) cubiertas.add(`${r}:${c}`);
    }
    const izquierdas = [0];
    hoja.columnas.slice(0, nCols).forEach((c) => izquierdas.push(izquierdas[izquierdas.length - 1] + c.ancho));
    return { filaIds, colIds, mapa, valores, nFilas, nCols, anclas, cubiertas, imagenes, izquierdas, recortada: maxF + 2 > MAX_FILAS };
  }, [hoja]);

  const filas: ReactNode[] = [];
  for (let r = 0; r < datos.nFilas; r++) {
    const celdas: ReactNode[] = [<th key="n" className="vh-num">{r + 1}</th>];
    for (let c = 0; c < datos.nCols; c++) {
      const pos = `${r}:${c}`;
      if (datos.cubiertas.has(pos)) continue;
      const k = `${datos.filaIds[r]}:${datos.colIds[c]}`;
      const celda = datos.mapa.get(k);
      const est: Estilo = (celda?.e ? hoja.estilos[celda.e] : undefined) ?? {};
      const v = celda?.t === 'formula' ? datos.valores.get(k) ?? valorDeCelda(celda) : valorDeCelda(celda);
      const span = datos.anclas.get(pos);
      celdas.push(
        <td key={c} rowSpan={span?.filas} colSpan={span?.cols} className={v.t === 'error' ? 'es-error' : ''}
          style={{ textAlign: ({ izq: 'left', centro: 'center', der: 'right' } as const)[alineacion(v, est)],
            fontWeight: est.n ? 700 : undefined, fontStyle: est.i ? 'italic' : undefined, textDecoration: est.s ? 'underline' : undefined,
            color: est.c, background: est.f, whiteSpace: est.w ? 'normal' : 'nowrap' }}>
          {mostrar(v, est.fmt)}
        </td>,
      );
    }
    filas.push(<tr key={r}>{celdas}</tr>);
  }

  return (
    <div className="vista-hoja">
      <div className="vista-hoja-lienzo">
        <table className="vista-hoja-tabla">
          <colgroup><col style={{ width: 44 }} />{hoja.columnas.slice(0, datos.nCols).map((c) => <col key={c.id} style={{ width: c.ancho }} />)}</colgroup>
          <thead><tr><th className="vh-num" />{hoja.columnas.slice(0, datos.nCols).map((c, i) => <th key={c.id}>{letra(i)}</th>)}</tr></thead>
          <tbody>{filas}</tbody>
        </table>
        {datos.imagenes.filter((img) => img.r < datos.nFilas).map((img) => (
          <div key={img.id} className="vista-hoja-imagen" style={{ left: 44 + datos.izquierdas[img.c] + img.x, top: ALTO * (img.r + 1) + img.y, width: img.w, height: img.h }}>
            <ImagenSegura url={img.url} alt={img.nombre ?? 'Imagen'} />
          </div>
        ))}
      </div>
      {datos.recortada && <p className="tenue">Se muestran las primeras {MAX_FILAS} filas. Haz una copia en tu espacio para ver la hoja completa.</p>}
    </div>
  );
}
