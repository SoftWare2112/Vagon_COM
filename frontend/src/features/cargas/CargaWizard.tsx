import { useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { cargasApi, columnasApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { Modal } from '../../components/Modal';
import { Aviso } from '../../components/Aviso';
import { useOnline } from '../../hooks/useOnline';
import { useAuth } from '../../auth/AuthContext';
import { esFechaValida, mascaraFecha } from '../../utils/formato';
import type { Carga, ColumnaCatalogo, ColumnaDesconocida, GrupoMensaje, Previsualizacion, TipoColumna } from '../../types';

type Paso = 'subir' | 'revisar' | 'listo';
type Modo = 'REEMPLAZAR' | 'MANTENER';
const EXT_OK = /\.(xlsx|xls)$/i;
const MAX_MB = 10;
/** Errores que se resuelven escribiendo las fechas de la quincena en el asistente. */
const ERRORES_PERIODO = ['PERIODO_NO_DETECTADO', 'PERIODO_INVALIDO', 'PERIODO_FECHAS_DISTINTAS', 'DOMINGO_FUERA_DE_PERIODO'];
const PASOS: { id: Paso; texto: string }[] = [{ id: 'subir', texto: 'Subir' }, { id: 'revisar', texto: 'Revisar' }, { id: 'listo', texto: 'Listo' }];

/**
 * Asistente de carga (v7): Subir -> Revisar -> Listo.
 *  - Todo bien y quincena nueva: se carga directo, sin preguntar nada, y se avisa con un mensaje corto.
 *  - Errores: se explican agrupados y en lenguaje simple, con la solución y el Excel marcado para descargar.
 *  - Avisos a confirmar: un recuadro pequeño con "Cargar de todos modos" / "Cancelar".
 *  - Mantener / Reemplazar: solo si la quincena YA tenía datos (en una nueva dan lo mismo).
 *  Las validaciones del servidor no cambian: aquí solo cambia cómo se presentan.
 */
export function CargaWizard({ abierto, onCerrar, onListo }: { abierto: boolean; onCerrar: () => void; onListo?: (texto: string) => void }) {
  const qc = useQueryClient();
  const online = useOnline();
  const { puede } = useAuth();
  const input = useRef<HTMLInputElement>(null);
  const [paso, setPaso] = useState<Paso>('subir');
  const [archivo, setArchivo] = useState<File | null>(null);
  const [anio, setAnio] = useState(new Date().getFullYear());
  const [progreso, setProgreso] = useState(0);
  const [hoja, setHoja] = useState<string | undefined>(undefined);
  const [carga, setCarga] = useState<Carga | null>(null);
  const [previa, setPrevia] = useState<Previsualizacion | null>(null);
  const [modo, setModo] = useState<Modo>('MANTENER');
  const [error, setError] = useState<string | null>(null);
  const [fechaIni, setFechaIni] = useState('');
  const [fechaFin, setFechaFin] = useState('');

  const fechasListas = esFechaValida(fechaIni) && esFechaValida(fechaFin);
  const periodoManual = fechasListas ? { inicio: fechaIni, fin: fechaFin } : undefined;

  const reiniciar = () => {
    setPaso('subir'); setArchivo(null); setCarga(null); setPrevia(null); setModo('MANTENER'); setError(null); setProgreso(0);
    setHoja(undefined); setFechaIni(''); setFechaFin('');
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
      setError('El PDF es solo para imprimir y no se puede cargar. Sube el Excel original (.xlsx).');
      return;
    }
    if (!EXT_OK.test(f.name)) {
      setError('Ese archivo no es de Excel. Selecciona un archivo .xlsx.');
      return;
    }
    if (f.size > MAX_MB * 1024 * 1024) {
      setError(`El archivo pesa más de ${MAX_MB} MB.`);
      return;
    }
    setArchivo(f);
    setHoja(undefined);
  };

  const aplicar = useMutation({
    mutationFn: (p: { carga: Carga; modo: Modo; previa: Previsualizacion | null }) =>
      // confirmar = true: el usuario ya vio los avisos (o no había ninguno)
      cargasApi.aplicar(p.carga.id, p.modo, true).then((r) => ({ ...r, ...p })),
    onSuccess: ({ resumen, carga: c, previa: pv }) => {
      void qc.invalidateQueries({ queryKey: ['registros'] });
      void qc.invalidateQueries({ queryKey: ['periodos'] });
      void qc.invalidateQueries({ queryKey: ['estadisticas'] });
      const nombre = nombrePeriodo(c);
      const partes = [`${resumen.nuevos ?? 0} nuevos`, `${resumen.actualizados ?? 0} actualizados`];
      if (resumen.eliminados) partes.push(`${resumen.eliminados} fuera de la vista`);
      const texto = pv?.quincena_nueva
        ? `${nombre} cargado: ${c.filas_total} empleados.`
        : `${nombre} actualizado: ${partes.join(', ')}.`;
      setPaso('listo');
      onListo?.(texto);
      reiniciar();
      onCerrar();
    },
    onError: (e) => setError(mensajeDeError(e)),
  });

  /** Sube y valida; si no hay nada que decidir y la quincena es nueva, aplica directo. */
  const subir = useMutation({
    mutationFn: async (hojaElegida?: string) => {
      if (!navigator.onLine) throw new Error('offline');
      const c = await cargasApi.subir(archivo!, anio, setProgreso, hojaElegida ?? hoja, periodoManual);
      const pv = c.estatus === 'LISTA' ? await cargasApi.previsualizar(c.id) : null;
      return { c, pv };
    },
    onSuccess: ({ c, pv }) => {
      setCarga(c);
      setPrevia(pv);
      setModo(pv?.modo_predeterminado ?? 'MANTENER');
      const grupos = c.grupos ?? [];
      const hayQueRevisar = c.estatus !== 'LISTA'
        || grupos.some((g) => g.tipo === 'confirmar')
        || (c.desconocidas?.length ?? 0) > 0
        || !pv?.quincena_nueva;
      if (hayQueRevisar) {
        setPaso('revisar');
      } else {
        aplicar.mutate({ carga: c, modo: 'MANTENER', previa: pv });
      }
    },
    onError: (e) => setError(e instanceof Error && e.message === 'offline'
      ? 'Sin conexión a internet. Conserva el archivo y vuelve a intentarlo cuando regrese la red.'
      : mensajeDeError(e)),
  });

  /** Volver a validar el mismo archivo (otra hoja, fechas escritas a mano o columnas recién asignadas). */
  const revalidar = (nombreHoja?: string) => {
    if (carga?.estatus === 'LISTA') void cargasApi.descartar(carga.id).catch(() => undefined);
    if (nombreHoja) setHoja(nombreHoja);
    setCarga(null);
    setPrevia(null);
    setError(null);
    subir.mutate(nombreHoja);
  };

  const descargar = useMutation({
    mutationFn: () => cargasApi.descargarErrores(carga!.id, carga!.archivo),
    onError: (e) => setError(mensajeDeError(e)),
  });

  const grupos = carga?.grupos ?? [];
  const deError = grupos.filter((g) => g.tipo === 'error');
  const aConfirmar = grupos.filter((g) => g.tipo === 'confirmar' && g.codigo !== 'PERIODO_YA_CARGADO');
  const informativos = grupos.filter((g) => g.tipo === 'info' && g.codigo !== 'ENCABEZADO_DESCONOCIDO' && g.codigo !== 'ENCABEZADO_AMBIGUO');
  const desconocidas = carga?.desconocidas ?? [];
  const conErrores = carga !== null && carga.estatus !== 'LISTA';
  const errorDePeriodo = deError.some((g) => ERRORES_PERIODO.includes(g.codigo));
  const quincenaExistente = previa !== null && !previa.quincena_nueva;
  const ocupado = subir.isPending || aplicar.isPending;

  return (
    <Modal titulo="Cargar reporte de Excel" abierto={abierto} onCerrar={cerrar} ancho="md"
      pie={<>
        <button className="btn btn-sec" onClick={cerrar}>Cancelar</button>
        {paso === 'subir' && (
          <button className="btn" disabled={!archivo || ocupado || !online} onClick={() => subir.mutate(undefined)}>
            {subir.isPending ? `Revisando… ${progreso}%` : aplicar.isPending ? 'Cargando…' : 'Cargar'}
          </button>
        )}
        {paso === 'revisar' && conErrores && <>
          {(carga?.num_errores ?? 0) > 0 && (
            <button className="btn btn-sec" disabled={descargar.isPending} onClick={() => descargar.mutate()}>
              {descargar.isPending ? 'Preparando…' : 'Descargar errores en Excel'}
            </button>
          )}
          <button className="btn" onClick={reiniciar}>Subir archivo corregido</button>
        </>}
        {paso === 'revisar' && !conErrores && carga && (
          <button className="btn" disabled={ocupado} onClick={() => aplicar.mutate({ carga, modo, previa })}>
            {aplicar.isPending ? 'Cargando…' : aConfirmar.length > 0 || desconocidas.length > 0 ? 'Cargar de todos modos' : 'Cargar'}
          </button>
        )}
      </>}>
      <Pasos actual={paso} />
      {error && <Aviso tipo="error">{error}</Aviso>}
      {!online && <Aviso tipo="advertencia">Sin conexión a internet.</Aviso>}

      {paso === 'subir' && (
        <div className="zona-archivo"
          onDragOver={(e) => e.preventDefault()}
          onDrop={(e) => { e.preventDefault(); elegir(e.dataTransfer.files[0]); }}
          onClick={() => input.current?.click()} role="button" tabIndex={0}
          onKeyDown={(e) => e.key === 'Enter' && input.current?.click()}>
          <input ref={input} type="file" hidden
            accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel"
            onChange={(e) => elegir(e.target.files?.[0])} />
          <p className="zona-icono" aria-hidden="true">⬆</p>
          <p><strong>{archivo ? archivo.name : 'Arrastra aquí el Excel de la quincena o toca para elegirlo'}</strong></p>
          <p className="tenue">Archivo .xlsx de hasta {MAX_MB} MB</p>
          <details className="opciones-carga" onClick={(e) => e.stopPropagation()} open={fechasListas || undefined}>
            <summary>Opciones</summary>
            <label title="Solo si el encabezado del periodo y el bloque Ejercicio no traen el año">Año de la quincena{' '}
              <input type="number" min={2020} max={2100} value={anio} onChange={(e) => setAnio(Number(e.target.value))} style={{ width: 90 }} />
            </label>
            <p className="tenue">Fechas de la quincena (solo si el encabezado del periodo está mal):</p>
            <FechasPeriodo ini={fechaIni} fin={fechaFin} onIni={setFechaIni} onFin={setFechaFin} />
          </details>
        </div>
      )}

      {paso === 'revisar' && carga && (
        <>
          <p className="resumen-carga"><strong>{carga.archivo}</strong> · {nombrePeriodo(carga)} · {carga.filas_total} empleados</p>

          {(carga.hojas?.length ?? 0) > 1 && (
            <label className="fila-opcion">El libro tiene {carga.hojas!.length} hojas. Leer la hoja{' '}
              <select value={carga.hoja ?? ''} onChange={(e) => revalidar(e.target.value)} style={{ width: 'auto' }}>
                {!carga.hoja && <option value="">Elige…</option>}
                {carga.hojas!.map((h) => <option key={h} value={h}>{h}</option>)}
              </select>
            </label>
          )}

          {conErrores && (
            <>
              <div className="estado-carga estado-error">
                <strong>No se pudo cargar el archivo</strong>
                <span>Corrige {deError.length === 1 ? 'este punto' : `estos ${deError.length} puntos`} en tu Excel y vuelve a subirlo. No se guardó nada.</span>
              </div>
              {deError.map((g) => <TarjetaGrupo key={g.codigo} g={g} />)}
            </>
          )}

          {errorDePeriodo && (
            <div className="bloque">
              <strong>¿Prefieres escribir las fechas de la quincena{carga.periodo?.numero ? ` ${carga.periodo.numero}` : ''}?</strong>
              <FechasPeriodo ini={fechaIni} fin={fechaFin} onIni={setFechaIni} onFin={setFechaFin} />
              <button className="btn btn-sm" disabled={!fechasListas || ocupado} onClick={() => revalidar()}>
                {subir.isPending ? 'Revisando…' : 'Usar estas fechas'}
              </button>
            </div>
          )}

          {desconocidas.length > 0 && (puede('columnas.gestionar')
            ? <ColumnasNoReconocidas desconocidas={desconocidas} onListo={() => revalidar()} />
            : <Aviso tipo="advertencia">Estas columnas no se reconocieron y no se cargarán: {desconocidas.map((d) => d.encabezado).join(', ')}.</Aviso>)}

          {!conErrores && (
            <>
              {aConfirmar.length > 0 && (
                <div className="estado-carga estado-revisar">
                  <strong>Revisa antes de cargar</strong>
                  {aConfirmar.map((g) => <TarjetaGrupo key={g.codigo} g={g} compacta />)}
                </div>
              )}

              {quincenaExistente && previa && (
                <div className="bloque">
                  <strong>Esta quincena ya tiene datos. ¿Cómo actualizarla?</strong>
                  <div className="opciones-modo">
                    {(['MANTENER', 'REEMPLAZAR'] as const).map((m) => {
                      const imp = previa.impacto[m];
                      return (
                        <label key={m} className={`opcion-modo ${modo === m ? 'activa' : ''}`}>
                          <input type="radio" name="modo" checked={modo === m} onChange={() => setModo(m)} />
                          <strong>{m === 'MANTENER' ? 'Mantener lo editado en la web' : 'Reemplazar con el Excel'}</strong>
                          <small>{m === 'MANTENER'
                            ? 'Agrega nuevos y actualiza solo lo que no se corrigió a mano.'
                            : 'El Excel manda: quien no venga en el archivo sale de la vista.'}</small>
                          <small className="impacto-linea">
                            {imp.nuevos} nuevos · {imp.actualizados} actualizados
                            {m === 'REEMPLAZAR' && imp.eliminados > 0 && <b className="texto-peligro"> · {imp.eliminados} salen de la vista</b>}
                          </small>
                        </label>
                      );
                    })}
                  </div>
                </div>
              )}

              {aConfirmar.length === 0 && !quincenaExistente && desconocidas.length === 0 && (
                <div className="estado-carga estado-ok"><strong>Todo listo para cargar</strong></div>
              )}
            </>
          )}

          {informativos.length > 0 && (
            <details className="detalles-tecnicos">
              <summary>Detalles ({informativos.length})</summary>
              <ul>{informativos.map((g) => <li key={g.codigo}>{g.titulo}{g.cantidad > 1 ? ` (${g.cantidad})` : ''}{g.solucion && g.solucion !== 'Solo informativo.' ? ` — ${g.solucion}` : ''}</li>)}</ul>
            </details>
          )}
        </>
      )}
    </Modal>
  );
}

function nombrePeriodo(c: Carga): string {
  const p = c.periodo;
  if (!p) return 'periodo no detectado';
  return p.numero ? `Periodo ${p.numero}${p.ejercicio ? ` de ${p.ejercicio}` : ''}` : p.etiqueta;
}

/** Barra de progreso de 3 pasos. */
function Pasos({ actual }: { actual: Paso }) {
  const i = PASOS.findIndex((p) => p.id === actual);
  return (
    <ol className="pasos" aria-label="Progreso de la carga">
      {PASOS.map((p, j) => (
        <li key={p.id} className={j < i ? 'hecho' : j === i ? 'actual' : ''} aria-current={j === i ? 'step' : undefined}>
          <span className="pasos-num">{j < i ? '✓' : j + 1}</span>{p.texto}
        </li>
      ))}
    </ol>
  );
}

/** Un problema agrupado: título, cuántas veces, dónde y cómo arreglarlo. */
function TarjetaGrupo({ g, compacta = false }: { g: GrupoMensaje; compacta?: boolean }) {
  const resto = g.cantidad - g.ubicaciones.length;
  return (
    <div className={`tarjeta-grupo grupo-${g.tipo} ${compacta ? 'compacta' : ''}`}>
      <div className="tarjeta-grupo-titulo">
        {g.titulo}{g.cantidad > 1 && <span className="contador">{g.cantidad}</span>}
      </div>
      {g.ubicaciones.length > 0 && (
        <div className="ubicaciones">
          {g.ubicaciones.map((u) => <span key={u} className="chip mono">{u}</span>)}
          {resto > 0 && <span className="tenue"> y {resto} más</span>}
        </div>
      )}
      {/* Un solo mensaje sin celda (p. ej. continuidad de nómina): su texto ya dice lo importante */}
      {g.ubicaciones.length === 0 && g.cantidad === 1 && g.detalle[0] && <div className="detalle-unico">{g.detalle[0]}</div>}
      {g.solucion && <div className="solucion">{g.solucion}</div>}
      {g.detalle.length > 0 && !(g.ubicaciones.length === 0 && g.cantidad === 1) && (
        <details>
          <summary>Ver detalle</summary>
          <ul>{g.detalle.map((d, i) => <li key={i}>{d}</li>)}</ul>
        </details>
      )}
    </div>
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
        {guardar.isPending ? 'Guardando…' : 'Guardar y revisar de nuevo'}
      </button>
    </div>
  );
}
