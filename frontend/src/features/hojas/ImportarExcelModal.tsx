import { useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { librosApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { Aviso } from '../../components/Aviso';
import { Modal } from '../../components/Modal';
import type { LibroDetalle } from '../../types';

const MAX_MB = 10;

/**
 * v9 — Importar CUALQUIER Excel como libro: todas las pestañas visibles con sus valores, fórmulas, formato,
 * celdas combinadas e imágenes. No importa si tiene tablas, textos sueltos o logotipos.
 * Si no hubo avisos se abre el libro directo; si los hubo, se muestran antes de abrirlo.
 */
export function ImportarExcelModal({ abierto, onCerrar }: { abierto: boolean; onCerrar: () => void }) {
  const nav = useNavigate();
  const qc = useQueryClient();
  const input = useRef<HTMLInputElement>(null);
  const [archivo, setArchivo] = useState<File | null>(null);
  const [nombre, setNombre] = useState('');
  const [progreso, setProgreso] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [resultado, setResultado] = useState<LibroDetalle | null>(null);
  const [encima, setEncima] = useState(false);

  const reiniciar = () => { setArchivo(null); setNombre(''); setProgreso(null); setError(null); setResultado(null); };
  const cerrar = () => { reiniciar(); onCerrar(); };
  const abrir = (r: LibroDetalle) => { cerrar(); nav(`/hojas/${r.libro.id}`); };

  const elegir = (f: File | undefined) => {
    setError(null);
    if (!f) return;
    if (!/\.(xlsx|xls|ods)$/i.test(f.name)) { setError('Elige un archivo de Excel (.xlsx o .xls) o una hoja de cálculo .ods.'); return; }
    if (f.size > MAX_MB * 1024 * 1024) { setError(`El archivo pesa más de ${MAX_MB} MB.`); return; }
    setArchivo(f);
    setNombre(f.name.replace(/\.[^.]+$/, ''));
  };

  const importar = async () => {
    if (!archivo) return;
    setError(null);
    setProgreso(0);
    try {
      const r = await librosApi.importar(archivo, nombre.trim() || undefined, setProgreso);
      void qc.invalidateQueries({ queryKey: ['libros'] });
      void qc.invalidateQueries({ queryKey: ['cargas'] });
      if ((r.importacion?.avisos.length ?? 0) === 0) abrir(r); else setResultado(r);
    } catch (e) {
      setError(mensajeDeError(e));
    } finally {
      setProgreso(null);
    }
  };

  const est = resultado?.importacion?.estadisticas;
  const trabajando = progreso !== null;

  return (
    <Modal titulo="Importar Excel como hoja" abierto={abierto} onCerrar={cerrar} ancho="md"
      pie={resultado
        ? <><button className="btn btn-sec" onClick={cerrar}>Cerrar</button><button className="btn" onClick={() => abrir(resultado)}>Abrir libro</button></>
        : <><button className="btn btn-sec" onClick={cerrar} disabled={trabajando}>Cancelar</button>
          <button className="btn" disabled={!archivo || trabajando} onClick={() => void importar()}>
            {trabajando ? (progreso! < 100 ? `Subiendo ${progreso}%…` : 'Leyendo el archivo…') : 'Importar'}
          </button></>}>
      {error && <Aviso tipo="error">{error}</Aviso>}

      {resultado && est ? (
        <div className="resultado-importacion">
          <p><strong>«{resultado.libro.nombre}»</strong> quedó en tus hojas.</p>
          <ul className="cifras">
            <li><strong>{est.pestanas}</strong> pestaña(s)</li>
            <li><strong>{est.celdas.toLocaleString('es-MX')}</strong> celdas</li>
            <li><strong>{est.formulas}</strong> fórmulas</li>
            {est.combinadas > 0 && <li><strong>{est.combinadas}</strong> combinadas</li>}
            {est.imagenes > 0 && <li><strong>{est.imagenes}</strong> imágenes</li>}
          </ul>
          <Aviso tipo="advertencia">
            <strong>Revisa antes de usarlo:</strong>
            <ul>{resultado.importacion!.avisos.map((a) => <li key={a}>{a}</li>)}</ul>
          </Aviso>
        </div>
      ) : (
        <>
          <p className="tenue">Se importan todas las pestañas visibles con sus datos, fórmulas, formato, celdas combinadas e imágenes. El archivo puede tener cualquier estructura.</p>
          <div className={`zona-archivo ${encima ? 'encima' : ''} ${archivo ? 'con-archivo' : ''}`}
            onDragOver={(e) => { e.preventDefault(); setEncima(true); }} onDragLeave={() => setEncima(false)}
            onDrop={(e) => { e.preventDefault(); setEncima(false); elegir(e.dataTransfer.files[0]); }}
            onClick={() => input.current?.click()} role="button" tabIndex={0}
            onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') input.current?.click(); }}>
            <span className="zona-icono" aria-hidden>⇪</span>
            {archivo
              ? <span><strong>{archivo.name}</strong><small>{(archivo.size / 1024).toFixed(0)} KB · haz clic para cambiarlo</small></span>
              : <span><strong>Arrastra aquí tu archivo</strong><small>o haz clic para elegirlo (.xlsx, .xls, .ods · máx. {MAX_MB} MB)</small></span>}
            <input ref={input} type="file" hidden accept=".xlsx,.xls,.ods" onChange={(e) => elegir(e.target.files?.[0])} />
          </div>
          {archivo && (
            <label>Nombre del libro
              <input value={nombre} maxLength={120} onChange={(e) => setNombre(e.target.value)} />
            </label>
          )}
          {trabajando && <progress max={100} value={progreso ?? 0} aria-label="Progreso de la importación" />}
          <p className="tenue nota">Solo tú verás el libro importado. Las gráficas y macros de Excel no se importan.</p>
        </>
      )}
    </Modal>
  );
}
