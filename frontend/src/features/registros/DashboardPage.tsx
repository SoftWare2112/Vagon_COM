import { useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { catalogosApi, exportacionesApi, registrosApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { useAuth } from '../../auth/AuthContext';
import { Aviso } from '../../components/Aviso';
import { useEsMovil } from '../../hooks/useMediaQuery';
import { Menu, type OpcionMenu } from '../../components/Menu';
import { ImportarExcelModal } from '../hojas/ImportarExcelModal';
import { CargaWizard } from '../cargas/CargaWizard';
import { EditarRegistroModal } from './EditarRegistroModal';
import { FiltrosPanel } from './FiltrosPanel';
import { NuevoEmpleadoModal } from './NuevoEmpleadoModal';
import { ResumenModal } from './ResumenModal';
import { TablaRegistros, type AccionColumna } from './TablaRegistros';
import { NuevaColumnaModal, type PosicionColumna } from './NuevaColumnaModal';
import { ColumnasPanel } from '../ajustes/ColumnasPanel';
import { Modal } from '../../components/Modal';
import { columnasApi } from '../../api/endpoints';
import { claveCatalogo } from '../../utils/columnas';
import { TarjetasRegistros } from './TarjetasRegistros';
import type { Columna, Fila, Filtros } from '../../types';

/**
 * Contenedor: maneja estado y datos; delega la presentación a componentes pequeños.
 * v9: acciones agrupadas (Importar ▾, Exportar ▾, Imprimir, ⋯) y filtros con buscador y etiquetas.
 * `incrustado`: dentro de la pestaña "Incidencias" de Inicio (título más chico).
 */
export function DashboardPage({ incrustado = false }: { incrustado?: boolean }) {
  const { puede } = useAuth();
  const esMovil = useEsMovil();
  const qc = useQueryClient();
  const [filtros, setFiltros] = useState<Filtros>({ orden: 'numero_nomina', direccion: 'asc', page: 1 });
  const [verId, setVerId] = useState<number | null>(null);
  const [editar, setEditar] = useState<Fila | null>(null);
  const [wizard, setWizard] = useState(false);
  const [importarHoja, setImportarHoja] = useState(false);
  const [nuevo, setNuevo] = useState(false);
  const [aviso, setAviso] = useState<{ tipo: 'exito' | 'error'; texto: string } | null>(null);
  const [panelColumnas, setPanelColumnas] = useState(false);
  const [insertar, setInsertar] = useState<PosicionColumna | null>(null);
  const puedeColumnas = puede('columnas.gestionar');

  const { data: periodos = [] } = useQuery({ queryKey: ['periodos'], queryFn: catalogosApi.periodos });
  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['registros', filtros],
    queryFn: () => registrosApi.tabla(filtros),
    placeholderData: keepPreviousData,
  });

  const exportar = useMutation({
    mutationFn: async (formato: 'PDF' | 'EXCEL') => {
      const inf = await exportacionesApi.generar(formato, { ...filtros, periodo_id: data?.periodo?.id });
      await exportacionesApi.descargar(inf.id, inf.nombre);
    },
    onError: (e) => setAviso({ tipo: 'error', texto: mensajeDeError(e) }),
  });

  /** v6: abre la vista de impresión (toda la tabla, mismos filtros) en otra pestaña. */
  const imprimir = () => {
    const p = new URLSearchParams();
    Object.entries({ ...filtros, page: undefined, periodo_id: data?.periodo?.id }).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== '') p.set(k, String(v));
    });
    window.open(`/imprimir?${p.toString()}`, '_blank', 'noopener');
  };

  const eliminar = useMutation({
    mutationFn: (f: Fila) => registrosApi.eliminar(f.id),
    onSuccess: (r) => {
      setAviso({ tipo: 'exito', texto: r.data.message });
      void qc.invalidateQueries({ queryKey: ['registros'] });
    },
    onError: (e) => setAviso({ tipo: 'error', texto: mensajeDeError(e) }),
  });

  // Menú ⋮ de cada encabezado: insertar a izquierda/derecha u ocultar (misma API que Ajustes > Columnas)
  const menuColumna = async (accion: AccionColumna, col: Columna) => {
    const clave = claveCatalogo(col.clave);
    if (accion === 'ocultar') {
      try {
        const catalogo = await qc.fetchQuery({ queryKey: ['columnas'], queryFn: columnasApi.listar });
        const def = catalogo.find((c) => c.clave === clave);
        if (!def || !confirm(`¿Ocultar "${def.etiqueta}"? Sus datos se conservan; puedes mostrarla de nuevo en Columnas.`)) return;
        await columnasApi.actualizar(def.id, { visible: false });
        void qc.invalidateQueries({ queryKey: ['registros'] });
        void qc.invalidateQueries({ queryKey: ['columnas'] });
      } catch (e) {
        setAviso({ tipo: 'error', texto: mensajeDeError(e) });
      }
      return;
    }
    // Los domingos son un grupo dinámico: "a la izquierda/derecha" se aplica al grupo completo
    const etiqueta = clave === 'domingos' ? 'los domingos de la quincena' : col.etiqueta;
    setInsertar({ lado: accion === 'izquierda' ? 'antes_de' : 'despues_de', clave, etiqueta });
  };

  const ordenar = (campo: string) => setFiltros((f) => ({
    ...f, orden: campo, direccion: f.orden === campo && f.direccion === 'asc' ? 'desc' : 'asc',
  }));

  const periodo = data?.periodo;
  const puedeEditar = puede('registros.editar');

  const opcionesImportar: OpcionMenu[] = [
    ...(puede('cargas.subir') ? [{ texto: 'Incidencias de nómina', detalle: 'El Excel quincenal de RH (se valida y se agrega a la quincena)', icono: '📋', onClick: () => setWizard(true) }] : []),
    ...(puede('hojas.crear') ? [{ texto: 'Excel como hoja', detalle: 'Cualquier archivo: se abre en Mis hojas tal como está', icono: '▦', onClick: () => setImportarHoja(true) }] : []),
  ];
  const opcionesExportar: OpcionMenu[] = [
    { texto: 'Excel (.xlsx)', detalle: 'Con el encabezado y formato de RH', icono: '⊞', onClick: () => exportar.mutate('EXCEL'), deshabilitada: exportar.isPending },
    { texto: 'PDF', detalle: 'Horizontal, listo para firmar', icono: '🗎', onClick: () => exportar.mutate('PDF'), deshabilitada: exportar.isPending },
  ];
  const opcionesMas: OpcionMenu[] = [
    ...(puede('registros.crear') ? [{ texto: 'Agregar empleado', icono: '+', onClick: () => setNuevo(true) }] : []),
    ...(puedeColumnas ? [{ texto: 'Columnas del reporte', detalle: 'Agregar, mover u ocultar', icono: '☰', onClick: () => setPanelColumnas(true) }] : []),
  ];

  return (
    <section>
      <div className="encabezado-pagina">
        <div className="titulo-con-selector">
          {incrustado ? <h2>Incidencias de nómina</h2> : <h1>Listado de personal</h1>}
          <p className="tenue">
            {periodos.length > 1 ? (
              <select className="selector-quincena" aria-label="Quincena" value={filtros.periodo_id ?? ''}
                onChange={(e) => setFiltros({ ...filtros, periodo_id: e.target.value ? Number(e.target.value) : undefined, page: 1 })}>
                <option value="">Quincena más reciente{periodo && !filtros.periodo_id ? ` (${periodo.etiqueta})` : ''}</option>
                {periodos.map((p) => <option key={p.id} value={p.id}>{p.etiqueta}</option>)}
              </select>
            ) : periodo ? `Quincena: ${periodo.etiqueta}` : 'Aún no hay cargas'}
            {data ? ` · ${data.meta.total} empleados` : ''}
          </p>
        </div>
        <div className="barra-acciones" role="toolbar" aria-label="Acciones del listado">
          {opcionesMas.length > 0 && <Menu etiqueta="⋯" titulo="Más acciones" clase="btn btn-sec btn-cuadrado" opciones={opcionesMas} />}
          {periodo && <button className="btn btn-sec btn-cuadrado" onClick={imprimir} title="Imprimir toda la tabla con el encabezado de RH" aria-label="Imprimir">🖨</button>}
          {puede('exportar') && periodo && <Menu etiqueta={exportar.isPending ? 'Exportando…' : 'Exportar'} opciones={opcionesExportar} />}
          {opcionesImportar.length > 0 && <Menu etiqueta="⇪ Importar" clase="btn" opciones={opcionesImportar} />}
        </div>
      </div>

      {aviso && <Aviso tipo={aviso.tipo}>{aviso.texto} <button className="btn-link" onClick={() => setAviso(null)}>Cerrar</button></Aviso>}

      <FiltrosPanel valor={filtros} onCambiar={setFiltros} />

      <div className="leyenda">
        <span className="faltas faltas-una">▲ 1</span> 1 falta
        <span className="faltas faltas-varias">● 2</span> 2 o más
        <span className="marca-editado" /> editado en la web (Mantener lo respeta)
      </div>

      {isLoading && <p>Cargando…</p>}
      {isError && <Aviso tipo="error">{mensajeDeError(error)}</Aviso>}
      {data && data.data.length === 0 && <div className="vacio">No hay registros con estos filtros.</div>}

      {data && data.data.length > 0 && periodo && (esMovil
        ? <TarjetasRegistros filas={data.data} onVer={(f) => setVerId(f.id)} onEditar={puedeEditar ? setEditar : undefined} />
        : <TablaRegistros filas={data.data} columnas={data.columnas}
            filtros={filtros} onOrdenar={ordenar} onVer={(f) => setVerId(f.id)}
            onEditar={puedeEditar ? setEditar : undefined}
            onMenuColumna={puedeColumnas ? (a, c) => void menuColumna(a, c) : undefined}
            onEliminar={puede('registros.eliminar') ? (f) => {
              if (confirm(`¿Quitar a ${f.valores.nombre_completo} de esta quincena? Seguirá disponible en Histórico.`)) eliminar.mutate(f);
            } : undefined} />)}

      {data && data.meta.last_page > 1 && (
        <nav className="paginacion" aria-label="Paginación">
          <button className="btn btn-sec btn-sm" disabled={data.meta.current_page <= 1} onClick={() => setFiltros({ ...filtros, page: data.meta.current_page - 1 })}>Anterior</button>
          <span>Página {data.meta.current_page} de {data.meta.last_page}</span>
          <button className="btn btn-sec btn-sm" disabled={data.meta.current_page >= data.meta.last_page} onClick={() => setFiltros({ ...filtros, page: data.meta.current_page + 1 })}>Siguiente</button>
        </nav>
      )}

      <ResumenModal registroId={verId} onCerrar={() => setVerId(null)}
        onEditar={puedeEditar ? () => { setEditar(data?.data.find((f) => f.id === verId) ?? null); setVerId(null); } : undefined} />
      {editar && data && <EditarRegistroModal key={editar.id} fila={editar} columnas={data.columnas} onCerrar={() => setEditar(null)} />}
      {insertar && <NuevaColumnaModal posicion={insertar} onCerrar={() => setInsertar(null)} />}
      <Modal titulo="Columnas del reporte" abierto={panelColumnas} onCerrar={() => setPanelColumnas(false)} ancho="lg">
        <ColumnasPanel />
      </Modal>
      <CargaWizard abierto={wizard} onCerrar={() => setWizard(false)} onListo={(texto) => setAviso({ tipo: 'exito', texto })} />
      <ImportarExcelModal abierto={importarHoja} onCerrar={() => setImportarHoja(false)} />
      <NuevoEmpleadoModal abierto={nuevo} periodoId={periodo?.id} onCerrar={() => setNuevo(false)} />
    </section>
  );
}
