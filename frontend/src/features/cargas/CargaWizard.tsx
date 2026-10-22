import { useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { cargasApi, columnasApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { Modal } from '../../components/Modal';
import { Aviso } from '../../components/Aviso';
import { useOnline } from '../../hooks/useOnline';
import { useAuth } from '../../auth/AuthContext';
import { esFechaValida, mascaraFecha } from '../../utils/formato';
import type { Carga, ColumnaCatalogo, ColumnaDesconocida, TipoColumna } from '../../types';

type Paso = 'elegir' | 'resultado' | 'listo';
const EXT_OK = /\.(xlsx|xls)$/i;
const MAX_MB = 10;
/** Errores que se resuelven escribiendo las fechas de la quincena en el asistente. */
const ERRORES_PERIODO = ['PERIODO_NO_DETECTADO', 'PERIODO_INVALIDO', 'PERIODO_FECHAS_DISTINTAS', 'DOMINGO_FUERA_DE_PERIODO'];

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
  // Fechas de la quincena escritas a mano (dd/mm/aaaa) cuando el encabezado del periodo está mal
  const [fechaIni, setFechaIni] = useState('');
  const [fechaFin, setFechaFin] = useState('');
  const [confirmado, setConfirmado] = useState(false);

  const fechasListas = esFechaValida(fechaIni) && esFechaValida(fechaFin);
  const periodoManual = fechasListas ? { inicio: fechaIni, fin: fechaFin } : undefined;

  const reiniciar = () => {
    setPaso('elegir'); setArchivo(null); setCarga(null); setModo(null); setError(null); setProgreso(0); setResumenFinal(null); setHoja(undefined);
    setFechaIni(''); setFechaFin(''); setConfirmado(false);
  };
  const cerrar = () => {
    if (carga?.estatus === 'LISTA' && paso !== 'listo') void cargasApi.descartar(carga.id).catch(() => undefined);
    reiniciar();
    onCerrar();
  };

  const elegir = (f: File | undefined) => {
    setError(null);
    if (!f) return;
    if (/\.pdf$/i.test(f.name) || f.type === 'application/pdf') {
      setError('El PDF es solo para imprimir y no se puede cargar: no tiene celdas ni columnas, y un dato podría quedar en la columna equivocada. Sube el Excel original (.xlsx) con el que se generó.');
      return;
    }
    if (!EXT_OK.test(f.name)) {
      setError('Archivo incorrecto: selecciona un archivo de Excel (.xlsx). No se aceptan PDF, Word, PowerPoint ni .odt.');
      return;
    }
    if (f.size > MAX_MB * 1024 * 1024) {
      setError(`El archivo supera ${MAX_MB} MB.`);
      return;
    }
    setArchivo(f);
    setHoja(undefined);
  };


  const subir = useMutation({
    mutationFn: (hojaElegida?: string) => {
      if (!navigator.onLine) throw new Error('offline');
      return cargasApi.subir(archivo!, anio, setProgreso, hojaElegida ?? hoja, periodoManual);
    },
    onSuccess: (c) => { setCarga(c); setPaso('resultado'); setConfirmado(false); },
    onError: (e) => setError(e instanceof Error && e.message === 'offline'
      ? 'Sin conexión: no se puede cargar el archivo. Conserva el archivo seleccionado y reintenta cuando vuelva la red.'
      : mensajeDeError(e)),
  });

  /** Volver a validar el mismo archivo (otra hoja, fechas escritas a mano o columnas recién asignadas). */
  const revalidar = (nombreHoja?: string) => {
    if (carga?.estatus === 'LISTA') void cargasApi.descartar(carga.id).catch(() => undefined);
    if (nombreHoja) setHoja(nombreHoja);
    setCarga(null);
    setPaso('elegir');
    subir.mutate(nombreHoja);
  };

  const preview = useQuery({
    queryKey: ['preview', carga?.id, carga?.num_advertencias],
    queryFn: () => cargasApi.previsualizar(carga!.id),
    enabled: carga?.estatus === 'LISTA',
  });

  const aplicar = useMutation({
    mutationFn: () => cargasApi.aplicar(carga!.id, modo!, confirmado),
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
  const porConfirmar = advertencias.filter((m) => m.confirmar);
  const normales = advertencias.filter((m) => !m.confirmar);
  const desconocidas = carga?.desconocidas ?? [];
  const errorDePeriodo = errores.some((m) => ERRORES_PERIODO.includes(m.codigo));
  const previa = preview.data;
  const modoElegido = modo ?? previa?.modo_predeterminado ?? null;
  const faltaConfirmar = porConfirmar.length > 0 && !confirmado;
  const p = carga?.periodo;
  const nombrePeriodo = p ? `${p.numero ? `Periodo ${p.numero}${p.ejercicio ? ` de ${p.ejercicio}` : ''} · ` : ''}${p.etiqueta}` : 'periodo no detectado';

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
          <button className="btn" disabled={!modoElegido || aplicar.isPending || faltaConfirmar}
            title={faltaConfirmar ? 'Primero confirma las advertencias marcadas' : undefined}
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
          <p className="tenue">Solo Excel (.xlsx), máximo {MAX_MB} MB. El PDF no sirve: sube el Excel original.</p>
          <label onClick={(e) => e.stopPropagation()} title="Si el encabezado trae el año (p. ej. '1 de octubre al 13 de octubre de 2026'), se usa ese">Año, si el encabezado del periodo no lo trae{' '}
            <input type="number" min={2020} max={2100} value={anio} onChange={(e) => setAnio(Number(e.target.value))} style={{ width: 90 }} />
          </label>
          <details onClick={(e) => e.stopPropagation()} open={fechasListas || undefined}>
            <summary className="tenue">Escribir las fechas de la quincena (si el encabezado del periodo está mal)</summary>
            <FechasPeriodo ini={fechaIni} fin={fechaFin} onIni={setFechaIni} onFin={setFechaFin} />
          </details>
        </div>
      )}

      {paso === 'resultado' && carga && (
        <>
          <p><strong>{carga.archivo}</strong>{carga.hoja ? ` · hoja "${carga.hoja}"` : ''} · {carga.filas_total} filas · {nombrePeriodo}</p>

          {(carga.hojas?.length ?? 0) > 1 && (
            <div className="editor-inline bloque">
              <label>El libro tiene {carga.hojas!.length} hojas. Hoja a leer
                <select value={carga.hoja ?? ''} onChange={(e) => revalidar(e.target.value)} style={{ width: 'auto' }}>
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

          {errorDePeriodo && (
            <div className="bloque">
              <strong>Escribe las fechas correctas de la quincena{p?.numero ? ` ${p.numero}` : ''} y valida de nuevo</strong>
              <p className="tenue">Se usarán en lugar del encabezado del archivo; la quincena quedará nombrada con estas fechas.</p>
              <FechasPeriodo ini={fechaIni} fin={fechaFin} onIni={setFechaIni} onFin={setFechaFin} />
              <button className="btn btn-sm" disabled={!fechasListas || subir.isPending} onClick={() => revalidar()}>
                {subir.isPending ? 'Validando…' : 'Validar con estas fechas'}
              </button>
            </div>
          )}

          {desconocidas.length > 0 && (puede('columnas.gestionar')
            ? <ColumnasNoReconocidas desconocidas={desconocidas} onListo={() => revalidar()} />
            : <Aviso tipo="advertencia">El archivo trae {desconocidas.length} columna(s) que la plataforma no reconoce ({desconocidas.map((d) => d.encabezado).join(', ')}). Pide a un administrador que las asigne.</Aviso>)}

          {carga.estatus === 'LISTA' && (
            <>
              {porConfirmar.length > 0 && (
                <div className="bloque aviso-advertencia">
                  <strong>Revisa antes de aplicar ({porConfirmar.length})</strong>
                  <ul className="lista-mensajes">{porConfirmar.map((m, i) => <li key={i}>{m.celda && <span className="mono">{m.celda} </span>}{m.mensaje}</li>)}</ul>
                  <label className="check">
                    <input type="checkbox" checked={confirmado} onChange={(e) => setConfirmado(e.target.checked)} />{' '}
                    Revisé estas advertencias y quiero aplicar la carga así
                  </label>
                </div>
              )}
              {normales.length > 0 && (
                <details className="bloque">
                  <summary>{normales.length} aviso(s) informativo(s)</summary>
                  <ul className="lista-mensajes">{normales.map((m, i) => <li key={i}>{m.celda && <span className="mono">{m.celda} </span>}{m.mensaje}</li>)}</ul>
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

/** Dos campos dd/mm/aaaa para las fechas de la quincena. */
function FechasPeriodo({ ini, fin, onIni, onFin }: { ini: string; fin: string; onIni: (v: string) => void; onFin: (v: string) => void }) {
  return (
    <div className="editor-inline">
      <label>Del
        <input inputMode="numeric" placeholder="dd/mm/aaaa" value={ini} onChange={(e) => onIni(mascaraFecha(e.target.value))} />
      </label>
      <label>Al
        <input inputMode="numeric" placeholder="dd/mm/aaaa" value={fin} onChange={(e) => onFin(mascaraFecha(e.target.value))} />
      </label>
    </div>
  );
}

const TIPOS_NUEVA: { valor: TipoColumna; texto: string }[] = [
  { valor: 'texto', texto: 'Texto' }, { valor: 'dinero', texto: 'Dinero ($)' }, { valor: 'decimal', texto: 'Número' },
  { valor: 'entero', texto: 'Entero' }, { valor: 'fecha', texto: 'Fecha' }, { valor: 'booleano', texto: 'Sí / No' },
];

/**
 * Mapeo asistido: por cada encabezado que la plataforma no reconoció, RH decide si es una columna
 * existente (se guarda como otro nombre de esa columna y la próxima vez se reconoce sola), si es
 * una columna nueva, o si se ignora. Después se valida el archivo de nuevo.
 */
function ColumnasNoReconocidas({ desconocidas, onListo }: { desconocidas: ColumnaDesconocida[]; onListo: () => void }) {
  const { data: catalogo = [] } = useQuery({ queryKey: ['columnas'], queryFn: columnasApi.listar });
  // valor: "asignar:<clave>" | "crear:<tipo>" | "ignorar"
  const [decision, setDecision] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);

  const asignables = catalogo.filter((c: ColumnaCatalogo) => c.activa && (c.origen === 'sistema' || c.origen === 'personalizada'));
  const valorDe = (d: ColumnaDesconocida) => decision[d.columna] ?? (d.sugerencias[0] ? `asignar:${d.sugerencias[0].clave}` : 'ignorar');

  const guardar = useMutation({
    mutationFn: async () => {
      for (const d of desconocidas) {
        const [accion, dato] = valorDe(d).split(':');
        if (accion === 'asignar') {
          const col = asignables.find((c) => c.clave === dato);
          if (col) await columnasApi.agregarAlias(col.id, d.encabezado);
        } else if (accion === 'crear') {
          await columnasApi.crear({ etiqueta: d.encabezado, tipo: dato as TipoColumna });
        }
      }
    },
    onSuccess: onListo,
    onError: (e) => setError(mensajeDeError(e)),
  });

  return (
    <div className="bloque">
      <strong>{desconocidas.length} columna(s) del archivo no se reconocieron</strong>
      <p className="tenue">Indica qué es cada una. Si eliges una columna existente, ese encabezado se recordará y la próxima vez se reconocerá solo.</p>
      {error && <Aviso tipo="error">{error}</Aviso>}
      <ul className="lista-mensajes">
        {desconocidas.map((d) => {
          const sugeridas = new Set(d.sugerencias.map((s) => s.clave));
          return (
            <li key={d.columna} className="editor-inline">
              <span><span className="mono">{d.columna}</span> <strong>{d.encabezado}</strong></span>
              <select value={valorDe(d)} onChange={(e) => setDecision({ ...decision, [d.columna]: e.target.value })} style={{ width: 'auto' }}>
                {d.sugerencias.length > 0 && (
                  <optgroup label="Sugeridas">
                    {d.sugerencias.map((s) => <option key={s.clave} value={`asignar:${s.clave}`}>Es «{s.etiqueta}»</option>)}
                  </optgroup>
                )}
                <optgroup label="Otra columna existente">
                  {asignables.filter((c) => !sugeridas.has(c.clave)).map((c) => <option key={c.clave} value={`asignar:${c.clave}`}>Es «{c.etiqueta}»</option>)}
                </optgroup>
                <optgroup label="Columna nueva">
                  {TIPOS_NUEVA.map((t) => <option key={t.valor} value={`crear:${t.valor}`}>Crear columna nueva · {t.texto}</option>)}
                </optgroup>
                <option value="ignorar">Ignorar (no cargar)</option>
              </select>
            </li>
          );
        })}
      </ul>
      <button className="btn btn-sm" disabled={guardar.isPending} onClick={() => guardar.mutate()}>
        {guardar.isPending ? 'Guardando…' : 'Guardar y validar de nuevo'}
      </button>
    </div>
  );
}
