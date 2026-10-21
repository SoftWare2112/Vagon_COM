import { useEffect, useRef } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useSearchParams } from 'react-router-dom';
import { registrosApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import type { Filtros } from '../../types';

/**
 * v6 — Botón "Imprimir": TODA la tabla (no solo la página visible), con los mismos filtros y
 * la estructura del archivo de RH (bloque Tipo Periodo / Ejercicio / Periodo + título + encabezados
 * originales). Se abre en otra pestaña y lanza el cuadro de impresión del navegador.
 */
export function ImprimirPage() {
  const [params] = useSearchParams();
  const filtros = Object.fromEntries(params.entries()) as unknown as Filtros;
  const lanzado = useRef(false);

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['impresion', params.toString()],
    queryFn: () => registrosApi.impresion(filtros),
  });

  useEffect(() => {
    if (!data || lanzado.current) return;
    lanzado.current = true;
    document.title = `${data.encabezado.titulo} · ${data.encabezado.periodo}`;
    const t = window.setTimeout(() => window.print(), 300);   // deja pintar la tabla antes
    return () => window.clearTimeout(t);
  }, [data]);

  if (isLoading) return <p className="impresion-estado">Preparando la impresión…</p>;
  if (isError || !data) return <p className="impresion-estado">No se pudo preparar la impresión: {mensajeDeError(error)}</p>;

  const e = data.encabezado;
  return (
    <div className="impresion">
      <div className="impresion-acciones no-imprimir">
        <button className="btn" onClick={() => window.print()}>Imprimir</button>
        <button className="btn btn-sec" onClick={() => window.close()}>Cerrar</button>
        <span className="tenue">{data.filas.length} registros · hoja horizontal</span>
      </div>

      <div className="impresion-cabecera">
        <table className="impresion-bloque">
          <tbody>
            <tr><th>Tipo Periodo:</th><td>{e.tipo ?? ''}</td></tr>
            <tr><th>Ejercicio:</th><td>{e.ejercicio ?? ''}</td></tr>
            <tr><th>Periodo:</th><td>{e.numero ?? ''}</td></tr>
          </tbody>
        </table>
        <div className="impresion-titulo">{e.titulo}</div>
      </div>

      <table className="impresion-tabla">
        <thead>
          <tr>{data.columnas.map((c, i) => <th key={i}>{c.etiqueta}</th>)}</tr>
        </thead>
        <tbody>
          {data.filas.map((f, r) => (
            <tr key={r}>
              {f.map((v, i) => {
                const faltas = i === data.col_faltas ? Number(v ?? 0) : 0;
                const clase = [
                  data.columnas[i].tipo === 'texto' || data.columnas[i].tipo === 'texto_largo' ? '' : 'c',
                  faltas === 1 ? 'f1' : faltas >= 2 ? 'f2' : '',
                ].join(' ').trim();
                return <td key={i} className={clase || undefined}>{v ?? ''}</td>;
              })}
            </tr>
          ))}
        </tbody>
      </table>
      <p className="impresion-pie">{e.periodo} · Documento confidencial — uso interno de RH.</p>
    </div>
  );
}
