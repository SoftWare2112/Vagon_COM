import { useQuery } from '@tanstack/react-query';
import { cargasApi, exportacionesApi } from '../../api/endpoints';
import { useAuth } from '../../auth/AuthContext';

/** Trazabilidad: archivos que entraron (cargas ETL) y reportes que salieron (exportaciones). */
export function InformesPage() {
  const { puede } = useAuth();
  const cargas = useQuery({ queryKey: ['cargas'], queryFn: cargasApi.historial, enabled: puede('cargas.ver') });
  const exportaciones = useQuery({ queryKey: ['exportaciones'], queryFn: exportacionesApi.historial });

  return (
    <section>
      <div className="encabezado-pagina"><h1>Informes</h1></div>

      {puede('cargas.ver') && (
        <>
          <h2>Historial de cargas</h2>
          <div className="tabla-scroll"><table className="tabla">
            <thead><tr><th>Fecha</th><th>Archivo</th><th>Usuario</th><th>Quincena</th><th>Filas</th><th>Modo</th><th>Estatus</th><th>Resultado</th></tr></thead>
            <tbody>{cargas.data?.data.map((c) => (
              <tr key={c.id}>
                <td>{c.subida}</td><td>{c.archivo}</td><td>{c.usuario}</td><td>{c.periodo?.etiqueta ?? '—'}</td><td>{c.filas_total}</td>
                <td>{c.modo ?? '—'}</td><td><span className={`estatus estatus-${c.estatus.toLowerCase()}`}>{c.estatus}</span></td>
                <td className="tenue">{c.resumen ? `+${c.resumen.nuevos} · ✎${c.resumen.actualizados} · −${c.resumen.eliminados}` : c.num_errores ? `${c.num_errores} errores` : ''}</td>
              </tr>
            ))}</tbody>
          </table></div>
        </>
      )}

      <h2>Reportes exportados</h2>
      <div className="tabla-scroll"><table className="tabla">
        <thead><tr><th>Fecha</th><th>Archivo</th><th>Formato</th><th>Filas</th><th>Usuario</th><th /></tr></thead>
        <tbody>{exportaciones.data?.data.map((x) => (
          <tr key={x.id}>
            <td>{x.fecha}</td><td>{x.nombre}</td><td>{x.formato}</td><td>{x.filas}</td><td>{x.usuario}</td>
            <td><button className="btn btn-sec btn-sm" onClick={() => exportacionesApi.descargar(x.id, x.nombre)}>Descargar</button></td>
          </tr>
        ))}</tbody>
      </table></div>
    </section>
  );
}
