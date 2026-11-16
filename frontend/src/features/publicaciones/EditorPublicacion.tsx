import { useEffect, useRef, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { publicacionesApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { useAuth } from '../../auth/AuthContext';
import { Aviso } from '../../components/Aviso';
import { TextoEnriquecido } from '../../components/TextoEnriquecido';
import type { DatosPublicacion, Publicacion, TipoPublicacion } from '../../types';
import { iconoArchivo, PROVEEDORES, tamano, TIPOS } from './piezas';

const ACEPTA: Record<TipoPublicacion, string> = {
  imagenes: '.jpg,.jpeg,.png,.gif,.webp',
  documentos: '.pdf,.xlsx,.xls,.docx,.doc,.pptx,.csv,.txt,.jpg,.jpeg,.png',
  texto: '.jpg,.jpeg,.png,.gif,.webp,.pdf', video: '',
};

/** Detecta en el navegador el proveedor del enlace (la validación definitiva la hace el servidor). */
function proveedorDe(url: string): { proveedor: string; youtube?: string } | null {
  if (!/^https:\/\/\S+$/.test(url.trim())) return null;
  const yt = url.match(/(?:youtube\.com\/(?:watch\?(?:.*&)?v=|shorts\/|embed\/|live\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/);
  if (yt) return { proveedor: 'youtube', youtube: yt[1] };
  if (/vimeo\.com\/(?:video\/)?\d{6,}/.test(url)) return { proveedor: 'vimeo' };
  if (/drive\.google\.com\/file\/d\//.test(url)) return { proveedor: 'drive' };
  if (/sharepoint\.com|microsoftstream\.com|1drv\.ms|onedrive\.live\.com/.test(url)) return { proveedor: 'microsoft' };
  if (/teams\.microsoft\.com/.test(url)) return { proveedor: 'teams' };
  return { proveedor: 'otro' };
}

/** v10 — Crear o editar una publicación: tipo, contenido, a quién va dirigida y opciones. */
export function EditorPublicacion() {
  const params = useParams();
  const editarId = params.id ? Number(params.id) : null;
  const { puede } = useAuth();
  const qc = useQueryClient();
  const nav = useNavigate();
  const moderador = puede('publicaciones.moderar');
  const existente = useQuery({ queryKey: ['publicacion', editarId], queryFn: () => publicacionesApi.obtener(editarId!), enabled: editarId !== null });
  const { data: audiencias } = useQuery({ queryKey: ['publicaciones-audiencias'], queryFn: publicacionesApi.audiencias });
  const { data: categorias = [] } = useQuery({ queryKey: ['publicaciones-categorias'], queryFn: publicacionesApi.categorias });

  const [tipo, setTipo] = useState<TipoPublicacion | null>(null);
  const [d, setD] = useState<DatosPublicacion>({ titulo: '', cuerpo: '', categoria: '', etiquetas: [], audiencia: 'todos', audiencia_ids: [], permite_descarga: true, permite_copia: true, video_url: '', caduca_at: null });
  const [etiquetaTxt, setEtiquetaTxt] = useState('');
  const [archivos, setArchivos] = useState<File[]>([]);
  const [vistaPrevia, setVistaPrevia] = useState(false);
  const [buscarUsuario, setBuscarUsuario] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [progreso, setProgreso] = useState<number | null>(null);
  const [actual, setActual] = useState<Publicacion | null>(null);
  const texto = useRef<HTMLTextAreaElement>(null);
  const inputArchivos = useRef<HTMLInputElement>(null);

  useEffect(() => {
    const p = existente.data;
    if (!p) return;
    setActual(p);
    setTipo(p.tipo);
    setD({ titulo: p.titulo, cuerpo: p.cuerpo, categoria: p.categoria ?? '', etiquetas: p.etiquetas, audiencia: p.audiencia, audiencia_ids: p.audiencia_ids ?? [],
      permite_descarga: p.permite_descarga, permite_copia: p.permite_copia, video_url: p.video?.url ?? '', caduca_at: p.caduca, fijada: p.fijada, oficial: p.oficial });
  }, [existente.data]);

  if (editarId && existente.isLoading) return <p>Cargando…</p>;
  if (editarId && existente.isError) return <Aviso tipo="error">{mensajeDeError(existente.error)}</Aviso>;

  const set = (c: Partial<DatosPublicacion>) => setD((x) => ({ ...x, ...c }));
  const video = d.video_url ? proveedorDe(d.video_url) : null;

  /** Envuelve la selección del texto con marcas (**negrita**, *cursiva*) o agrega un prefijo de línea. */
  const marcar = (antes: string, despues = antes, linea = false) => {
    const t = texto.current;
    if (!t) return;
    const { selectionStart: a, selectionEnd: b, value } = t;
    const nuevo = linea
      ? value.slice(0, value.lastIndexOf('\n', a - 1) + 1) + antes + value.slice(value.lastIndexOf('\n', a - 1) + 1)
      : value.slice(0, a) + antes + (value.slice(a, b) || 'texto') + despues + value.slice(b);
    set({ cuerpo: nuevo });
    setTimeout(() => t.focus(), 0);
  };

  const agregarEtiqueta = () => {
    const nuevas = etiquetaTxt.split(',').map((e) => e.trim().replace(/^#/, '').toLowerCase()).filter(Boolean);
    if (nuevas.length) set({ etiquetas: [...new Set([...(d.etiquetas ?? []), ...nuevas])].slice(0, 10) });
    setEtiquetaTxt('');
  };
  const alternarId = (v: string | number) => {
    const ids = d.audiencia_ids ?? [];
    set({ audiencia_ids: ids.includes(v) ? ids.filter((x) => x !== v) : [...ids, v] });
  };

  const elegirArchivos = async (lista: FileList | null) => {
    if (!lista?.length) return;
    const nuevos = [...lista];
    setError(null);
    if (actual) {   // editando: se suben de inmediato
      try { setActual(await publicacionesApi.agregarAdjuntos(actual.id, nuevos)); } catch (e) { setError(mensajeDeError(e)); }
    } else {
      setArchivos((a) => [...a, ...nuevos].slice(0, 20));
    }
  };
  const quitarAdjunto = async (id: number) => {
    if (!actual) return;
    try { setActual(await publicacionesApi.quitarAdjunto(actual.id, id)); } catch (e) { setError(mensajeDeError(e)); }
  };

  const guardar = async (estado: 'borrador' | 'publicada') => {
    if (!tipo) return;
    setError(null);
    const datos: DatosPublicacion = {
      ...d, categoria: d.categoria?.trim() || undefined,
      video_url: tipo === 'video' ? d.video_url : undefined,
      audiencia_ids: d.audiencia === 'todos' ? [] : d.audiencia_ids,
      fijada: moderador ? d.fijada : undefined, oficial: moderador ? d.oficial : undefined,
    };
    try {
      let p: Publicacion;
      if (actual) {
        p = await publicacionesApi.actualizar(actual.id, { ...datos, video_url: tipo === 'video' ? d.video_url : undefined });
        if (estado === 'publicada' && p.estado !== 'publicada') p = await publicacionesApi.publicar(p.id);
      } else {
        setProgreso(0);
        p = await publicacionesApi.crear({ ...datos, tipo, estado }, archivos, setProgreso);
      }
      void qc.invalidateQueries({ queryKey: ['publicaciones'] });
      qc.setQueryData(['publicacion', p.id], p);
      nav(`/publicaciones/${p.id}`);
    } catch (e) {
      setError(mensajeDeError(e));
    } finally {
      setProgreso(null);
    }
  };

  // Paso 1: elegir el tipo
  if (!tipo) {
    return (
      <section className="editor-pub">
        <Link to="/publicaciones" className="btn-link">← Publicaciones</Link>
        <h1>Nueva publicación</h1>
        <p className="tenue">¿Qué quieres compartir?</p>
        <div className="tipos-pub">
          {(Object.keys(TIPOS) as TipoPublicacion[]).map((t) => (
            <button key={t} type="button" className="tipo-pub" onClick={() => setTipo(t)}>
              <span className="tipo-pub-icono" aria-hidden>{TIPOS[t].icono}</span>
              <strong>{TIPOS[t].texto}</strong><small>{TIPOS[t].detalle}</small>
            </button>
          ))}
        </div>
      </section>
    );
  }

  const adjuntosActuales = actual?.adjuntos ?? [];
  const usuariosFiltrados = (audiencias?.usuarios ?? []).filter((u) => u.name.toLowerCase().includes(buscarUsuario.toLowerCase()));
  const listo = (d.titulo ?? '').trim().length >= 3;
  const trabajando = progreso !== null;

  return (
    <section className="editor-pub">
      <Link to={actual ? `/publicaciones/${actual.id}` : '/publicaciones'} className="btn-link">← {actual ? 'Volver a la publicación' : 'Publicaciones'}</Link>
      <div className="editor-pub-cab">
        <h1>{actual ? 'Editar publicación' : 'Nueva publicación'}</h1>
        <span className="etiqueta etiqueta-info"><span aria-hidden>{TIPOS[tipo].icono}</span> {TIPOS[tipo].texto}</span>
        {!actual && <button className="btn-link" onClick={() => setTipo(null)}>Cambiar tipo</button>}
      </div>
      {error && <Aviso tipo="error">{error}</Aviso>}

      <div className="editor-pub-rejilla">
        <div className="tarjeta editor-pub-principal">
          <label>Título
            <input value={d.titulo} maxLength={160} placeholder="Ej. Cambio de horario a partir del lunes" onChange={(e) => set({ titulo: e.target.value })} />
          </label>

          <div className="campo">
            <div className="campo-cab">
              <span>Texto{tipo === 'texto' ? '' : ' (opcional)'}</span>
              <div className="mini-barra" role="toolbar" aria-label="Formato del texto">
                <button type="button" title="Negrita" onClick={() => marcar('**')}><b>N</b></button>
                <button type="button" title="Cursiva" onClick={() => marcar('*')}><i>K</i></button>
                <button type="button" title="Título" onClick={() => marcar('## ', '', true)}>T</button>
                <button type="button" title="Lista" onClick={() => marcar('- ', '', true)}>•</button>
                <button type="button" className={vistaPrevia ? 'activa' : ''} onClick={() => setVistaPrevia((v) => !v)}>{vistaPrevia ? 'Escribir' : 'Vista previa'}</button>
              </div>
            </div>
            {vistaPrevia
              ? <div className="vista-previa-texto">{d.cuerpo ? <TextoEnriquecido texto={d.cuerpo} /> : <p className="tenue">Nada que mostrar todavía.</p>}</div>
              : <textarea ref={texto} rows={tipo === 'texto' ? 10 : 4} maxLength={20000} value={d.cuerpo} onChange={(e) => set({ cuerpo: e.target.value })}
                  placeholder="Escribe aquí. **negrita**, *cursiva*, listas con «- » y los enlaces https://… se vuelven clic." />}
          </div>

          {(tipo === 'documentos' || tipo === 'imagenes' || tipo === 'texto') && (
            <div className="campo">
              <span>{tipo === 'imagenes' ? 'Imágenes' : tipo === 'documentos' ? 'Documentos' : 'Archivos adjuntos (opcional)'}</span>
              <div className="zona-archivo" role="button" tabIndex={0} onClick={() => inputArchivos.current?.click()}
                onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') inputArchivos.current?.click(); }}
                onDragOver={(e) => e.preventDefault()} onDrop={(e) => { e.preventDefault(); void elegirArchivos(e.dataTransfer.files); }}>
                <span className="zona-icono" aria-hidden>⇪</span>
                <span><strong>Arrastra aquí o haz clic para elegir</strong>
                  <small>{tipo === 'imagenes' ? 'JPG, PNG, GIF o WEBP · hasta 8 MB cada una' : 'PDF, Excel, Word, PowerPoint, CSV o imágenes · hasta 20 MB cada uno'} · máximo 20</small></span>
                <input ref={inputArchivos} type="file" multiple hidden accept={ACEPTA[tipo]} onChange={(e) => { void elegirArchivos(e.target.files); e.target.value = ''; }} />
              </div>
              {(archivos.length > 0 || adjuntosActuales.length > 0) && (
                <ul className="lista-documentos compacta">
                  {adjuntosActuales.map((a) => (
                    <li key={`a${a.id}`}><span className="icono-archivo">{iconoArchivo(a)}</span><span className="lista-documentos-nombre"><strong>{a.nombre}</strong><small className="tenue">{tamano(a.tamano)}</small></span>
                      <button className="btn-icono" aria-label={`Quitar ${a.nombre}`} onClick={() => void quitarAdjunto(a.id)}>✕</button></li>
                  ))}
                  {archivos.map((a, i) => (
                    <li key={`n${i}`}><span className="icono-archivo">{iconoArchivo({ nombre: a.name, mime: a.type })}</span><span className="lista-documentos-nombre"><strong>{a.name}</strong><small className="tenue">{tamano(a.size)} · por subir</small></span>
                      <button className="btn-icono" aria-label={`Quitar ${a.name}`} onClick={() => setArchivos(archivos.filter((_, k) => k !== i))}>✕</button></li>
                  ))}
                </ul>
              )}
            </div>
          )}

          {tipo === 'video' && (
            <label>Enlace del video
              <input type="url" inputMode="url" placeholder="https://www.youtube.com/watch?v=…" value={d.video_url} onChange={(e) => set({ video_url: e.target.value })} />
              <small className={d.video_url && !video ? 'texto-peligro' : 'tenue'}>
                {!d.video_url ? 'Los videos no se suben a la plataforma: pega el enlace de YouTube, Vimeo, Google Drive, Stream o Teams.'
                  : video ? `Video de ${PROVEEDORES[video.proveedor]}${['youtube', 'vimeo', 'drive'].includes(video.proveedor) ? ': se verá dentro de la publicación.' : ': se abrirá en otra pestaña.'}`
                    : 'El enlace debe empezar con https://'}
              </small>
              {video?.youtube && <img className="vista-video" src={`https://i.ytimg.com/vi/${video.youtube}/hqdefault.jpg`} alt="Vista previa del video" referrerPolicy="no-referrer" />}
            </label>
          )}

        </div>

        <aside className="editor-pub-lateral">
          <div className="tarjeta">
            <h2 className="seccion-titulo">¿Quién la puede ver?</h2>
            {(['todos', 'roles', 'usuarios'] as const).map((a) => (
              <label key={a} className="check">
                <input type="radio" name="audiencia" checked={d.audiencia === a} onChange={() => set({ audiencia: a, audiencia_ids: [] })} />
                {{ todos: 'Todos los usuarios', roles: 'Solo ciertos roles', usuarios: 'Solo ciertas personas' }[a]}
              </label>
            ))}
            {d.audiencia === 'roles' && (
              <div className="sub-opciones">
                {audiencias?.roles.map((r) => (
                  <label key={r.nombre} className="check"><input type="checkbox" checked={(d.audiencia_ids ?? []).includes(r.nombre)} onChange={() => alternarId(r.nombre)} /> {r.descripcion || r.nombre}</label>
                ))}
              </div>
            )}
            {d.audiencia === 'usuarios' && (
              <div className="sub-opciones">
                <input type="search" placeholder="Buscar persona…" value={buscarUsuario} onChange={(e) => setBuscarUsuario(e.target.value)} />
                <div className="lista-usuarios">
                  {usuariosFiltrados.map((u) => (
                    <label key={u.id} className="check"><input type="checkbox" checked={(d.audiencia_ids ?? []).includes(u.id)} onChange={() => alternarId(u.id)} /> {u.name}</label>
                  ))}
                </div>
                <small className="tenue">{(d.audiencia_ids ?? []).length} seleccionada(s)</small>
              </div>
            )}
          </div>

          <div className="tarjeta">
            <h2 className="seccion-titulo">Organizar</h2>
            <label>Categoría
              <input list="categorias-pub" maxLength={60} placeholder="Ej. Avisos, Capacitación" value={d.categoria} onChange={(e) => set({ categoria: e.target.value })} />
              <datalist id="categorias-pub">{categorias.map((c) => <option key={c.categoria} value={c.categoria} />)}</datalist>
            </label>
            <label>Etiquetas
              <input placeholder="Escribe y presiona Enter" value={etiquetaTxt} onChange={(e) => setEtiquetaTxt(e.target.value)}
                onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); agregarEtiqueta(); } }} onBlur={agregarEtiqueta} />
            </label>
            {(d.etiquetas?.length ?? 0) > 0 && (
              <ul className="chips">{d.etiquetas!.map((e) => <li key={e} className="chip">#{e}<button type="button" aria-label={`Quitar ${e}`} onClick={() => set({ etiquetas: d.etiquetas!.filter((x) => x !== e) })}>✕</button></li>)}</ul>
            )}
            <label>Visible hasta (opcional)
              <input type="date" value={d.caduca_at ?? ''} onChange={(e) => set({ caduca_at: e.target.value || null })} />
            </label>
          </div>

          <div className="tarjeta">
            <h2 className="seccion-titulo">Opciones</h2>
            {(tipo === 'documentos' || tipo === 'imagenes' || tipo === 'texto') && (
              <label className="check"><input type="checkbox" checked={!!d.permite_descarga} onChange={(e) => set({ permite_descarga: e.target.checked })} /> Permitir descargar los archivos</label>
            )}
            {moderador && <>
              <label className="check"><input type="checkbox" checked={!!d.fijada} onChange={(e) => set({ fijada: e.target.checked })} /> Fijar arriba del muro</label>
              <label className="check"><input type="checkbox" checked={!!d.oficial} onChange={(e) => set({ oficial: e.target.checked })} /> Marcar como contenido oficial</label>
            </>}
          </div>
        </aside>
      </div>

      <div className="editor-pub-pie">
        {trabajando && <progress max={100} value={progreso ?? 0} aria-label="Subiendo" />}
        {(!actual || actual.estado !== 'publicada') && (
          <button className="btn btn-sec" disabled={!listo || trabajando} onClick={() => void guardar('borrador')}>{actual ? 'Guardar borrador' : 'Guardar como borrador'}</button>
        )}
        <button className="btn" disabled={!listo || trabajando} onClick={() => void guardar('publicada')}>
          {trabajando ? (progreso! < 100 ? `Subiendo ${progreso}%…` : 'Guardando…') : actual?.estado === 'publicada' ? 'Guardar cambios' : 'Publicar'}
        </button>
      </div>
    </section>
  );
}
