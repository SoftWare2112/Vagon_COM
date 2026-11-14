import { useEffect, useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { cargasApi, exportacionesApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { useAuth } from '../../auth/AuthContext';
import { Aviso } from '../../components/Aviso';
import { Menu } from '../../components/Menu';
import { Modal } from '../../components/Modal';
import type { Carga, FiltrosHistorial } from '../../types';

type Pestana = 'importaciones' | 'exportaciones';

const ESTATUS: Record<Carga['estatus'], { texto: string; clase: string }> = {
  APLICADA: { texto: 'Aplicada', clase: 'ok' },
  LISTA: { texto: 'En revisión', clase: 'info' },
  CON_ERRORES: { texto: 'Con errores', clase: 'mal' },
  FALLIDA: { texto: 'Falló', clase: 'mal' },
  DESCARTADA: { texto: 'Descartada', clase: 'neutro' },
};

/** "Hoy", "Ayer", "Esta semana", "Este mes" o "Octubre 2026". */
export function grupoDeFecha(iso: string, hoy = new Date()): string {
  const d = new Date(iso);
  const dia = (x: Date) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
  const dias = Math.round((dia(hoy) - dia(d)) / 86_400_000);
  if (dias <= 0) return 'Hoy';
  if (dias === 1) return 'Ayer';
  if (dias <= (hoy.getDay() + 6) % 7) return 'Esta semana';   // la semana empieza en lunes
  if (d.getFullYear() === hoy.getFullYear() && d.getMonth() === hoy.getMonth()) return 'Este mes';
  const mes = d.toLocaleDateString('es-MX', { month: 'long', year: 'numeric' }).replace(' de ', ' ');
  return mes.charAt(0).toUpperCase() + mes.slice(1);
}

const tamano = (b?: number | null) => (!b ? '' : b < 1024 * 1024 ? `${Math.max(1, Math.round(b / 1024))} KB` : `${(b / 1024 / 1024).toFixed(1)} MB`);

/**
 * v9 — Informes: historial de importaciones (incidencias de nómina y Excel como hoja) agrupado por fecha,
 * con búsqueda, filtros, orden, quitar, limpiar y papelera; y el historial de exportaciones.
 */
export function InformesPage() {
  const { puede } = useAuth();
  const verImportaciones = puede('cargas.ver') || puede('hojas.crear');
  const [pestana, setPestana] = useState<Pestana>(verImportaciones ? 'importaciones' : 'exportaciones');

  return (
    <section>
      <div className="encabezado-pagina">
        <div>
          <h1>Informes</h1>
          <p className="tenue">Lo que entró a la plataforma (importaciones) y lo que salió (exportaciones).</p>
        </div>
      </div>
      {verImportaciones && puede('exportar') && (
        <div className="segmentado" role="tablist" aria-label="Tipo de informe">
          <button role="tab" aria-selected={pestana === 'importaciones'} className={pestana === 'importaciones' ? 'activo' : ''} onClick={() => setPestana('importaciones')}>Importaciones</button>
          <button role="tab" aria-selected={pestana === 'exportaciones'} className={pestana === 'exportaciones' ? 'activo' : ''} onClick={() => setPestana('exportaciones')}>Exportaciones</button>
        </div>
      )}
      {pestana === 'importaciones' ? <Importaciones /> : <Exportaciones />}
    </section>
  );
}

function Importaciones() {
  const { puede, usuario } = useAuth();
  const qc = useQueryClient();
  const [f, setF] = useState<FiltrosHistorial>({ orden: 'reciente', page: 1 });
  const [busqueda, setBusqueda] = useState('');
  const [aviso, setAviso] = useState<{ tipo: 'exito' | 'error'; texto: string } | null>(null);
  const [quitar, setQuitar] = useState<Carga | null>(null);

  useEffect(() => {
    const t = setTimeout(() => setF((x) => (x.q === (busqueda || undefined) ? x : { ...x, q: busqueda || undefined, page: 1 })), 350);
    return () => clearTimeout(t);
  }, [busqueda]);

  const { data, isLoading, isError, error } = useQuery({ queryKey: ['cargas', f], queryFn: () => cargasApi.historial(f), placeholderData: keepPreviousData });
  const refrescar = () => { void qc.invalidateQueries({ queryKey: ['cargas'] }); void qc.invalidateQueries({ queryKey: ['libros'] }); };
  const ok = (texto: string) => { setAviso({ tipo: 'exito', texto }); refrescar(); };
  const mal = (e: unknown) => setAviso({ tipo: 'error', texto: mensajeDeError(e) });

  const restaurar = useMutation({ mutationFn: (id: number) => cargasApi.restaurar(id), onSuccess: (r) => ok(r.message), onError: mal });
  const limpiar = useMutation({
    mutationFn: (alcance: 'mias' | 'todas') => cargasApi.limpiar(alcance, f.tipo || undefined),
    onSuccess: (r) => ok(r.message), onError: mal,
  });
  const pedirLimpiar = (alcance: 'mias' | 'todas') => {
    const que = alcance === 'mias' ? 'tus importaciones' : 'TODAS las importaciones de todos los usuarios';
    if (confirm(`¿Quitar del historial ${que}${f.tipo ? (f.tipo === 'libro' ? ' de Excel como hoja' : ' de incidencias') : ''}?\n\nIrán a la papelera ${30} días. Los datos de nómina y tus libros no se borran.`)) limpiar.mutate(alcance);
  };

  const cambiar = (cambio: Partial<FiltrosHistorial>) => setF((x) => ({ ...x, ...cambio, page: 1 }));
  const porFecha = f.orden === 'reciente' || f.orden === 'antiguo';
  const grupos: { titulo: string; cargas: Carga[] }[] = [];
  for (const c of data?.data ?? []) {
    const titulo = porFecha && c.fecha_iso ? grupoDeFecha(c.fecha_iso) : 'Resultados';
    const ultimo = grupos[grupos.length - 1];
    if (ultimo?.titulo === titulo) ultimo.cargas.push(c); else grupos.push({ titulo, cargas: [c] });
  }
  const hayFiltros = !!(f.q || f.tipo || f.estatus || f.desde || f.hasta);

  return (
    <>
      <div className="historial-barra">
        <div className="buscador">
          <span className="buscador-icono" aria-hidden>⌕</span>
          <input type="search" placeholder="Buscar archivo…" value={busqueda} onChange={(e) => setBusqueda(e.target.value)} aria-label="Buscar por nombre de archivo" />
        </div>
        <select aria-label="Tipo" value={f.tipo ?? ''} onChange={(e) => cambiar({ tipo: e.target.value as FiltrosHistorial['tipo'] })}>
          <option value="">Todos los tipos</option>
          {puede('cargas.ver') && <option value="nomina">Incidencias de nómina</option>}
          <option value="libro">Excel como hoja</option>
        </select>
        <select aria-label="Estado" value={f.estatus ?? ''} onChange={(e) => cambiar({ estatus: e.target.value || undefined })}>
          <option value="">Cualquier estado</option>
          {Object.entries(ESTATUS).map(([k, v]) => <option key={k} value={k}>{v.texto}</option>)}
        </select>
        <label className="campo-fecha">Desde<input type="date" value={f.desde ?? ''} onChange={(e) => cambiar({ desde: e.target.value || undefined })} /></label>
        <label className="campo-fecha">Hasta<input type="date" value={f.hasta ?? ''} onChange={(e) => cambiar({ hasta: e.target.value || undefined })} /></label>
        <select aria-label="Ordenar" value={f.orden} onChange={(e) => cambiar({ orden: e.target.value as FiltrosHistorial['orden'] })}>
          <option value="reciente">Más recientes</option>
          <option value="antiguo">Más antiguas</option>
          <option value="nombre">Nombre (A-Z)</option>
          <option value="tamano">Tamaño</option>
        </select>
        <div className="historial-barra-der">
          <label className="check"><input type="checkbox" checked={!!f.papelera} onChange={(e) => cambiar({ papelera: e.target.checked })} /> Papelera</label>
          {!f.papelera && (
            <Menu etiqueta="Limpiar historial" clase="btn btn-sec btn-sm" opciones={[
              { texto: 'Solo mis importaciones', icono: '🧹', onClick: () => pedirLimpiar('mias') },
              ...(puede('informes.limpiar') ? [{ texto: 'Todo el historial', detalle: 'De todos los usuarios', icono: '⚠', peligro: true, onClick: () => pedirLimpiar('todas') }] : []),
            ]} />
          )}
        </div>
      </div>
      {hayFiltros && (
        <p className="tenue">
          {data ? `${data.meta.total} resultado(s)` : ''} · <button className="btn-link" onClick={() => { setBusqueda(''); setF({ orden: f.orden, papelera: f.papelera, page: 1 }); }}>Quitar filtros</button>
        </p>
      )}
      {f.papelera && <Aviso tipo="info">Importaciones quitadas del historial. Se borran definitivamente a los 30 días.</Aviso>}
      {aviso && <Aviso tipo={aviso.tipo}>{aviso.texto} <button className="btn-link" onClick={() => setAviso(null)}>Cerrar</button></Aviso>}
      {isLoading && <p>Cargando…</p>}
      {isError && <Aviso tipo="error">{mensajeDeError(error)}</Aviso>}
      {data && data.data.length === 0 && (
        <div className="vacio">{f.papelera ? 'La papelera está vacía.' : hayFiltros ? 'Nada coincide con la búsqueda.' : 'Aún no hay importaciones.'}</div>
      )}

      {grupos.map((g) => (
        <section key={g.titulo} className="grupo-historial">
          <h2 className="grupo-titulo">{g.titulo}</h2>
          <ul className="lista-historial">
            {g.cargas.map((c) => (
              <TarjetaImportacion key={c.id} c={c} papelera={!!f.papelera}
                puedeQuitar={c.user_id === usuario?.id || puede('informes.limpiar')}
                onQuitar={() => setQuitar(c)} onRestaurar={() => restaurar.mutate(c.id)} />
            ))}
          </ul>
        </section>
      ))}

      {data && data.meta.last_page > 1 && (
        <nav className="paginacion" aria-label="Paginación">
          <button className="btn btn-sec btn-sm" disabled={data.meta.current_page <= 1} onClick={() => setF({ ...f, page: data.meta.current_page - 1 })}>Anterior</button>
          <span>Página {data.meta.current_page} de {data.meta.last_page}</span>
          <button className="btn btn-sec btn-sm" disabled={data.meta.current_page >= data.meta.last_page} onClick={() => setF({ ...f, page: data.meta.current_page + 1 })}>Siguiente</button>
        </nav>
      )}

      {quitar && <QuitarModal carga={quitar} onCerrar={() => setQuitar(null)} onListo={(t) => { setQuitar(null); ok(t); }} />}
    </>
  );
}

function TarjetaImportacion({ c, papelera, puedeQuitar, onQuitar, onRestaurar }: {
  c: Carga; papelera: boolean; puedeQuitar: boolean; onQuitar: () => void; onRestaurar: () => void;
}) {
  const est = ESTATUS[c.estatus];
  const r = c.resumen;
  const detalle = c.tipo === 'libro'
    ? [r?.pestanas && `${r.pestanas} pestaña(s)`, r?.celdas !== undefined && `${Number(r.celdas).toLocaleString('es-MX')} celdas`,
      r?.imagenes ? `${r.imagenes} imagen(es)` : null, r?.avisos?.length ? `${r.avisos.length} aviso(s)` : null]
    : [c.periodo?.etiqueta, c.filas_total ? `${c.filas_total} empleados` : null,
      r && 'nuevos' in r ? `+${r.nuevos} nuevos · ${r.actualizados} actualizados` : null,
      c.num_errores ? `${c.num_errores} error(es)` : null];
  const hora = c.subida.slice(-5);

  return (
    <li className={`tarjeta-historial tipo-${c.tipo}`}>
      <span className="tarjeta-historial-icono" aria-hidden>{c.tipo === 'libro' ? '▦' : '📋'}</span>
      <div className="tarjeta-historial-texto">
        <div className="tarjeta-historial-titulo">
          <strong title={c.archivo}>{c.archivo}</strong>
          <span className={`etiqueta etiqueta-${est.clase}`}>{est.texto}</span>
          <span className="etiqueta etiqueta-neutro">{c.tipo === 'libro' ? 'Excel como hoja' : 'Incidencias'}</span>
        </div>
        <small className="tenue">{[c.usuario, papelera ? `quitada ${c.eliminada}` : `${c.subida.slice(0, 10)} ${hora}`, tamano(c.tamano)].filter(Boolean).join(' · ')}</small>
        <small>{detalle.filter(Boolean).join(' · ')}</small>
      </div>
      <div className="tarjeta-historial-acciones">
        {papelera
          ? puedeQuitar && <button className="btn btn-sec btn-sm" onClick={onRestaurar}>Recuperar</button>
          : <>
            {c.tipo === 'libro' && c.libro && !c.libro.eliminado && <Link className="btn btn-sec btn-sm" to={`/hojas/${c.libro.id}`}>Abrir</Link>}
            {c.tipo === 'nomina' && c.estatus === 'APLICADA' && <Link className="btn btn-sec btn-sm" to="/incidencias">Ver quincena</Link>}
            {c.tipo === 'nomina' && c.num_errores + c.num_advertencias > 0 && (
              <button className="btn btn-sec btn-sm" onClick={() => void cargasApi.descargarErrores(c.id, c.archivo)}>Errores en Excel</button>
            )}
            {puedeQuitar && <button className="btn-icono" title="Quitar del historial" aria-label={`Quitar ${c.archivo} del historial`} onClick={onQuitar}>🗑</button>}
          </>}
      </div>
    </li>
  );
}

function QuitarModal({ carga, onCerrar, onListo }: { carga: Carga; onCerrar: () => void; onListo: (texto: string) => void }) {
  const [conLibro, setConLibro] = useState(false);
  const quitar = useMutation({ mutationFn: () => cargasApi.quitar(carga.id, conLibro), onSuccess: (r) => onListo(r.message) });
  const libro = carga.tipo === 'libro' && carga.libro && !carga.libro.eliminado ? carga.libro : null;

  return (
    <Modal titulo="Quitar del historial" abierto onCerrar={onCerrar} ancho="sm"
      pie={<>
        <button className="btn btn-sec" onClick={onCerrar}>Cancelar</button>
        <button className="btn btn-peligro" disabled={quitar.isPending} onClick={() => quitar.mutate()}>{quitar.isPending ? 'Quitando…' : 'Quitar'}</button>
      </>}>
      {quitar.error != null && <Aviso tipo="error">{mensajeDeError(quitar.error)}</Aviso>}
      <p><strong>{carga.archivo}</strong> irá a la papelera; podrás recuperarlo durante 30 días.</p>
      {carga.tipo === 'nomina' && <p className="tenue">Los datos de la quincena no se borran: solo deja de aparecer en el historial.</p>}
      {libro && (
        <label className="check"><input type="checkbox" checked={conLibro} onChange={(e) => setConLibro(e.target.checked)} /> Eliminar también el libro «{libro.nombre}»</label>
      )}
    </Modal>
  );
}

function Exportaciones() {
  const exportaciones = useQuery({ queryKey: ['exportaciones'], queryFn: exportacionesApi.historial });
  return (
    <>
      {exportaciones.data?.data.length === 0 && <div className="vacio">Aún no se ha exportado ningún reporte.</div>}
      {!!exportaciones.data?.data.length && (
        <div className="tabla-scroll"><table className="tabla">
          <thead><tr><th>Fecha</th><th>Archivo</th><th>Formato</th><th>Filas</th><th>Usuario</th><th /></tr></thead>
          <tbody>{exportaciones.data.data.map((x) => (
            <tr key={x.id}>
              <td>{x.fecha}</td><td>{x.nombre}</td><td><span className="etiqueta etiqueta-neutro">{x.formato}</span></td><td>{x.filas}</td><td>{x.usuario}</td>
              <td><button className="btn btn-sec btn-sm" onClick={() => exportacionesApi.descargar(x.id, x.nombre)}>Descargar</button></td>
            </tr>
          ))}</tbody>
        </table></div>
      )}
    </>
  );
}
