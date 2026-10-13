import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { columnasApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { Aviso } from '../../components/Aviso';
import { CambioTipoModal, NOMBRE_TIPO } from './CambioTipoModal';
import type { ColumnaCatalogo, TipoColumna } from '../../types';

const TIPOS = (Object.keys(NOMBRE_TIPO) as TipoColumna[]).map((v) => ({ v, t: NOMBRE_TIPO[v] }));
const ORIGEN: Record<string, string> = { sistema: 'Sistema', personalizada: 'Agregada', domingos: 'Dinámica', periodo: 'Dinámica' };

/**
 * Columnas del reporte. Insertar una columna en una posición NO mueve ni altera datos:
 * cada valor se guarda por la clave de su columna, no por su lugar.
 */
export function ColumnasPanel() {
  const qc = useQueryClient();
  const { data: columnas = [] } = useQuery({ queryKey: ['columnas'], queryFn: columnasApi.listar });
  const [nueva, setNueva] = useState<{ etiqueta: string; tipo: TipoColumna; despues_de: string }>({ etiqueta: '', tipo: 'texto', despues_de: '' });
  const [aviso, setAviso] = useState<{ tipo: 'exito' | 'error'; texto: string } | null>(null);
  const [cambioTipo, setCambioTipo] = useState<{ columna: ColumnaCatalogo; tipo: TipoColumna } | null>(null);

  const refrescar = () => {
    void qc.invalidateQueries({ queryKey: ['columnas'] });
    void qc.invalidateQueries({ queryKey: ['registros'] });
  };
  const accion = useMutation({
    mutationFn: (fn: () => Promise<unknown>) => fn(),
    onSuccess: () => { refrescar(); setAviso({ tipo: 'exito', texto: 'Columnas actualizadas.' }); },
    onError: (e) => setAviso({ tipo: 'error', texto: mensajeDeError(e) }),
  });

  return (
    <div className="tarjeta-form" style={{ width: '100%', maxWidth: 'none' }}>
      <h2>Columnas del reporte</h2>
      <p className="tenue">El orden de esta lista es el orden de la tabla, del Excel exportado y del PDF. Las columnas que agregues se reconocen en los próximos archivos por su encabezado, en cualquier posición.</p>
      {aviso && <Aviso tipo={aviso.tipo}>{aviso.texto}</Aviso>}

      <form className="filtros" onSubmit={(e) => {
        e.preventDefault();
        accion.mutate(async () => {
          await columnasApi.crear({ etiqueta: nueva.etiqueta.trim(), tipo: nueva.tipo, despues_de: nueva.despues_de || undefined });
          setNueva({ etiqueta: '', tipo: 'texto', despues_de: '' });
        });
      }}>
        <label>Nueva columna<input required maxLength={100} value={nueva.etiqueta} onChange={(e) => setNueva({ ...nueva, etiqueta: e.target.value })} placeholder="Ej. Bono transporte" /></label>
        <label>Tipo
          <select value={nueva.tipo} onChange={(e) => setNueva({ ...nueva, tipo: e.target.value as TipoColumna })}>
            {TIPOS.map((t) => <option key={t.v} value={t.v}>{t.t}</option>)}
          </select>
        </label>
        <label>Insertar después de
          <select value={nueva.despues_de} onChange={(e) => setNueva({ ...nueva, despues_de: e.target.value })}>
            <option value="">Al final</option>
            {columnas.filter((c) => c.activa).map((c) => <option key={c.clave} value={c.clave}>{c.etiqueta}</option>)}
          </select>
        </label>
        <div className="filtros-acciones"><button className="btn" disabled={accion.isPending || !nueva.etiqueta.trim()}>Agregar</button></div>
      </form>

      <div className="tabla-scroll"><table className="tabla tabla-compacta">
        <thead><tr><th>#</th><th>Columna</th><th>Tipo</th><th>Origen</th><th>Visible</th><th>Orden</th><th /></tr></thead>
        <tbody>{columnas.map((c, i) => (
          <tr key={c.id} className={c.activa ? undefined : 'tenue'}>
            <td>{c.orden}</td>
            <td>
              <input defaultValue={c.etiqueta} maxLength={100} aria-label={`Nombre de ${c.etiqueta}`}
                onBlur={(e) => e.target.value.trim() && e.target.value !== c.etiqueta && accion.mutate(() => columnasApi.actualizar(c.id, { etiqueta: e.target.value.trim() }))} />
            </td>
            <td>
              {c.origen === 'personalizada' && c.activa ? (
                // Las columnas agregadas nacen como Texto; aquí se cambian a su tipo real convirtiendo lo ya capturado
                <select value={c.tipo} aria-label={`Tipo de ${c.etiqueta}`} style={{ width: 'auto' }}
                  onChange={(e) => setCambioTipo({ columna: c, tipo: e.target.value as TipoColumna })}>
                  {TIPOS.map((t) => <option key={t.v} value={t.v}>{t.t}</option>)}
                </select>
              ) : NOMBRE_TIPO[c.tipo]}
            </td>
            <td>{ORIGEN[c.origen]}{c.obligatoria ? ' · obligatoria' : ''}</td>
            <td className="centro">
              <input type="checkbox" checked={c.visible} disabled={c.obligatoria} style={{ width: 'auto', minHeight: 'auto' }}
                onChange={(e) => accion.mutate(() => columnasApi.actualizar(c.id, { visible: e.target.checked }))} aria-label={`Mostrar ${c.etiqueta}`} />
            </td>
            <td className="acciones">
              <button className="btn-icono" disabled={i === 0} onClick={() => accion.mutate(() => columnasApi.mover(c.id, c.orden - 1))} aria-label="Subir">▲</button>
              <button className="btn-icono" disabled={i === columnas.length - 1} onClick={() => accion.mutate(() => columnasApi.mover(c.id, c.orden + 1))} aria-label="Bajar">▼</button>
            </td>
            <td>{c.origen === 'personalizada' && (
              <button className="btn btn-sec btn-sm" onClick={() => accion.mutate(() => columnasApi.activarDesactivar(c.id))}>
                {c.activa ? 'Desactivar' : 'Reactivar'}
              </button>
            )}</td>
          </tr>
        ))}</tbody>
      </table></div>
      {cambioTipo && <CambioTipoModal key={`${cambioTipo.columna.id}-${cambioTipo.tipo}`} columna={cambioTipo.columna} tipo={cambioTipo.tipo} onCerrar={() => setCambioTipo(null)} />}
      <p className="tenue">Las columnas agregadas se crean como Texto; cambia su tipo cuando quieras (Dinero, Fecha, Sí/No…) y la plataforma convierte lo ya capturado.</p>
      <p className="tenue">Desactivar una columna la oculta y deja de leerse en las cargas, pero sus datos se conservan y puedes reactivarla.</p>
    </div>
  );
}
