import { useEffect, useState } from 'react';
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { publicacionesApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { useAuth } from '../../auth/AuthContext';
import { Aviso } from '../../components/Aviso';
import { ImagenSegura } from '../../components/ImagenSegura';
import { TextoEnriquecido } from '../../components/TextoEnriquecido';
import type { FiltrosPublicaciones, Publicacion, TipoPublicacion } from '../../types';
import { haceCuanto, iconoArchivo, iniciales, Insignias, PROVEEDORES, TIPOS } from './piezas';

const VISTAS: { id: NonNullable<FiltrosPublicaciones['vista']>; texto: string; crear?: boolean }[] = [
  { id: 'todas', texto: 'Todas' }, { id: 'favoritas', texto: '★ Favoritas' },
  { id: 'mias', texto: 'Mis publicaciones', crear: true }, { id: 'borradores', texto: 'Borradores', crear: true }, { id: 'archivadas', texto: 'Archivadas', crear: true },
];

/** v10 — Muro de publicaciones: lo fijado arriba, filtros por tipo, categoría y búsqueda. */
export function PublicacionesPage() {
  const { puede } = useAuth();
  const qc = useQueryClient();
  const [f, setF] = useState<FiltrosPublicaciones>({ vista: 'todas', page: 1 });
  const [busqueda, setBusqueda] = useState('');
  useEffect(() => {
    const t = setTimeout(() => setF((x) => (x.q === (busqueda || undefined) ? x : { ...x, q: busqueda || undefined, page: 1 })), 350);
    return () => clearTimeout(t);
  }, [busqueda]);

  const { data, isLoading, isError, error } = useQuery({ queryKey: ['publicaciones', f], queryFn: () => publicacionesApi.listar(f), placeholderData: keepPreviousData });
  const { data: categorias = [] } = useQuery({ queryKey: ['publicaciones-categorias'], queryFn: publicacionesApi.categorias });
  const cambiar = (c: Partial<FiltrosPublicaciones>) => setF((x) => ({ ...x, ...c, page: 1 }));
  const favorita = async (p: Publicacion) => {
    await publicacionesApi.favorita(p.id);
    void qc.invalidateQueries({ queryKey: ['publicaciones'] });
  };

  return (
    <section>
      <div className="encabezado-pagina">
        <div>
          <h1>Publicaciones</h1>
          <p className="tenue">Avisos, documentos, imágenes, videos y trabajos que comparten los usuarios de la plataforma.</p>
        </div>
        {puede('publicaciones.crear') && <Link className="btn" to="/publicaciones/nueva">+ Nueva publicación</Link>}
      </div>

      <div className="muro-barra">
        <div className="buscador">
          <span className="buscador-icono" aria-hidden>⌕</span>
          <input type="search" placeholder="Buscar en publicaciones…" value={busqueda} onChange={(e) => setBusqueda(e.target.value)} aria-label="Buscar publicaciones" />
        </div>
        <select aria-label="Categoría" value={f.categoria ?? ''} onChange={(e) => cambiar({ categoria: e.target.value || undefined })}>
          <option value="">Todas las categorías</option>
          {categorias.map((c) => <option key={c.categoria} value={c.categoria}>{c.categoria} ({c.total})</option>)}
        </select>
        <div className="segmentado segmentado-sm" role="tablist" aria-label="Tipo">
          <button role="tab" aria-selected={!f.tipo} className={!f.tipo ? 'activo' : ''} onClick={() => cambiar({ tipo: undefined })}>Todo</button>
          {(Object.keys(TIPOS) as TipoPublicacion[]).map((t) => (
            <button key={t} role="tab" aria-selected={f.tipo === t} className={f.tipo === t ? 'activo' : ''} onClick={() => cambiar({ tipo: t })}>
              <span aria-hidden>{TIPOS[t].icono}</span> {t === 'trabajo' ? 'Trabajos' : TIPOS[t].texto}
            </button>
          ))}
        </div>
      </div>
      <nav className="vistas-muro" aria-label="Vistas">
        {VISTAS.filter((v) => !v.crear || puede('publicaciones.crear')).map((v) => (
          <button key={v.id} className={`btn-link ${f.vista === v.id ? 'activa' : ''}`} onClick={() => cambiar({ vista: v.id })}>{v.texto}</button>
        ))}
        {f.etiqueta && <span className="chip-filtro">#{f.etiqueta} <button aria-label="Quitar etiqueta" onClick={() => cambiar({ etiqueta: undefined })}>✕</button></span>}
      </nav>

      {isLoading && <p>Cargando…</p>}
      {isError && <Aviso tipo="error">{mensajeDeError(error)}</Aviso>}
      {data && data.data.length === 0 && (
        <div className="vacio">
          {f.q || f.tipo || f.categoria ? 'Nada coincide con la búsqueda.' : f.vista === 'todas' ? 'Todavía no hay publicaciones.' : 'No hay publicaciones en esta vista.'}
        </div>
      )}

      <ul className="muro">
        {data?.data.map((p) => <TarjetaPublicacion key={p.id} p={p} onFavorita={() => void favorita(p)} onEtiqueta={(e) => cambiar({ etiqueta: e })} />)}
      </ul>

      {data && data.meta.last_page > 1 && (
        <nav className="paginacion" aria-label="Paginación">
          <button className="btn btn-sec btn-sm" disabled={data.meta.current_page <= 1} onClick={() => setF({ ...f, page: data.meta.current_page - 1 })}>Anteriores</button>
          <span>Página {data.meta.current_page} de {data.meta.last_page}</span>
          <button className="btn btn-sec btn-sm" disabled={data.meta.current_page >= data.meta.last_page} onClick={() => setF({ ...f, page: data.meta.current_page + 1 })}>Más</button>
        </nav>
      )}
    </section>
  );
}

function TarjetaPublicacion({ p, onFavorita, onEtiqueta }: { p: Publicacion; onFavorita: () => void; onEtiqueta: (e: string) => void }) {
  const imagenes = p.adjuntos.filter((a) => a.es_imagen);
  const documentos = p.adjuntos.filter((a) => !a.es_imagen);
  return (
    <li className={`tarjeta-pub ${p.fijada ? 'fijada' : ''} ${!p.leida ? 'no-leida' : ''}`}>
      <header className="tarjeta-pub-cab">
        <span className="avatar" aria-hidden>{iniciales(p.autor.nombre ?? '?')}</span>
        <div className="tarjeta-pub-autor">
          <strong>{p.autor.nombre}</strong>
          <small className="tenue">{p.estado === 'publicada' ? haceCuanto(p.publicada_iso) : 'Sin publicar'} · <span aria-hidden>{TIPOS[p.tipo].icono}</span> {TIPOS[p.tipo].texto}</small>
        </div>
        <Insignias p={p} />
        <button className={`btn-icono estrella ${p.favorita ? 'activa' : ''}`} aria-pressed={p.favorita} aria-label={p.favorita ? 'Quitar de favoritas' : 'Agregar a favoritas'} onClick={onFavorita}>{p.favorita ? '★' : '☆'}</button>
      </header>

      <Link to={`/publicaciones/${p.id}`} className="tarjeta-pub-cuerpo">
        <h2>{p.titulo}</h2>
        {p.cuerpo && <TextoEnriquecido texto={p.cuerpo} className="extracto" sinEnlaces />}

        {imagenes.length > 0 && (
          <div className={`mini-galeria n${Math.min(imagenes.length, 4)}`}>
            {imagenes.slice(0, 4).map((a, i) => (
              <span key={a.id} className="mini-galeria-item">
                <ImagenSegura url={a.miniatura} alt={a.nombre} />
                {i === 3 && imagenes.length > 4 && <span className="mas-imagenes">+{imagenes.length - 4}</span>}
              </span>
            ))}
          </div>
        )}
        {documentos.length > 0 && (
          <ul className="mini-documentos">
            {documentos.slice(0, 3).map((a) => <li key={a.id}><span className="icono-archivo">{iconoArchivo(a)}</span>{a.nombre}</li>)}
            {documentos.length > 3 && <li className="tenue">y {documentos.length - 3} más…</li>}
          </ul>
        )}
        {p.video && (
          <div className="mini-video">
            {p.video.proveedor === 'youtube' && p.video.id
              ? <img src={`https://i.ytimg.com/vi/${p.video.id}/hqdefault.jpg`} alt="" loading="lazy" referrerPolicy="no-referrer" />
              : <span className="mini-video-fondo" aria-hidden>▶</span>}
            <span className="mini-video-play" aria-hidden>▶</span>
            <small>Video en {PROVEEDORES[p.video.proveedor] ?? 'sitio externo'}</small>
          </div>
        )}
        {p.trabajo && (
          <div className="mini-trabajo">
            <span className="tarjeta-libro-icono origen-plantilla" aria-hidden>▦</span>
            <span><strong>{p.trabajo.libro_nombre}</strong><small className="tenue">{p.trabajo.hojas.length} pestaña(s) · versión {p.trabajo.version}</small></span>
          </div>
        )}
      </Link>

      <footer className="tarjeta-pub-pie">
        {p.categoria && <span className="etiqueta etiqueta-neutro">{p.categoria}</span>}
        {p.etiquetas.map((e) => <button key={e} className="btn-link etiqueta-hash" onClick={() => onEtiqueta(e)}>#{e}</button>)}
        <span className="pie-der tenue">
          {p.audiencia !== 'todos' && <span title="Solo para ciertos roles o usuarios">🔒 Dirigida</span>}
          {p.lecturas !== undefined && <span title="Personas que la abrieron">👁 {p.lecturas}</span>}
        </span>
      </footer>
    </li>
  );
}
