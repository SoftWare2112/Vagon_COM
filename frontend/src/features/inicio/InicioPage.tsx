import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';
import { cargasApi, librosApi, publicacionesApi } from '../../api/endpoints';
import { useAuth } from '../../auth/AuthContext';
import { EditorLibro } from '../hojas/EditorLibro';
import { DashboardPage } from '../registros/DashboardPage';

type Pestana = 'mi-hoja' | 'incidencias' | 'recientes';
const CLAVE = 'inicio.pestana';

function leer(): Pestana | null {
  try { return (localStorage.getItem(CLAVE) as Pestana | null) ?? null; } catch { return null; }
}
function guardar(p: Pestana) {
  try { localStorage.setItem(CLAVE, p); } catch { /* sin almacenamiento: no pasa nada */ }
}

/**
 * v9 — Pantalla de Inicio: Dashboard y Hojas juntos.
 *  - Mi hoja: una hoja ya creada para escribir, armar una tabla o importar un Excel.
 *  - Incidencias: la tabla de la quincena (la nómina es compartida).
 *  - Recientes: últimos libros e importaciones.
 * Se recuerda la última pestaña elegida (en este navegador).
 */
export function InicioPage() {
  const { puede, usuario } = useAuth();
  const [params, setParams] = useSearchParams();
  const disponibles: { id: Pestana; texto: string }[] = [
    ...(puede('hojas.crear') ? [{ id: 'mi-hoja' as const, texto: 'Mi hoja' }] : []),
    ...(puede('registros.ver') ? [{ id: 'incidencias' as const, texto: 'Incidencias' }] : []),
    ...(puede('hojas.ver') ? [{ id: 'recientes' as const, texto: 'Recientes' }] : []),
  ];
  const pedida = (params.get('pestana') as Pestana | null) ?? leer();
  const [pestana, setPestana] = useState<Pestana | undefined>(
    disponibles.find((d) => d.id === pedida)?.id ?? disponibles[0]?.id,
  );

  if (!pestana) {
    return <div className="vacio">Tu usuario aún no tiene acceso a ningún apartado. Pide a un administrador que te asigne un rol.</div>;
  }

  const elegir = (p: Pestana) => { setPestana(p); guardar(p); setParams({ pestana: p }, { replace: true }); };

  return (
    <section className="inicio">
      <div className="inicio-cab">
        <h1>Hola, {usuario?.name?.split(' ')[0]}</h1>
        {disponibles.length > 1 && (
          <div className="segmentado" role="tablist" aria-label="Apartados de inicio">
            {disponibles.map((d) => (
              <button key={d.id} type="button" role="tab" aria-selected={pestana === d.id} className={pestana === d.id ? 'activo' : ''} onClick={() => elegir(d.id)}>
                {d.texto}
              </button>
            ))}
          </div>
        )}
      </div>
      {pestana === 'mi-hoja' && <EditorLibro personal />}
      {pestana === 'incidencias' && <DashboardPage incrustado />}
      {pestana === 'recientes' && <Recientes />}
    </section>
  );
}

function Recientes() {
  const { puede } = useAuth();
  const libros = useQuery({ queryKey: ['libros', { q: '', todos: false }], queryFn: () => librosApi.listar() });
  const importaciones = useQuery({
    queryKey: ['cargas', 'recientes'], queryFn: () => cargasApi.historial({ orden: 'reciente' }),
    enabled: puede('cargas.ver') || puede('hojas.crear'),
  });

  const publicaciones = useQuery({ queryKey: ['publicaciones', { vista: 'todas', page: 1 }], queryFn: () => publicacionesApi.listar({ vista: 'todas', page: 1 }), enabled: puede('publicaciones.ver') });

  return (
    <div className="recientes">
      {publicaciones.data && (
        <section>
          <div className="seccion-cab"><h2>Publicaciones</h2><Link to="/publicaciones" className="btn-link">Ver todas</Link></div>
          {publicaciones.data.data.length === 0 && <p className="tenue">Sin publicaciones todavía.</p>}
          <ul className="lista-simple">
            {publicaciones.data.data.slice(0, 5).map((p) => (
              <li key={p.id}>
                <Link to={`/publicaciones/${p.id}`}>{!p.leida && <span className="punto-nuevo" aria-label="Nueva" />}<strong>{p.titulo}</strong></Link>
                <small className="tenue">{p.autor.nombre}{p.publicada ? ` · ${p.publicada}` : ''}</small>
              </li>
            ))}
          </ul>
        </section>
      )}
      <section>
        <div className="seccion-cab"><h2>Libros recientes</h2><Link to="/hojas" className="btn-link">Ver todos</Link></div>
        {libros.data?.length === 0 && <p className="tenue">Aún no tienes libros.</p>}
        <ul className="lista-simple">
          {libros.data?.slice(0, 6).map((l) => (
            <li key={l.id}><Link to={`/hojas/${l.id}`}><strong>{l.nombre}</strong></Link><small className="tenue">{l.hojas} pestaña(s) · {l.actualizado}</small></li>
          ))}
        </ul>
      </section>
      {importaciones.data && (
        <section>
          <div className="seccion-cab"><h2>Últimas importaciones</h2><Link to="/informes" className="btn-link">Ver historial</Link></div>
          {importaciones.data.data.length === 0 && <p className="tenue">Sin importaciones todavía.</p>}
          <ul className="lista-simple">
            {importaciones.data.data.slice(0, 5).map((c) => (
              <li key={c.id}>
                <span>{c.tipo === 'libro' ? '▦' : '📋'} <strong>{c.archivo}</strong></span>
                <small className="tenue">{c.tipo === 'libro' ? 'Excel como hoja' : 'Incidencias de nómina'} · {c.subida}{c.usuario ? ` · ${c.usuario}` : ''}</small>
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  );
}
