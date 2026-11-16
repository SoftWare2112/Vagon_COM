import { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { archivoBlob, descargarArchivo, publicacionesApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { useAuth } from '../../auth/AuthContext';
import { Aviso } from '../../components/Aviso';
import { ImagenSegura } from '../../components/ImagenSegura';
import { Modal } from '../../components/Modal';
import { TextoEnriquecido } from '../../components/TextoEnriquecido';
import type { AdjuntoPublicacion, Publicacion } from '../../types';
import { iconoArchivo, iniciales, Insignias, PROVEEDORES, tamano, TIPOS } from './piezas';
import { VistaHojaPublicada } from './VistaHojaPublicada';

/** v10 — Una publicación completa: texto, galería, documentos con vista previa, video y trabajo en solo lectura. */
export function PublicacionPage() {
  const id = Number(useParams().id);
  const { puede } = useAuth();
  const qc = useQueryClient();
  const nav = useNavigate();
  const { data: p, isLoading, isError, error } = useQuery({ queryKey: ['publicacion', id], queryFn: () => publicacionesApi.obtener(id) });
  const [aviso, setAviso] = useState<{ tipo: 'exito' | 'error'; texto: string } | null>(null);
  const [foto, setFoto] = useState<number | null>(null);
  const [previa, setPrevia] = useState<AdjuntoPublicacion | null>(null);
  const [pestana, setPestana] = useState(0);
  const [verLecturas, setVerLecturas] = useState(false);
  const [trabajando, setTrabajando] = useState(false);

  useEffect(() => { void qc.invalidateQueries({ queryKey: ['publicaciones-sin-leer'] }); }, [id, qc]);

  if (isLoading) return <p>Cargando…</p>;
  if (isError || !p) return <Aviso tipo="error">{mensajeDeError(error)}</Aviso>;

  const moderador = puede('publicaciones.moderar');
  const imagenes = p.adjuntos.filter((a) => a.es_imagen);
  const documentos = p.adjuntos.filter((a) => !a.es_imagen);
  const descarga = p.permite_descarga || p.puede_editar;

  const accion = async (fn: () => Promise<Publicacion | void>, exito: string) => {
    setTrabajando(true);
    try {
      const r = await fn();
      if (r) qc.setQueryData(['publicacion', id], r);
      void qc.invalidateQueries({ queryKey: ['publicaciones'] });
      setAviso({ tipo: 'exito', texto: exito });
    } catch (e) { setAviso({ tipo: 'error', texto: mensajeDeError(e) }); } finally { setTrabajando(false); }
  };
  const eliminar = async () => {
    if (!confirm(`¿Eliminar la publicación «${p.titulo}»? Dejará de verse para todos.`)) return;
    try { await publicacionesApi.eliminar(p.id); void qc.invalidateQueries({ queryKey: ['publicaciones'] }); nav('/publicaciones'); }
    catch (e) { setAviso({ tipo: 'error', texto: mensajeDeError(e) }); }
  };
  const copiar = async () => {
    setTrabajando(true);
    try { const libro = await publicacionesApi.copiar(p.id); void qc.invalidateQueries({ queryKey: ['libros'] }); nav(`/hojas/${libro.libro.id}`); }
    catch (e) { setAviso({ tipo: 'error', texto: mensajeDeError(e) }); setTrabajando(false); }
  };

  return (
    <article className="publicacion">
      <Link to="/publicaciones" className="btn-link">← Publicaciones</Link>

      <header className="publicacion-cab">
        <div className="publicacion-titulo">
          <Insignias p={{ ...p, leida: true }} />
          <h1>{p.titulo}</h1>
          <div className="publicacion-autor">
            <span className="avatar" aria-hidden>{iniciales(p.autor.nombre ?? '?')}</span>
            <span><strong>{p.autor.nombre}</strong>
              <small className="tenue">{p.publicada ? `Publicada el ${p.publicada}` : 'Sin publicar'} · {TIPOS[p.tipo].texto}{p.caduca ? ` · visible hasta el ${p.caduca.split('-').reverse().join('/')}` : ''}</small>
            </span>
          </div>
        </div>
        <div className="barra-acciones">
          <button className={`btn btn-sec ${p.favorita ? 'activo' : ''}`} aria-pressed={p.favorita}
            onClick={() => void accion(async () => { const fav = await publicacionesApi.favorita(p.id); return { ...p, favorita: fav }; }, p.favorita ? 'Quitada de favoritas.' : 'Agregada a favoritas.')}>
            {p.favorita ? '★ Favorita' : '☆ Favorita'}
          </button>
          {p.puede_editar && <>
            {p.lecturas !== undefined && <button className="btn btn-sec" onClick={() => setVerLecturas(true)}>👁 {p.lecturas} vista(s)</button>}
            <Link className="btn btn-sec" to={`/publicaciones/${p.id}/editar`}>Editar</Link>
            {p.estado !== 'publicada'
              ? <button className="btn" disabled={trabajando} onClick={() => void accion(() => publicacionesApi.publicar(p.id), 'Publicada. Ya la pueden ver sus destinatarios.')}>Publicar</button>
              : <button className="btn btn-sec" disabled={trabajando} onClick={() => void accion(() => publicacionesApi.archivar(p.id), 'Archivada: ya no aparece en el muro.')}>Archivar</button>}
            <button className="btn-icono peligro" title="Eliminar" aria-label="Eliminar publicación" onClick={() => void eliminar()}>🗑</button>
          </>}
        </div>
      </header>

      {aviso && <Aviso tipo={aviso.tipo}>{aviso.texto} <button className="btn-link" onClick={() => setAviso(null)}>Cerrar</button></Aviso>}

      {moderador && (
        <div className="barra-moderacion">
          <span className="tenue">Moderación:</span>
          <label className="check"><input type="checkbox" checked={p.fijada} onChange={(e) => void accion(() => publicacionesApi.moderar(p.id, { fijada: e.target.checked }), e.target.checked ? 'Fijada arriba del muro.' : 'Ya no está fijada.')} /> Fijar arriba</label>
          <label className="check"><input type="checkbox" checked={p.oficial} onChange={(e) => void accion(() => publicacionesApi.moderar(p.id, { oficial: e.target.checked }), e.target.checked ? 'Marcada como oficial.' : 'Ya no es oficial.')} /> Contenido oficial</label>
        </div>
      )}

      {p.cuerpo && <TextoEnriquecido texto={p.cuerpo} className="publicacion-texto" />}

      {p.video && (
        <section className="publicacion-video">
          {p.video.incrustar
            ? <div className="video-marco">
                <iframe src={p.video.incrustar} title={p.titulo} loading="lazy" referrerPolicy="strict-origin-when-cross-origin"
                  allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowFullScreen
                  sandbox="allow-scripts allow-same-origin allow-presentation allow-popups" />
              </div>
            : <div className="video-externo"><span aria-hidden>▶</span><p>Este video está en {PROVEEDORES[p.video.proveedor] ?? 'un sitio externo'} y se abre en otra pestaña{p.video.proveedor === 'microsoft' || p.video.proveedor === 'teams' ? ' (con tu cuenta de Microsoft)' : ''}.</p></div>}
          <a className="btn btn-sec btn-sm" href={p.video.url} target="_blank" rel="noopener noreferrer">Abrir en {PROVEEDORES[p.video.proveedor] ?? 'el sitio'} ↗</a>
        </section>
      )}

      {imagenes.length > 0 && (
        <section className="galeria">
          {imagenes.map((a, i) => (
            <button key={a.id} className="galeria-item" onClick={() => setFoto(i)} aria-label={`Ver ${a.nombre}`}>
              <ImagenSegura url={a.miniatura} alt={a.nombre} />
            </button>
          ))}
        </section>
      )}

      {documentos.length > 0 && (
        <section>
          <h2 className="seccion-titulo">Documentos</h2>
          <ul className="lista-documentos">
            {documentos.map((a) => (
              <li key={a.id}>
                <span className="icono-archivo">{iconoArchivo(a)}</span>
                <span className="lista-documentos-nombre"><strong>{a.nombre}</strong><small className="tenue">{tamano(a.tamano)}</small></span>
                {a.mime === 'application/pdf' && <button className="btn btn-sec btn-sm" onClick={() => setPrevia(a)}>Ver</button>}
                {descarga
                  ? <button className="btn btn-sec btn-sm" onClick={() => void descargarArchivo(`${a.url}?descargar=1`, a.nombre)}>Descargar</button>
                  : <small className="tenue">{a.mime === 'application/pdf' ? 'Solo lectura' : 'El autor no permitió descargarlo'}</small>}
              </li>
            ))}
          </ul>
        </section>
      )}

      {p.trabajo && (
        <section className="publicacion-trabajo">
          <div className="seccion-cab">
            <h2 className="seccion-titulo">▦ {p.trabajo.libro_nombre} <small className="tenue">versión {p.trabajo.version}{p.trabajo.fecha ? ` · ${p.trabajo.fecha}` : ''}</small></h2>
            <div className="barra-acciones">
              {p.puede_editar && p.trabajo.libro_id && <Link className="btn btn-sec btn-sm" to={`/hojas/${p.trabajo.libro_id}`}>Abrir mi libro</Link>}
              {p.puede_editar && p.trabajo.libro_disponible && (
                <button className="btn btn-sec btn-sm" disabled={trabajando} title="Publica lo que tienes hoy en tu libro"
                  onClick={() => void accion(() => publicacionesApi.actualizarVersion(p.id), 'Publicación actualizada con la versión de hoy.')}>Actualizar publicación</button>
              )}
              {puede('hojas.crear') && (p.permite_copia || p.puede_editar) && (
                <button className="btn btn-sm" disabled={trabajando} onClick={() => void copiar()}>Hacer una copia en mi espacio</button>
              )}
            </div>
          </div>
          {(p.trabajo.contenido?.length ?? 0) > 1 && (
            <div className="segmentado segmentado-sm" role="tablist">
              {p.trabajo.contenido!.map((h, i) => <button key={i} role="tab" aria-selected={pestana === i} className={pestana === i ? 'activo' : ''} onClick={() => setPestana(i)}>{h.nombre}</button>)}
            </div>
          )}
          {p.trabajo.contenido?.[pestana] && <VistaHojaPublicada key={pestana} hoja={p.trabajo.contenido[pestana]} />}
        </section>
      )}

      {(p.categoria || p.etiquetas.length > 0) && (
        <footer className="tarjeta-pub-pie">
          {p.categoria && <span className="etiqueta etiqueta-neutro">{p.categoria}</span>}
          {p.etiquetas.map((e) => <span key={e} className="etiqueta-hash">#{e}</span>)}
        </footer>
      )}

      {foto !== null && imagenes[foto] && (
        <Modal titulo={imagenes[foto].nombre} abierto onCerrar={() => setFoto(null)} ancho="lg"
          pie={<>
            <span className="tenue">{foto + 1} de {imagenes.length}</span>
            <button className="btn btn-sec" disabled={foto === 0} onClick={() => setFoto(foto - 1)}>‹ Anterior</button>
            <button className="btn btn-sec" disabled={foto === imagenes.length - 1} onClick={() => setFoto(foto + 1)}>Siguiente ›</button>
            {descarga && <button className="btn" onClick={() => void descargarArchivo(`${imagenes[foto].url}?descargar=1`, imagenes[foto].nombre)}>Descargar</button>}
          </>}>
          <div className="visor-imagen"><ImagenSegura key={imagenes[foto].id} url={imagenes[foto].url} alt={imagenes[foto].nombre} /></div>
        </Modal>
      )}
      {previa && <VisorPdf adjunto={previa} onCerrar={() => setPrevia(null)} />}
      {verLecturas && <Lecturas id={p.id} onCerrar={() => setVerLecturas(false)} />}
    </article>
  );
}

function VisorPdf({ adjunto, onCerrar }: { adjunto: AdjuntoPublicacion; onCerrar: () => void }) {
  const { data: blob, isError } = useQuery({ queryKey: ['archivo', adjunto.url], queryFn: () => archivoBlob(adjunto.url), staleTime: Infinity });
  const [src, setSrc] = useState<string | null>(null);
  useEffect(() => {
    if (!blob) return;
    const u = URL.createObjectURL(blob);
    setSrc(u);
    return () => URL.revokeObjectURL(u);
  }, [blob]);
  return (
    <Modal titulo={adjunto.nombre} abierto onCerrar={onCerrar} ancho="lg">
      {isError && <Aviso tipo="error">No se pudo abrir el documento.</Aviso>}
      {src ? <iframe className="visor-pdf" src={src} title={adjunto.nombre} /> : !isError && <p>Abriendo…</p>}
    </Modal>
  );
}

function Lecturas({ id, onCerrar }: { id: number; onCerrar: () => void }) {
  const { data = [], isLoading } = useQuery({ queryKey: ['publicacion-lecturas', id], queryFn: () => publicacionesApi.lecturas(id) });
  return (
    <Modal titulo="Quién la ha visto" abierto onCerrar={onCerrar} ancho="sm">
      {isLoading && <p>Cargando…</p>}
      {!isLoading && data.length === 0 && <p className="tenue">Nadie la ha abierto todavía.</p>}
      <ul className="lista-simple">{data.map((l) => <li key={l.id}><strong>{l.nombre}</strong><small className="tenue">{l.leida}</small></li>)}</ul>
    </Modal>
  );
}
