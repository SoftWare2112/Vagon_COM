import { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { hojasApi, librosApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { useAuth } from '../../auth/AuthContext';
import { Aviso } from '../../components/Aviso';
import type { LibroDetalle } from '../../types';
import { EditorHoja } from './EditorHoja';

/**
 * v9 — Un libro con sus pestañas (como Excel). Cada pestaña se edita con EditorHoja.
 *  - personal: "Mi hoja" de la pantalla de Inicio (se crea la primera vez).
 *  - Solo el dueño (o el Administrador, con control total) puede abrirlo.
 */
export function EditorLibro({ libroId, personal = false }: { libroId?: number; personal?: boolean }) {
  const { puede, usuario } = useAuth();
  const qc = useQueryClient();
  const editable = puede('hojas.editar');
  const clave = ['libro', personal ? 'personal' : libroId];
  const { data, isLoading, isError, error } = useQuery({
    queryKey: clave,
    queryFn: () => (personal ? librosApi.personal() : librosApi.obtener(libroId!)),
    staleTime: Infinity,
  });
  const [activa, setActiva] = useState<number | null>(null);
  const [nombre, setNombre] = useState('');
  const [renombrando, setRenombrando] = useState<{ id: number; texto: string } | null>(null);
  const [aviso, setAviso] = useState<string | null>(null);

  useEffect(() => {
    if (!data) return;
    setNombre(data.libro.nombre);
    setActiva((a) => (a && data.hojas.some((h) => h.id === a) ? a : data.hojas[0]?.id ?? null));
  }, [data]);

  const actualizar = (d: LibroDetalle) => {
    qc.setQueryData(clave, d);
    void qc.invalidateQueries({ queryKey: ['libros'] });
  };
  const intentar = async (accion: () => Promise<void>) => {
    try { setAviso(null); await accion(); } catch (e) { setAviso(mensajeDeError(e)); }
  };

  if (isLoading) return <p>Abriendo…</p>;
  if (isError || !data) return <Aviso tipo="error">{mensajeDeError(error)}</Aviso>;

  const ajeno = data.libro.user_id !== usuario?.id;

  const guardarNombre = () => intentar(async () => {
    const n = nombre.trim();
    if (!n || n === data.libro.nombre) { setNombre(data.libro.nombre); return; }
    await librosApi.renombrar(data.libro.id, n);
    actualizar({ ...data, libro: { ...data.libro, nombre: n } });
  });
  const agregar = () => intentar(async () => {
    const d = await librosApi.agregarHoja(data.libro.id);
    actualizar(d);
    if (d.nueva) setActiva(d.nueva);
  });
  const renombrarPestana = () => intentar(async () => {
    if (!renombrando) return;
    const n = renombrando.texto.trim();
    setRenombrando(null);
    const actual = data.hojas.find((h) => h.id === renombrando.id);
    if (!n || n === actual?.nombre) return;
    await hojasApi.renombrar(renombrando.id, n);
    actualizar({ ...data, hojas: data.hojas.map((h) => (h.id === renombrando.id ? { ...h, nombre: n } : h)) });
  });
  const quitarPestana = (id: number, nombreHoja: string) => intentar(async () => {
    if (!confirm(`¿Eliminar la pestaña «${nombreHoja}» y todo su contenido? No se puede deshacer.`)) return;
    actualizar(await hojasApi.eliminar(id));
  });

  const cabecera = (
    <>
      {personal
        ? <h2 className="titulo-libro">Mi hoja <small className="tenue">· tu espacio para escribir, armar tablas y hacer cálculos</small></h2>
        : <>
          <Link to="/hojas" className="btn btn-sec btn-sm">← Mis hojas</Link>
          <input className="nombre-hoja" aria-label="Nombre del libro" value={nombre} disabled={!editable} maxLength={120}
            onChange={(e) => setNombre(e.target.value)} onBlur={() => void guardarNombre()}
            onKeyDown={(e) => { if (e.key === 'Enter') e.currentTarget.blur(); }} />
        </>}
      {ajeno && <span className="etiqueta etiqueta-info" title="Lo ves porque eres administrador">Libro de {data.libro.autor}</span>}
    </>
  );

  const pestanas = (
    <nav className="pestanas-hoja" aria-label="Pestañas del libro">
      {data.hojas.map((h) => (
        renombrando?.id === h.id
          ? <input key={h.id} className="pestana-input" autoFocus value={renombrando.texto} maxLength={60} aria-label="Nombre de la pestaña"
              onChange={(e) => setRenombrando({ id: h.id, texto: e.target.value })} onBlur={() => void renombrarPestana()}
              onKeyDown={(e) => { if (e.key === 'Enter') e.currentTarget.blur(); if (e.key === 'Escape') setRenombrando(null); }} />
          : <span key={h.id} className={`pestana ${h.id === activa ? 'activa' : ''}`}>
              <button type="button" onClick={() => setActiva(h.id)} title={editable ? 'Doble clic para cambiar el nombre' : h.nombre}
                onDoubleClick={() => editable && setRenombrando({ id: h.id, texto: h.nombre })}>{h.nombre}</button>
              {editable && h.id === activa && data.hojas.length > 1 && (
                <button type="button" className="pestana-quitar" aria-label={`Eliminar la pestaña ${h.nombre}`} onClick={() => void quitarPestana(h.id, h.nombre)}>✕</button>
              )}
            </span>
      ))}
      {editable && <button type="button" className="pestana-nueva" onClick={() => void agregar()} aria-label="Agregar pestaña" title="Agregar pestaña">+</button>}
    </nav>
  );

  return (
    <>
      {aviso && <Aviso tipo="error">{aviso} <button className="btn-link" onClick={() => setAviso(null)}>Cerrar</button></Aviso>}
      {activa && <EditorHoja key={activa} hojaId={activa} cabecera={cabecera} pie={pestanas} />}
    </>
  );
}

/** Ruta /hojas/:id */
export function LibroPage() {
  return <EditorLibro libroId={Number(useParams().id)} />;
}
