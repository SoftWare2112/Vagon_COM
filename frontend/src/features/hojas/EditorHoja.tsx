import { useCallback, useEffect, useMemo, useRef, useState, type KeyboardEvent, type MouseEvent, type ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { hojasApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { useAuth } from '../../auth/AuthContext';
import { Aviso } from '../../components/Aviso';
import type { HojaDatos, ImagenHoja } from '../../types';
import { interpretar } from './motor/entrada';
import { alineacion, mostrar, textoParaEditar } from './motor/formato';
import { recalcular, revisarSintaxis } from './motor/libro';
import { aInterno, aVisible, letra, nombreCelda } from './motor/referencias';
import { resultadoParaGuardar, valorDeCelda, type Celda, type Estilo, type Valor } from './motor/tipos';
import { BarraFormato } from './BarraFormato';

const ALTO_FILA = 26;
const ALTO_ENCABEZADO = 26;
const ANCHO_NUMEROS = 48;
const clave = (f: number, c: number) => `${f}:${c}`;

type Pos = { r: number; c: number };
type Edicion = { texto: string; modo: 'escribir' | 'editar' };
type Cambio = { f: number; c: number; t: string; v?: unknown; fx?: string; rt?: string; ver: number };
type Formato = { celdas: [number, number][]; estilo: Estilo };
/** v9: celdas combinadas por posición (r1,c1 esquina; r2,c2 fin). */
type Comb = { id: number; r1: number; c1: number; r2: number; c2: number };
const pk = (r: number, c: number) => `${r}:${c}`;

/**
 * v8 — Editor de hojas de cálculo.
 *  - Se escribe directo en la celda (como Excel): el tipo se decide al escribir (motor/entrada).
 *  - Las fórmulas se calculan aquí, en el navegador; al servidor viaja la fórmula (con ids) y su resultado.
 *  - Los cambios se juntan y se guardan cada momento en un lote; si otra persona cambió la misma celda,
 *    el servidor responde "conflicto" y se muestra su valor (nadie pisa a nadie).
 *  - Solo se dibujan las filas visibles, así una hoja grande no vuelve lento el navegador.
 * v9: es una pestaña de un libro (EditorLibro pone la cabecera y las pestañas); celdas combinadas e imágenes.
 */
export function EditorHoja({ hojaId, cabecera, pie }: { hojaId: number; cabecera?: ReactNode; pie?: ReactNode }) {
  const id = hojaId;
  const { puede } = useAuth();
  const editable = puede('hojas.editar');
  // gcTime 0: al volver a una pestaña se pide de nuevo (lo guardado mientras tanto no se pierde)
  const inicial = useQuery({ queryKey: ['hoja', id], queryFn: () => hojasApi.obtener(id), staleTime: Infinity, gcTime: 0 });

  const [datos, setDatos] = useState<HojaDatos | null>(null);
  const [celdas, setCeldas] = useState<Map<string, Celda>>(new Map());
  const [valores, setValores] = useState<Map<string, Valor>>(new Map());
  const [estiloLocal, setEstiloLocal] = useState<Map<string, Estilo>>(new Map());
  const [ancla, setAncla] = useState<Pos>({ r: 0, c: 0 });
  const [extremo, setExtremo] = useState<Pos>({ r: 0, c: 0 });
  const [edicion, setEdicion] = useState<Edicion | null>(null);
  const [errorFormula, setErrorFormula] = useState<string | null>(null);
  const [estado, setEstado] = useState<'guardado' | 'pendiente' | 'guardando' | 'error'>('guardado');
  const [aviso, setAviso] = useState<string | null>(null);
  const [scroll, setScroll] = useState({ top: 0, left: 0, alto: 600 });
  const [imagenSel, setImagenSel] = useState<number | null>(null);

  const cuerpo = useRef<HTMLDivElement>(null);
  const lienzo = useRef<HTMLDivElement>(null);
  const editorInput = useRef<HTMLInputElement>(null);
  const pendientes = useRef(new Map<string, Cambio>());
  const formatosPend = useRef<Formato[]>([]);
  const enVuelo = useRef(false);
  const temporizador = useRef<number | undefined>(undefined);
  const arrastrando = useRef(false);
  const celdasRef = useRef(celdas);
  celdasRef.current = celdas;

  const filaIds = useMemo(() => datos?.filas.map((f) => f.id) ?? [], [datos]);
  const colIds = useMemo(() => datos?.columnas.map((c) => c.id) ?? [], [datos]);
  const posFila = useMemo(() => new Map(filaIds.map((fid, i) => [fid, i])), [filaIds]);
  const posCol = useMemo(() => new Map(colIds.map((cid, i) => [cid, i])), [colIds]);
  const izquierdas = useMemo(() => {
    const xs = [0];
    datos?.columnas.forEach((c) => xs.push(xs[xs.length - 1] + c.ancho));
    return xs;
  }, [datos]);

  // v9: celdas combinadas. `anclas` = esquina que muestra el valor; `cubiertas` = el resto (no se dibujan).
  const combinadas = useMemo<Comb[]>(() => (datos?.combinadas ?? []).flatMap((m) => {
    const r1 = posFila.get(m.f), c1 = posCol.get(m.c), r2 = posFila.get(m.f2), c2 = posCol.get(m.c2);
    return r1 === undefined || c1 === undefined || r2 === undefined || c2 === undefined ? [] : [{ id: m.id, r1, c1, r2, c2 }];
  }), [datos, posFila, posCol]);
  const { anclas, cubiertas } = useMemo(() => {
    const a = new Map<string, Comb>(), cub = new Map<string, Comb>();
    for (const m of combinadas) {
      for (let r = m.r1; r <= m.r2; r++) for (let c = m.c1; c <= m.c2; c++) (r === m.r1 && c === m.c1 ? a : cub).set(pk(r, c), m);
    }
    return { anclas: a, cubiertas: cub };
  }, [combinadas]);
  const combDe = (p: Pos) => anclas.get(pk(p.r, p.c)) ?? cubiertas.get(pk(p.r, p.c));
  /** Una celda cubierta por una combinación se trata como su esquina. */
  const aEsquina = (p: Pos): Pos => { const m = cubiertas.get(pk(p.r, p.c)); return m ? { r: m.r1, c: m.c1 } : p; };

  // ───────────── recálculo y cola de guardado ─────────────

  /** Recalcula; los resultados que cambiaron se guardan junto con el cambio que los provocó. */
  const recalcularYEncolar = useCallback((mapa: Map<string, Celda>, filas: number[], cols: number[]) => {
    const { valores: vals, cambiadas } = recalcular(mapa, { filas, columnas: cols });
    for (const k of cambiadas) {
      const c = mapa.get(k)!;
      const r = resultadoParaGuardar(vals.get(k)!);
      const nueva: Celda = { ...c, rt: r.rt, v: r.v as Celda['v'] };
      mapa.set(k, nueva);
      if (editable) encolar(nueva, c.ver ?? 0);
    }
    return vals;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [editable]);

  const encolar = (c: Celda, ver: number) => {
    const previo = pendientes.current.get(clave(c.f, c.c));
    pendientes.current.set(clave(c.f, c.c), { f: c.f, c: c.c, t: c.t, v: c.v, fx: c.fx, rt: c.rt, ver: previo?.ver ?? ver });
    programar();
  };

  const programar = () => {
    setEstado('pendiente');
    window.clearTimeout(temporizador.current);
    temporizador.current = window.setTimeout(() => void enviar(), 700);
  };

  const enviar = async (): Promise<void> => {
    if (enVuelo.current || (pendientes.current.size === 0 && formatosPend.current.length === 0)) return;
    const lote = [...pendientes.current.values()];
    const formatos = formatosPend.current;
    pendientes.current = new Map();
    formatosPend.current = [];
    enVuelo.current = true;
    setEstado('guardando');
    try {
      const r = await hojasApi.guardar(id, lote, formatos);
      const mapa = new Map(celdasRef.current);
      for (const s of r.celdas) {
        const k = clave(s.f, s.c);
        const pend = pendientes.current.get(k);
        if (pend) { pend.ver = s.ver ?? 0; continue; }   // hay un cambio más nuevo esperando: solo se actualiza la versión
        if (s.t === 'vacio' && !s.ver) mapa.delete(k); else mapa.set(k, s);
      }
      if (r.conflictos.length > 0) {
        for (const s of r.conflictos) {
          mapa.set(clave(s.f, s.c), s);
          pendientes.current.delete(clave(s.f, s.c));
        }
        const pf = new Map(filaIdsRef.current.map((x, i) => [x, i]));
        const pc = new Map(colIdsRef.current.map((x, i) => [x, i]));
        const nombres = r.conflictos.slice(0, 3).map((s) => nombreCelda(pf.get(s.f) ?? 0, pc.get(s.c) ?? 0)).join(', ');
        setAviso(`Otra persona cambió ${nombres} antes que tú; se muestra su valor.`);
        setValores(recalcularYEncolar(mapa, filaIdsRef.current, colIdsRef.current));
      }
      setCeldas(mapa);
      setDatos((d) => (d ? { ...d, estilos: r.estilos } : d));
      setEstiloLocal((m) => {
        const n = new Map(m);
        // Solo se suelta el formato local si no cambió otra vez mientras se guardaba
        formatos.forEach((fm) => fm.celdas.forEach(([f, c]) => {
          const k = clave(f, c);
          if (JSON.stringify(n.get(k)) === JSON.stringify(fm.estilo)) n.delete(k);
        }));
        return n;
      });
      enVuelo.current = false;
      if (pendientes.current.size > 0 || formatosPend.current.length > 0) await enviar(); else setEstado('guardado');
    } catch (e) {
      // Se devuelve el lote a la cola (sin pisar cambios más nuevos) y se reintenta
      for (const cbio of lote) if (!pendientes.current.has(clave(cbio.f, cbio.c))) pendientes.current.set(clave(cbio.f, cbio.c), cbio);
      formatosPend.current = [...formatos, ...formatosPend.current];
      enVuelo.current = false;
      setEstado('error');
      setAviso(`No se pudo guardar: ${mensajeDeError(e)}. Se reintentará.`);
      window.clearTimeout(temporizador.current);
      temporizador.current = window.setTimeout(() => void enviar(), 4000);
    }
  };

  const filaIdsRef = useRef(filaIds);
  const colIdsRef = useRef(colIds);
  filaIdsRef.current = filaIds;
  colIdsRef.current = colIds;

  /** Espera a que no quede nada por guardar (antes de insertar o borrar filas y columnas). */
  const vaciarCola = async () => {
    window.clearTimeout(temporizador.current);
    for (let i = 0; i < 20 && (enVuelo.current || pendientes.current.size > 0 || formatosPend.current.length > 0); i++) {
      if (!enVuelo.current) await enviar(); else await new Promise((r) => setTimeout(r, 150));
    }
  };

  const cargar = useCallback((d: HojaDatos) => {
    const mapa = new Map(d.celdas.map((c) => [clave(c.f, c.c), c]));
    const filas = d.filas.map((f) => f.id);
    const cols = d.columnas.map((c) => c.id);
    setDatos(d);
    setValores(recalcularYEncolar(mapa, filas, cols));   // p. ej. fórmulas de plantilla sin resultado todavía
    setCeldas(mapa);
    setAncla((p) => ({ r: Math.min(p.r, filas.length - 1), c: Math.min(p.c, cols.length - 1) }));
    setExtremo((p) => ({ r: Math.min(p.r, filas.length - 1), c: Math.min(p.c, cols.length - 1) }));
  }, [recalcularYEncolar]);

  useEffect(() => { if (inicial.data) cargar(inicial.data); }, [inicial.data, cargar]);

  // Guardar antes de salir
  useEffect(() => {
    const antes = (e: BeforeUnloadEvent) => {
      if (pendientes.current.size > 0 || enVuelo.current) { void enviar(); e.preventDefault(); }
    };
    window.addEventListener('beforeunload', antes);
    return () => { window.removeEventListener('beforeunload', antes); void enviar(); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // ───────────── selección ─────────────

  const sel = { r1: Math.min(ancla.r, extremo.r), r2: Math.max(ancla.r, extremo.r), c1: Math.min(ancla.c, extremo.c), c2: Math.max(ancla.c, extremo.c) };
  // La selección crece para incluir completas las combinaciones que toca (como Excel)
  for (let vuelta = 0, crecio = true; crecio && vuelta < 6; vuelta++) {
    crecio = false;
    for (const m of combinadas) {
      if (m.r1 <= sel.r2 && m.r2 >= sel.r1 && m.c1 <= sel.c2 && m.c2 >= sel.c1
        && (m.r1 < sel.r1 || m.r2 > sel.r2 || m.c1 < sel.c1 || m.c2 > sel.c2)) {
        sel.r1 = Math.min(sel.r1, m.r1); sel.r2 = Math.max(sel.r2, m.r2); sel.c1 = Math.min(sel.c1, m.c1); sel.c2 = Math.max(sel.c2, m.c2);
        crecio = true;
      }
    }
  }
  const combActiva = anclas.get(pk(ancla.r, ancla.c));
  const cajaActiva = combActiva ?? { r1: ancla.r, c1: ancla.c, r2: ancla.r, c2: ancla.c };
  const idActiva = { f: filaIds[ancla.r], c: colIds[ancla.c] };
  const celdaActiva = celdas.get(clave(idActiva.f, idActiva.c));

  const estiloDe = (k: string, c?: Celda): Estilo => estiloLocal.get(k) ?? (c?.e ? datos?.estilos[c.e] : undefined) ?? {};
  const valorDe = (k: string, c?: Celda): Valor => (c?.t === 'formula' ? valores.get(k) ?? valorDeCelda(c) : valorDeCelda(c));

  const textoEdicionDe = (c?: Celda): string => {
    if (!c) return '';
    if (c.t === 'formula') return aVisible(c.fx ?? '', posFila, posCol);
    return textoParaEditar(valorDeCelda(c));
  };

  const mover = (dr: number, dc: number, extender = false) => {
    const max = { r: filaIds.length - 1, c: colIds.length - 1 };
    let base = extender ? extremo : ancla;
    const m = combDe(base);
    if (m) base = { r: dr > 0 ? m.r2 : m.r1, c: dc > 0 ? m.c2 : m.c1 };   // salir de una combinación por su borde
    let nueva = { r: Math.max(0, Math.min(max.r, base.r + dr)), c: Math.max(0, Math.min(max.c, base.c + dc)) };
    if (!extender) nueva = aEsquina(nueva);
    if (extender) setExtremo(nueva); else { setAncla(nueva); setExtremo(nueva); }
    asegurarVisible(nueva);
  };

  const asegurarVisible = (p: Pos) => {
    const el = cuerpo.current;
    if (!el) return;
    const y = p.r * ALTO_FILA;
    if (y < el.scrollTop) el.scrollTop = y;
    else if (y + ALTO_FILA > el.scrollTop + el.clientHeight) el.scrollTop = y + ALTO_FILA - el.clientHeight;
    const x = izquierdas[p.c];
    const w = izquierdas[p.c + 1] - x;
    if (x < el.scrollLeft) el.scrollLeft = x;
    else if (x + w > el.scrollLeft + el.clientWidth) el.scrollLeft = x + w - el.clientWidth;
  };

  // ───────────── edición ─────────────

  const empezar = (texto: string, modo: Edicion['modo']) => {
    if (!editable) return;
    setErrorFormula(null);
    setEdicion({ texto, modo });
    setTimeout(() => editorInput.current?.focus(), 0);
  };

  /** Guarda lo escrito en la celda activa. Devuelve false si la fórmula tiene un error de escritura. */
  const confirmar = (): boolean => {
    if (!edicion || !datos) return true;
    const entrada = interpretar(edicion.texto);
    const k = clave(idActiva.f, idActiva.c);
    const actual = celdas.get(k);
    let nueva: Celda;
    if (entrada.t === 'formula') {
      const fx = aInterno(entrada.fx!, filaIds, colIds);
      const problema = revisarSintaxis(fx);
      if (problema) { setErrorFormula(`Hay un problema con la fórmula: ${problema}.`); return false; }
      nueva = { f: idActiva.f, c: idActiva.c, t: 'formula', fx, e: actual?.e, ver: actual?.ver };
    } else if (entrada.t === 'vacio') {
      if (!actual || actual.t === 'vacio') { setEdicion(null); return true; }
      nueva = { f: idActiva.f, c: idActiva.c, t: 'vacio', e: actual.e, ver: actual.ver };
    } else {
      nueva = { f: idActiva.f, c: idActiva.c, t: entrada.t, v: entrada.v, e: actual?.e, ver: actual?.ver };
    }
    if (textoEdicionDe(actual) === edicion.texto && actual) { setEdicion(null); return true; }   // sin cambios

    const mapa = new Map(celdas);
    mapa.set(k, nueva);
    if (nueva.t !== 'formula') encolar(nueva, actual?.ver ?? 0);
    const vals = recalcularYEncolar(mapa, filaIds, colIds);
    if (nueva.t === 'formula') encolar(mapa.get(k)!, actual?.ver ?? 0);   // con su resultado ya calculado
    setCeldas(mapa);
    setValores(vals);
    if (entrada.fmt && !actual?.e && !estiloLocal.has(k)) aplicarFormato({ fmt: entrada.fmt }, [[idActiva.f, idActiva.c]]);
    setEdicion(null);
    setErrorFormula(null);
    return true;
  };

  const cancelar = () => { setEdicion(null); setErrorFormula(null); cuerpo.current?.focus(); };

  const borrarSeleccion = () => {
    if (!editable) return;
    const mapa = new Map(celdas);
    for (let r = sel.r1; r <= sel.r2; r++) {
      for (let c = sel.c1; c <= sel.c2; c++) {
        const k = clave(filaIds[r], colIds[c]);
        const actual = mapa.get(k);
        if (!actual || actual.t === 'vacio') continue;
        const nueva: Celda = { f: actual.f, c: actual.c, t: 'vacio', e: actual.e, ver: actual.ver };
        mapa.set(k, nueva);
        encolar(nueva, actual.ver ?? 0);
      }
    }
    setValores(recalcularYEncolar(mapa, filaIds, colIds));
    setCeldas(mapa);
  };

  // ───────────── formato ─────────────

  const celdasSeleccion = (): [number, number][] => {
    const out: [number, number][] = [];
    for (let r = sel.r1; r <= sel.r2; r++) for (let c = sel.c1; c <= sel.c2; c++) out.push([filaIds[r], colIds[c]]);
    return out;
  };

  /** Aplica un cambio de formato a cada celda, conservando el resto de su formato. */
  const aplicarFormato = (cambio: Partial<Estilo> | null, lista = celdasSeleccion()) => {
    if (!editable) return;
    const grupos = new Map<string, Formato>();
    const local = new Map(estiloLocal);
    for (const [f, c] of lista) {
      const k = clave(f, c);
      const nuevo: Estilo = cambio === null ? {} : limpiarEstilo({ ...estiloDe(k, celdas.get(k)), ...cambio });
      local.set(k, nuevo);
      const g = JSON.stringify(nuevo);
      if (!grupos.has(g)) grupos.set(g, { celdas: [], estilo: nuevo });
      grupos.get(g)!.celdas.push([f, c]);
    }
    setEstiloLocal(local);
    formatosPend.current.push(...grupos.values());
    programar();
  };

  const estiloActivo = estiloDe(clave(idActiva.f, idActiva.c), celdaActiva);

  // ───────────── filas y columnas ─────────────

  const estructura = async (accion: () => Promise<HojaDatos>) => {
    if (!editable) return;
    await vaciarCola();
    try { cargar(await accion()); } catch (e) { setAviso(mensajeDeError(e)); }
  };

  const acciones = {
    combinar: () => estructura(() => hojasApi.combinar(id, { f: filaIds[sel.r1], c: colIds[sel.c1], f2: filaIds[sel.r2], c2: colIds[sel.c2] })),
    separar: () => { const m = combDe(ancla); if (m) void estructura(() => hojasApi.separar(id, m.id)); },
    filaArriba: () => estructura(() => hojasApi.insertarFilas(id, ancla.r === 0 ? 0 : filaIds[ancla.r - 1])),
    filaAbajo: () => estructura(() => hojasApi.insertarFilas(id, filaIds[ancla.r])),
    borrarFila: () => estructura(() => hojasApi.eliminarFila(id, filaIds[ancla.r])),
    columnaIzq: () => estructura(() => hojasApi.insertarColumnas(id, ancla.c === 0 ? 0 : colIds[ancla.c - 1])),
    columnaDer: () => estructura(() => hojasApi.insertarColumnas(id, colIds[ancla.c])),
    borrarColumna: () => estructura(() => hojasApi.eliminarColumna(id, colIds[ancla.c])),
    masFilas: () => estructura(() => hojasApi.insertarFilas(id, null, 50)),
  };

  // Ancho de columna arrastrando el borde del encabezado
  const redimensionar = (e: MouseEvent, ci: number) => {
    if (!editable || !datos) return;
    e.preventDefault();
    e.stopPropagation();
    const x0 = e.clientX;
    const w0 = datos.columnas[ci].ancho;
    let w = w0;
    const mover = (ev: globalThis.MouseEvent) => {
      w = Math.max(30, Math.min(800, w0 + ev.clientX - x0));
      setDatos((d) => d && { ...d, columnas: d.columnas.map((c, i) => (i === ci ? { ...c, ancho: w } : c)) });
    };
    const soltar = () => {
      window.removeEventListener('mousemove', mover);
      window.removeEventListener('mouseup', soltar);
      if (w !== w0) void hojasApi.ancho(id, datos.columnas[ci].id, w);
    };
    window.addEventListener('mousemove', mover);
    window.addEventListener('mouseup', soltar);
  };

  // ───────────── mouse y teclado ─────────────

  const posDeEvento = (e: MouseEvent): Pos | null => {
    const rect = lienzo.current?.getBoundingClientRect();
    if (!rect) return null;
    const x = e.clientX - rect.left;
    const y = e.clientY - rect.top;
    const r = Math.floor(y / ALTO_FILA);
    let c = izquierdas.findIndex((xi, i) => i < izquierdas.length - 1 && x >= xi && x < izquierdas[i + 1]);
    if (c < 0) c = colIds.length - 1;
    return r >= 0 && r < filaIds.length ? { r, c } : null;
  };

  const alPresionar = (e: MouseEvent) => {
    const crudo = posDeEvento(e);
    if (!crudo) return;
    const p = aEsquina(crudo);
    setImagenSel(null);
    // Escribiendo una fórmula: un clic agrega la referencia de esa celda
    if (edicion && edicion.texto.startsWith('=') && (p.r !== ancla.r || p.c !== ancla.c)) {
      e.preventDefault();
      const ultimo = edicion.texto.slice(-1);
      const sep = /[=(;,+\-*/^&<>:]/.test(ultimo) ? '' : '+';
      setEdicion({ ...edicion, texto: edicion.texto + sep + nombreCelda(p.r, p.c) });
      setTimeout(() => editorInput.current?.focus(), 0);
      return;
    }
    if (edicion && !confirmar()) return;
    if (e.shiftKey) setExtremo(p); else { setAncla(p); setExtremo(p); }
    arrastrando.current = true;
  };

  useEffect(() => {
    const soltar = () => { arrastrando.current = false; };
    window.addEventListener('mouseup', soltar);
    return () => window.removeEventListener('mouseup', soltar);
  }, []);

  const alMover = (e: MouseEvent) => {
    if (!arrastrando.current || edicion) return;
    const p = posDeEvento(e);   // el extremo puede caer dentro de una combinación: la selección crece sola
    if (p && (p.r !== extremo.r || p.c !== extremo.c)) setExtremo(p);
  };

  const teclaCuadricula = (e: KeyboardEvent) => {
    if (edicion) return;
    const ctrl = e.ctrlKey || e.metaKey;
    const flechas: Record<string, [number, number]> = { ArrowUp: [-1, 0], ArrowDown: [1, 0], ArrowLeft: [0, -1], ArrowRight: [0, 1] };
    if (flechas[e.key]) { e.preventDefault(); mover(...flechas[e.key], e.shiftKey); return; }
    if (e.key === 'Tab') { e.preventDefault(); mover(0, e.shiftKey ? -1 : 1); return; }
    if (e.key === 'Enter') { e.preventDefault(); if (e.shiftKey) mover(-1, 0); else empezar(textoEdicionDe(celdaActiva), 'editar'); return; }
    if (e.key === 'F2') { e.preventDefault(); empezar(textoEdicionDe(celdaActiva), 'editar'); return; }
    if (e.key === 'Delete' || e.key === 'Backspace') {
      e.preventDefault();
      if (imagenSel !== null) void quitarImagen(imagenSel); else borrarSeleccion();
      return;
    }
    if (ctrl && ['b', 'i', 'u'].includes(e.key.toLowerCase())) {
      e.preventDefault();
      const p = ({ b: 'n', i: 'i', u: 's' } as const)[e.key.toLowerCase() as 'b' | 'i' | 'u'];
      aplicarFormato({ [p]: !estiloActivo[p] });
      return;
    }
    if (!ctrl && !e.altKey && e.key.length === 1) { e.preventDefault(); empezar(e.key, 'escribir'); }
  };

  const teclaEditor = (e: KeyboardEvent<HTMLInputElement>) => {
    const salir = (dr: number, dc: number) => { e.preventDefault(); if (confirmar()) { mover(dr, dc); cuerpo.current?.focus(); } };
    if (e.key === 'Enter') return salir(e.shiftKey ? -1 : 1, 0);
    if (e.key === 'Tab') return salir(0, e.shiftKey ? -1 : 1);
    if (e.key === 'Escape') { e.preventDefault(); cancelar(); return; }
    if (edicion?.modo === 'escribir' && !edicion.texto.startsWith('=')) {
      const flechas: Record<string, [number, number]> = { ArrowUp: [-1, 0], ArrowDown: [1, 0], ArrowLeft: [0, -1], ArrowRight: [0, 1] };
      if (flechas[e.key]) salir(...flechas[e.key]);
    }
  };

  const quitarImagen = async (imagenId: number) => {
    if (!editable || !confirm('¿Quitar esta imagen de la hoja?')) return;
    try {
      await hojasApi.eliminarImagen(id, imagenId);
      setDatos((d) => d && { ...d, imagenes: d.imagenes.filter((i) => i.id !== imagenId) });
      setImagenSel(null);
    } catch (err) { setAviso(mensajeDeError(err)); }
  };

  /** Estilo en línea de una celda (también para la esquina de una combinación). */
  const estiloCelda = (est: Estilo) => ({
    fontWeight: est.n ? 700 : undefined, fontStyle: est.i ? 'italic' : undefined,
    textDecoration: est.s ? 'underline' : undefined, color: est.c, background: est.f,
    whiteSpace: est.w ? 'normal' : undefined,
  });

  // ───────────── dibujo ─────────────

  if (inicial.isLoading || !datos) return inicial.isError ? <Aviso tipo="error">{mensajeDeError(inicial.error)}</Aviso> : <p>Abriendo la hoja…</p>;

  const anchoTotal = izquierdas[izquierdas.length - 1];
  const altoTotal = filaIds.length * ALTO_FILA;
  const primera = Math.max(0, Math.floor(scroll.top / ALTO_FILA) - 5);
  const ultima = Math.min(filaIds.length - 1, Math.ceil((scroll.top + scroll.alto) / ALTO_FILA) + 5);
  const visibles = Array.from({ length: Math.max(0, ultima - primera + 1) }, (_, i) => primera + i);
  const textoBarra = edicion ? edicion.texto : textoEdicionDe(celdaActiva);

  return (
    <div className="editor-hoja">
      <header className="editor-cabecera">
        {cabecera}
        <span className={`estado-guardado estado-${estado}`} role="status">
          {{ guardado: 'Guardado', pendiente: 'Cambios sin guardar…', guardando: 'Guardando…', error: 'Sin guardar (reintentando)' }[estado]}
        </span>
        {!editable && <span className="tenue">Solo lectura</span>}
      </header>

      {editable && <BarraFormato estilo={estiloActivo} onFormato={aplicarFormato} acciones={acciones}
        combinada={!!combDe(ancla)} varias={sel.r1 !== sel.r2 || sel.c1 !== sel.c2} />}

      <div className="barra-formula">
        <span className="nombre-celda mono">{nombreCelda(ancla.r, ancla.c)}{(sel.r1 !== sel.r2 || sel.c1 !== sel.c2) ? `:${nombreCelda(sel.r2, sel.c2)}` : ''}</span>
        <span className="fx" aria-hidden="true">fx</span>
        <input aria-label="Contenido de la celda" value={textoBarra} disabled={!editable}
          onFocus={() => !edicion && empezar(textoEdicionDe(celdaActiva), 'editar')}
          onChange={(e) => setEdicion({ texto: e.target.value, modo: 'editar' })}
          onKeyDown={teclaEditor} />
      </div>
      {errorFormula && <Aviso tipo="error">{errorFormula} Corrígela o presiona Esc para cancelar.</Aviso>}
      {aviso && <Aviso tipo="advertencia">{aviso} <button className="btn-link" onClick={() => setAviso(null)}>Cerrar</button></Aviso>}

      <div className="hoja-marco" style={{ gridTemplateColumns: `${ANCHO_NUMEROS}px 1fr`, gridTemplateRows: `${ALTO_ENCABEZADO}px 1fr` }}>
        <div className="hoja-esquina" />
        <div className="hoja-encabezados">
          <div style={{ width: anchoTotal, transform: `translateX(${-scroll.left}px)`, position: 'relative', height: ALTO_ENCABEZADO }}>
            {datos.columnas.map((col, ci) => (
              <div key={col.id} className={`hoja-col ${ci >= sel.c1 && ci <= sel.c2 ? 'sel' : ''}`} style={{ left: izquierdas[ci], width: col.ancho }}
                onMouseDown={() => { setAncla({ r: 0, c: ci }); setExtremo({ r: filaIds.length - 1, c: ci }); }}>
                {letra(ci)}
                <span className="hoja-col-borde" onMouseDown={(e) => redimensionar(e, ci)} />
              </div>
            ))}
          </div>
        </div>
        <div className="hoja-numeros">
          <div style={{ height: altoTotal, transform: `translateY(${-scroll.top}px)`, position: 'relative' }}>
            {visibles.map((r) => (
              <div key={filaIds[r]} className={`hoja-num ${r >= sel.r1 && r <= sel.r2 ? 'sel' : ''}`} style={{ top: r * ALTO_FILA, height: ALTO_FILA }}
                onMouseDown={() => { setAncla({ r, c: 0 }); setExtremo({ r, c: colIds.length - 1 }); }}>{r + 1}</div>
            ))}
          </div>
        </div>
        <div className="hoja-cuerpo" ref={cuerpo} tabIndex={0} onKeyDown={teclaCuadricula}
          onScroll={(e) => setScroll({ top: e.currentTarget.scrollTop, left: e.currentTarget.scrollLeft, alto: e.currentTarget.clientHeight })}>
          <div ref={lienzo} className="hoja-lienzo" style={{ width: anchoTotal, height: altoTotal }}
            onMouseDown={alPresionar} onMouseMove={alMover}
            onDoubleClick={() => empezar(textoEdicionDe(celdaActiva), 'editar')}>
            {izquierdas.slice(1).map((x, i) => <div key={`l${i}`} className="hoja-linea-col" style={{ left: x - 1 }} />)}
            {visibles.map((r) => datos.columnas.map((col, ci) => {
              if (cubiertas.has(pk(r, ci)) || anclas.has(pk(r, ci))) return null;   // las combinadas se dibujan aparte
              const k = clave(filaIds[r], col.id);
              const c = celdas.get(k);
              const est = estiloDe(k, c);
              if (!c && !estiloLocal.has(k)) return null;
              const v = valorDe(k, c);
              return (
                <div key={k} className={`hoja-celda al-${alineacion(v, est)} ${v.t === 'error' ? 'es-error' : ''}`}
                  style={{ top: r * ALTO_FILA, left: izquierdas[ci], width: col.ancho, height: ALTO_FILA, ...estiloCelda(est) }}>
                  {mostrar(v, est.fmt)}
                </div>
              );
            }))}
            {combinadas.filter((m) => m.r2 >= primera && m.r1 <= ultima).map((m) => {
              const k = clave(filaIds[m.r1], colIds[m.c1]);
              const c = celdas.get(k);
              const est = estiloDe(k, c);
              const v = valorDe(k, c);
              return (
                <div key={`m${m.id}`} className={`hoja-celda combinada al-${alineacion(v, est)} ${v.t === 'error' ? 'es-error' : ''}`}
                  style={{ top: m.r1 * ALTO_FILA, left: izquierdas[m.c1], width: izquierdas[m.c2 + 1] - izquierdas[m.c1],
                    height: (m.r2 - m.r1 + 1) * ALTO_FILA, ...estiloCelda(est), background: est.f ?? 'var(--surface)' }}>
                  {mostrar(v, est.fmt)}
                </div>
              );
            })}
            {datos.imagenes.map((img) => {
              const r = posFila.get(img.f), c = posCol.get(img.c);
              if (r === undefined || c === undefined) return null;
              return <ImagenEnHoja key={img.id} img={img} top={r * ALTO_FILA + img.y} left={izquierdas[c] + img.x}
                seleccionada={imagenSel === img.id} editable={editable}
                onElegir={() => { setImagenSel(img.id); cuerpo.current?.focus(); }} onQuitar={() => void quitarImagen(img.id)} />;
            })}
            <div className="hoja-seleccion" style={{
              top: sel.r1 * ALTO_FILA, left: izquierdas[sel.c1],
              width: izquierdas[sel.c2 + 1] - izquierdas[sel.c1], height: (sel.r2 - sel.r1 + 1) * ALTO_FILA,
            }} />
            <div className="hoja-activa" style={{ top: cajaActiva.r1 * ALTO_FILA, left: izquierdas[cajaActiva.c1],
              width: izquierdas[cajaActiva.c2 + 1] - izquierdas[cajaActiva.c1], height: (cajaActiva.r2 - cajaActiva.r1 + 1) * ALTO_FILA }} />
            {edicion && (
              <input ref={editorInput} className="hoja-editor" value={edicion.texto} aria-label="Editar celda"
                style={{ top: cajaActiva.r1 * ALTO_FILA, left: izquierdas[cajaActiva.c1], minWidth: izquierdas[cajaActiva.c2 + 1] - izquierdas[cajaActiva.c1],
                  height: (cajaActiva.r2 - cajaActiva.r1 + 1) * ALTO_FILA }}
                onChange={(e) => setEdicion({ ...edicion, texto: e.target.value })}
                onKeyDown={teclaEditor} onMouseDown={(e) => e.stopPropagation()} />
            )}
          </div>
        </div>
      </div>
      <div className="editor-pie">
        {pie}
        {editable && <button className="btn btn-sec btn-sm mas-filas" onClick={acciones.masFilas}>+ 50 filas</button>}
      </div>
    </div>
  );
}

/** v9: imagen de la hoja. Se descarga con la sesión del usuario y se muestra como blob. */
function ImagenEnHoja({ img, top, left, seleccionada, editable, onElegir, onQuitar }: {
  img: ImagenHoja; top: number; left: number; seleccionada: boolean; editable: boolean; onElegir: () => void; onQuitar: () => void;
}) {
  const { data: blob } = useQuery({ queryKey: ['hoja-imagen', img.url], queryFn: () => hojasApi.imagen(img.url), staleTime: Infinity });
  const [src, setSrc] = useState<string | null>(null);
  useEffect(() => {
    if (!blob) return;
    const u = URL.createObjectURL(blob);
    setSrc(u);
    return () => URL.revokeObjectURL(u);
  }, [blob]);

  return (
    <div className={`hoja-imagen ${seleccionada ? 'sel' : ''}`} style={{ top, left, width: img.w, height: img.h }}
      onMouseDown={(e) => { e.stopPropagation(); onElegir(); }} title={img.nombre ?? 'Imagen'}>
      {src ? <img src={src} alt={img.nombre ?? 'Imagen de la hoja'} draggable={false} /> : <span className="tenue">Cargando imagen…</span>}
      {seleccionada && editable && (
        <button type="button" className="hoja-imagen-quitar" aria-label="Quitar imagen" onMouseDown={(e) => e.stopPropagation()} onClick={onQuitar}>✕</button>
      )}
    </div>
  );
}

/** Quita del formato lo que no aporta (false, vacío, general) para que formatos iguales coincidan. */
function limpiarEstilo(e: Estilo): Estilo {
  const r: Estilo = {};
  (Object.keys(e) as (keyof Estilo)[]).forEach((k) => {
    const v = e[k];
    if (v === undefined || v === false || v === '' || (k === 'fmt' && v === 'general')) return;
    (r as Record<string, unknown>)[k] = v;
  });
  return r;
}
