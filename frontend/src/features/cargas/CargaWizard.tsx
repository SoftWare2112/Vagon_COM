import { useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { cargasApi, columnasApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { Modal } from '../../components/Modal';
import { Aviso } from '../../components/Aviso';
import { useOnline } from '../../hooks/useOnline';
import { useAuth } from '../../auth/AuthContext';
import type { Carga, MensajeCarga } from '../../types';

type Paso = 'elegir' | 'resultado' | 'listo';
const EXT_OK = /\.(xlsx|xls)$/i;

/**
 * Asistente ETL: 1) elegir archivo  2) validar (errores con celda | advertencias editables)
 * 3) vista previa del impacto  4) elegir Reemplazar/Mantener  5) aplicar.
 */
export function CargaWizard({ abierto, onCerrar }: { abierto: boolean; onCerrar: () => void }) {
  const qc = useQueryClient();
  const online = useOnline();
  const { puede } = useAuth();
  const input = useRef<HTMLInputElement>(null);
  const [paso, setPaso] = useState<Paso>('elegir');
  const [archivo, setArchivo] = useState<File | null>(null);
  const [anio, setAnio] = useState(new Date().getFullYear());
  const [progreso, setProgreso] = useState(0);
  const [hoja, setHoja] = useState<string | undefined>(undefined);   // libros con varias hojas
  const [carga, setCarga] = useState<Carga | null>(null);
  const [modo, setModo] = useState<'REEMPLAZAR' | 'MANTENER' | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [resumenFinal, setResumenFinal] = useState<Record<string, number> | null>(null);

  const reiniciar = () => {
    setPaso('elegir'); setArchivo(null); setCarga(null); setModo(null); setError(null); setProgreso(0); setResumenFinal(null); setHoja(undefined);
  };
  const cerrar = () => {
    if (carga?.estatus === 'LISTA' && paso !== 'listo') void cargasApi.descartar(carga.id).catch(() => undefined);
    reiniciar();
    onCerrar();
  };

  const elegir = (f: File | undefined) => {
    setError(null);
    if (!f) return;
    if (!EXT_OK.test(f.name)) {
      setError('Archivo incorrecto: selecciona un archivo de Excel (.xlsx). No se aceptan Word, PowerPoint ni .odt.');
      return;
    }
    if (f.size > 5 * 1024 * 1024) {
      setError('El archivo supera 5 MB.');
      return;
    }
    setArchivo(f);
    setHoja(undefined);
  };


  const subir = useMutation({
    mutationFn: (hojaElegida?: string) => {
      if (!navigator.onLine) throw new Error('offline');
      return cargasApi.subir(archivo!, anio, setProgreso, hojaElegida ?? hoja);
    },
    onSuccess: (c) => { setCarga(c); setPaso('resultado'); },
    onError: (e) => setError(e instanceof Error && e.message === 'offline'
      ? 'Sin conexión: no se puede cargar el archivo. Conserva el archivo seleccionado y reintenta cuando vuelva la red.'
      : mensajeDeError(e)),
  });

  /** Volver a validar el mismo archivo leyendo otra hoja del libro. */
  const validarHoja = (nombre: string) => {
    if (carga?.estatus === 'LISTA') void cargasApi.descartar(carga.id).catch(() => undefined);
    setHoja(nombre);
    setCarga(null);
    setPaso('elegir');
    subir.mutate(nombre);
  };

  const preview = useQuery({
    queryKey: ['preview', carga?.id, carga?.num_advertencias],
    queryFn: () => cargasApi.previsualizar(carga!.id),
    enabled: carga?.estatus === 'LISTA',
  });

  const aplicar = useMutation({
    mutationFn: () => cargasApi.aplicar(carga!.id, modo!),
    onSuccess: (r) => {
      setResumenFinal(r.resumen);
      setPaso('listo');
      void qc.invalidateQueries({ queryKey: ['registros'] });
      void qc.invalidateQueries({ queryKey: ['periodos'] });
    },
    onError: (e) => setError(mensajeDeError(e)),
  });

  const errores = carga?.mensajes?.filter((m) => m.nivel === 'ERROR') ?? [];
  const advertencias = carga?.mensajes?.filter((m) => m.nivel === 'ADVERTENCIA') ?? [];
  const desconocidas = advertencias.filter((m) => m.codigo === 'ENCABEZADO_DESCONOCIDO');
  const previa = preview.data;
  const modoElegido = modo ?? previa?.modo_predeterminado ?? null;

  return (
    <Modal titulo="Cargar reporte de Excel" abierto={abierto} onCerrar={cerrar} ancho="lg"
      pie={<>
        <button className="btn btn-sec" onClick={cerrar}>{paso === 'listo' ? 'Cerrar' : 'Cancelar'}</button>
        {paso === 'elegir' && (
          <button className="btn" disabled={!archivo || subir.isPending || !online} onClick={() => subir.mutate(undefined)}>
            {subir.isPending ? `Validando… ${progreso}%` : 'Validar archivo'}
          </button>
        )}
        {paso === 'resultado' && errores.length > 0 && <button className="btn" onClick={reiniciar}>Subir archivo corregido</button>}
        {paso === 'resultado' && carga?.estatus === 'LISTA' && (
          <button className="btn" disabled={!modoElegido || aplicar.isPending}
            onClick={() => { setModo(modoElegido); aplicar.mutate(); }}>
            {aplicar.isPending ? 'Aplicando…' : `Aplicar (${modoElegido === 'REEMPLAZAR' ? 'Reemplazar' : 'Mantener'})`}
          </button>
        )}
      </>}>
      {error && <Aviso tipo="error">{error}</Aviso>}
      {!online && <Aviso tipo="advertencia">Sin conexión a internet.</Aviso>}

      {paso === 'elegir' && (
        <div className="zona-archivo"
          onDragOver={(e) => e.preventDefault()}
          onDrop={(e) => { e.preventDefault(); elegir(e.dataTransfer.files[0]); }}
          onClick={() => input.current?.click()} role="button" tabIndex={0}
          onKeyDown={(e) => e.key === 'Enter' && input.current?.click()}>
          <input ref={input} type="file" hidden
            accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel"
            onChange={(e) => elegir(e.target.files?.[0])} />
          <p><strong>{archivo ? archivo.name : 'Arrastra el archivo aquí o toca para seleccionarlo'}</strong></p>
          <p className="tenue">Solo Excel (.xlsx), máximo 5 MB</p>
          <label onClick={(e) => e.stopPropagation()} title="Si el encabezado trae el año (p. ej. '1 de octubre al 13 de octubre de 2026'), se usa ese">Año, si el encabezado del periodo no lo trae{' '}
            <input type="number" min={2020} max={2100} value={anio} onChange={(e) => setAnio(Number(e.target.value))} style={{ width: 90 }} />
          </label>
        </div>
      )}

      {paso === 'resultado' && carga && (
        <>
          <p><strong>{carga.archivo}</strong>{carga.hoja ? ` · hoja "${carga.hoja}"` : ''} · {carga.filas_total} filas · {carga.periodo?.etiqueta ?? 'periodo no detectado'}</p>

          {(carga.hojas?.length ?? 0) > 1 && (
            <div className="editor-inline bloque">
              <label>El libro tiene {carga.hojas!.length} hojas. Hoja a leer
                <select value={carga.hoja ?? ''} onChange={(e) => validarHoja(e.target.value)} style={{ width: 'auto' }}>
                  {!carga.hoja && <option value="">Elige una hoja…</option>}
                  {carga.hojas!.map((h) => <option key={h} value={h}>{h}</option>)}
                </select>
              </label>
              <small className="tenue">Las demás hojas se ignoran y no se guardan.</small>
            </div>
          )}

          {errores.length > 0 && (
            <>
              <Aviso tipo="error">Se encontraron {errores.length} error(es). No se aplicó ningún cambio. Corrige el archivo y vuelve a subirlo.</Aviso>
              <div className="tabla-scroll"><table className="tabla tabla-compacta">
                <thead><tr><th>Celda</th><th>Columna</th><th>Problema</th><th>Valor recibido</th></tr></thead>
                <tbody>{errores.map((m, i) => (
                  <tr key={i}><td className="mono">{m.celda ?? (m.fila ? `Fila ${m.fila}` : '—')}</td><td>{m.columna ?? '—'}</td><td>{m.mensaje}</td><td>{m.valor_recibido ?? ''}</td></tr>
                ))}</tbody>
              </table></div>
            </>
          )}

          {desconocidas.length > 0 && puede('columnas.gestionar') && (
            <ColumnasNuevas mensajes={desconocidas} onCreadas={() => {
              if (carga.estatus === 'LISTA') void cargasApi.descartar(carga.id).catch(() => undefined);
              setCarga(null); setPaso('elegir'); subir.mutate(undefined);
            }} />
          )}

          {carga.estatus === 'LISTA' && (
            <>
              {advertencias.length > 0 && (
                <details open className="bloque">
                  <summary>{advertencias.length} advertencia(s) — no bloquean la carga</summary>
                  <ul className="lista-mensajes">{advertencias.map((m, i) => <li key={i}>{m.celda && <span className="mono">{m.celda} </span>}{m.mensaje}</li>)}</ul>
                </details>
              )}
              {previa?.quincena_nueva && <Aviso tipo="info">Es una quincena nueva: se creará {previa.periodo.etiqueta} y las quincenas anteriores no se tocan.</Aviso>}

              <h3>¿Cómo aplicar los datos?</h3>
              {preview.isLoading && <p>Calculando impacto…</p>}
              {previa && (
                <div className="opciones-modo">
                  {(['MANTENER', 'REEMPLAZAR'] as const).map((m) => {
                    const imp = previa.impacto[m];
                    return (
                      <label key={m} className={`opcion-modo ${modoElegido === m ? 'activa' : ''}`}>
                        <input type="radio" name="modo" checked={modoElegido === m} onChange={() => setModo(m)} />
                        <strong>{m === 'MANTENER' ? 'Mantener información' : 'Reemplazar información'}</strong>
                        <small>{m === 'MANTENER'
                          ? 'Conserva lo creado, editado o eliminado en la web. Solo agrega nuevos y actualiza los campos que no se editaron.'
                          : 'El Excel manda en esta quincena: sobrescribe ediciones de las columnas que vienen, restaura eliminados que vengan en el archivo y quita de la vista a quien no venga. Otras quincenas no se tocan.'}</small>
                        <ul className="impacto">
                          <li>Nuevos: <b>{imp.nuevos}</b></li>
                          <li>Actualizados: <b>{imp.actualizados}</b></li>
                          {m === 'REEMPLAZAR' && <li>Restaurados: <b>{imp.restaurados}</b></li>}
                          {m === 'REEMPLAZAR' && <li className={imp.eliminados ? 'texto-peligro' : ''}>Saldrán de la vista: <b>{imp.eliminados}</b></li>}
                          {m === 'MANTENER' && <li>Omitidos (eliminados en web): <b>{imp.omitidos}</b></li>}
                        </ul>
                        {m === 'REEMPLAZAR' && imp.detalle.eliminados?.length > 0 && (
                          <small className="tenue">Ej.: {imp.detalle.eliminados.slice(0, 5).join(', ')}{imp.detalle.eliminados.length > 5 ? '…' : ''}</small>
                        )}
                      </label>
                    );
                  })}
                </div>
              )}
            </>
          )}
        </>
      )}

      {paso === 'listo' && resumenFinal && (
        <Aviso tipo="exito">
          Carga aplicada. Nuevos: {resumenFinal.nuevos} · Actualizados: {resumenFinal.actualizados} · Restaurados: {resumenFinal.restaurados} ·
          Eliminados de la vista: {resumenFinal.eliminados} · Omitidos: {resumenFinal.omitidos}
        </Aviso>
      )}
    </Modal>
  );
}

/**
 * El archivo trae columnas que la plataforma no conoce: RH puede crearlas aquí mismo
 * (se agregan al final; luego se pueden mover en Ajustes > Columnas) y volver a validar.
 */
function ColumnasNuevas({ mensajes, onCreadas }: { mensajes: MensajeCarga[]; onCreadas: () => void }) {
  const [tipos, setTipos] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const crear = useMutation({
    mutationFn: async () => {
      for (const m of mensajes) {
        const etiqueta = m.valor_recibido ?? m.columna ?? '';
        if (tipos[etiqueta] === 'ignorar') continue;
        await columnasApi.crear({ etiqueta, tipo: (tipos[etiqueta] ?? 'texto') as never });
      }
    },
    onSuccess: onCreadas,
    onError: (e) => setError(mensajeDeError(e)),
  });

  return (
    <details className="bloque" open>
      <summary>{mensajes.length} columna(s) nueva(s) en el archivo</summary>
      {error && <Aviso tipo="error">{error}</Aviso>}
      <ul className="lista-mensajes">
        {mensajes.map((m) => {
          const etiqueta = m.valor_recibido ?? m.columna ?? '';
          return (
            <li key={etiqueta} className="editor-inline">
              <strong>{etiqueta}</strong>
              <select value={tipos[etiqueta] ?? 'texto'} onChange={(e) => setTipos({ ...tipos, [etiqueta]: e.target.value })} style={{ width: 'auto' }}>
                <option value="texto">Texto</option><option value="dinero">Dinero ($)</option><option value="decimal">Número</option>
                <option value="entero">Entero</option><option value="fecha">Fecha</option><option value="booleano">Sí / No</option>
                <option value="ignorar">No crear (ignorar)</option>
              </select>
            </li>
          );
        })}
      </ul>
      <button className="btn btn-sm" disabled={crear.isPending} onClick={() => crear.mutate()}>Crear columnas y validar de nuevo</button>
    </details>
  );
}
