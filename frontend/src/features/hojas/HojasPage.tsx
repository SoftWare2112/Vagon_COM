import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { hojasApi, librosApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { useAuth } from '../../auth/AuthContext';
import { Aviso } from '../../components/Aviso';
import { Modal } from '../../components/Modal';
import type { LibroResumen } from '../../types';
import { ImportarExcelModal } from './ImportarExcelModal';

const ORIGEN: Record<LibroResumen['origen'], { texto: string; icono: string }> = {
  personal: { texto: 'Mi hoja', icono: '★' },
  importado: { texto: 'Excel importado', icono: '⇪' },
  plantilla: { texto: 'Desde plantilla', icono: '▤' },
  blanco: { texto: 'En blanco', icono: '▦' },
};

/** v9 — Mis hojas: los libros del usuario (privados). El Administrador puede ver los de todos. */
export function HojasPage() {
  const { puede, usuario } = useAuth();
  const qc = useQueryClient();
  const nav = useNavigate();
  const [nueva, setNueva] = useState(false);
  const [importar, setImportar] = useState(false);
  const [q, setQ] = useState('');
  const [todos, setTodos] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const admin = puede('hojas.ver_todas');
  const { data: libros = [], isLoading } = useQuery({ queryKey: ['libros', { q, todos }], queryFn: () => librosApi.listar({ q, todos }) });

  const eliminar = useMutation({
    mutationFn: (id: number) => librosApi.eliminar(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['libros'] }),
    onError: (e) => setError(mensajeDeError(e)),
  });

  return (
    <section>
      <header className="encabezado-pagina">
        <div>
          <h1>Mis hojas</h1>
          <p className="tenue">Tus libros de cálculo. Solo tú los ves{admin ? ' (como administrador puedes ver los de todos)' : ''}.</p>
        </div>
        <div className="acciones-pagina">
          {puede('hojas.crear') && <button className="btn btn-sec" onClick={() => setImportar(true)}>⇪ Importar Excel</button>}
          {puede('hojas.crear') && <button className="btn" onClick={() => setNueva(true)}>+ Nuevo libro</button>}
        </div>
      </header>

      <div className="barra-busqueda">
        <input type="search" placeholder="Buscar por nombre…" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Buscar libros" />
        {admin && (
          <label className="check"><input type="checkbox" checked={todos} onChange={(e) => setTodos(e.target.checked)} /> Ver los de todos los usuarios</label>
        )}
      </div>

      {error && <Aviso tipo="error">{error}</Aviso>}
      {isLoading && <p>Cargando…</p>}
      {!isLoading && libros.length === 0 && (
        <div className="vacio">
          {q ? 'Ningún libro coincide con la búsqueda.' : 'Aún no tienes libros. Crea uno en blanco, desde una plantilla o importa un Excel.'}
        </div>
      )}

      <ul className="tarjetas-libros">
        {libros.map((l) => (
          <li key={l.id} className="tarjeta-libro">
            <Link to={`/hojas/${l.id}`} className="tarjeta-libro-enlace">
              <span className={`tarjeta-libro-icono origen-${l.origen}`} aria-hidden>{ORIGEN[l.origen].icono}</span>
              <span className="tarjeta-libro-texto">
                <strong>{l.nombre}</strong>
                <small>{ORIGEN[l.origen].texto} · {l.hojas} pestaña{l.hojas === 1 ? '' : 's'}</small>
                <small className="tenue">Modificado {l.actualizado}{l.user_id !== usuario?.id ? ` · de ${l.autor}` : ''}</small>
              </span>
            </Link>
            {puede('hojas.crear') && !l.es_personal && (
              <button className="btn-icono" aria-label={`Eliminar ${l.nombre}`} title="Eliminar"
                onClick={() => confirm(`¿Eliminar el libro «${l.nombre}» con todas sus pestañas?`) && eliminar.mutate(l.id)}>🗑</button>
            )}
          </li>
        ))}
      </ul>

      <NuevoLibro abierto={nueva} onCerrar={() => setNueva(false)} onCreado={(id) => { setNueva(false); nav(`/hojas/${id}`); }} />
      <ImportarExcelModal abierto={importar} onCerrar={() => setImportar(false)} />
    </section>
  );
}

function NuevoLibro({ abierto, onCerrar, onCreado }: { abierto: boolean; onCerrar: () => void; onCreado: (id: number) => void }) {
  const qc = useQueryClient();
  const [nombre, setNombre] = useState('');
  const [plantilla, setPlantilla] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const { data: plantillas = [] } = useQuery({ queryKey: ['hoja-plantillas'], queryFn: hojasApi.plantillas, enabled: abierto });

  const crear = useMutation({
    mutationFn: () => librosApi.crear(nombre.trim(), plantilla ?? undefined),
    onSuccess: (d) => { void qc.invalidateQueries({ queryKey: ['libros'] }); setNombre(''); setPlantilla(null); onCreado(d.libro.id); },
    onError: (e) => setError(mensajeDeError(e)),
  });

  const elegir = (id: number | null, sugerido: string) => {
    setPlantilla(id);
    if (!nombre.trim() || plantillas.some((p) => p.nombre === nombre) || nombre === 'Libro en blanco') setNombre(sugerido);
  };

  return (
    <Modal titulo="Nuevo libro" abierto={abierto} onCerrar={onCerrar} ancho="md"
      pie={<>
        <button className="btn btn-sec" onClick={onCerrar}>Cancelar</button>
        <button className="btn" disabled={!nombre.trim() || crear.isPending} onClick={() => crear.mutate()}>{crear.isPending ? 'Creando…' : 'Crear'}</button>
      </>}>
      {error && <Aviso tipo="error">{error}</Aviso>}
      <label>Nombre del libro
        <input value={nombre} onChange={(e) => setNombre(e.target.value)} maxLength={120} placeholder="Ej. Asistencia noviembre" />
      </label>
      <p className="tenue">Empieza con:</p>
      <div className="plantillas">
        <button type="button" className={`plantilla ${plantilla === null ? 'activa' : ''}`} onClick={() => elegir(null, 'Libro en blanco')}>
          <strong>En blanco</strong><small>100 filas × 26 columnas</small>
        </button>
        {plantillas.map((p) => (
          <button type="button" key={p.id} className={`plantilla ${plantilla === p.id ? 'activa' : ''}`} onClick={() => elegir(p.id, p.nombre)}>
            <strong>{p.nombre}</strong><small>{p.descripcion}</small>
          </button>
        ))}
      </div>
    </Modal>
  );
}
