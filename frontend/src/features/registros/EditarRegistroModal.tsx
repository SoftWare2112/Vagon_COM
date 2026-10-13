import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import { registrosApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { Modal } from '../../components/Modal';
import { Aviso } from '../../components/Aviso';
import { CampoValor } from '../../components/CampoValor';
import type { Columna, Fila } from '../../types';

/**
 * Edición GENÉRICA de una fila: un campo por cada columna editable (incluye las que RH agregue).
 * Solo se envía lo que cambió. Bloqueo optimista: si otro usuario guardó antes, 409 y no se pisa.
 */
export function EditarRegistroModal({ fila, columnas, onCerrar }: { fila: Fila; columnas: Columna[]; onCerrar: () => void }) {
  const qc = useQueryClient();
  const editables = columnas.filter((c) => c.editable);
  const [valores, setValores] = useState<Fila['valores']>(() => ({ ...fila.valores }));
  const [error, setError] = useState<string | null>(null);

  const cambios = Object.fromEntries(editables.filter((c) => valores[c.clave] !== fila.valores[c.clave]).map((c) => [c.clave, valores[c.clave]]));

  const guardar = useMutation({
    mutationFn: () => registrosApi.actualizar(fila.id, fila.version, cambios),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['registros'] });
      void qc.invalidateQueries({ queryKey: ['resumen', fila.id] });
      onCerrar();
    },
    onError: (e) => {
      if ((e as AxiosError).response?.status === 409) void qc.invalidateQueries({ queryKey: ['registros'] });
      setError(mensajeDeError(e));
    },
  });

  return (
    <Modal titulo={`Editar · ${String(fila.valores.nombre_completo ?? '')}`} abierto onCerrar={onCerrar}
      pie={<>
        <button className="btn btn-sec" onClick={onCerrar}>Cancelar</button>
        <button className="btn" disabled={guardar.isPending || Object.keys(cambios).length === 0} onClick={() => guardar.mutate()}>
          {guardar.isPending ? 'Guardando…' : 'Guardar'}
        </button>
      </>}>
      {error && <Aviso tipo="error">{error}</Aviso>}
      <p className="tenue">{fila.numero_empleado} · Ingreso {String(fila.valores.fecha_ingreso ?? '')}</p>
      <div className="form-grid">
        {editables.map((c) => (
          <label key={c.clave} className={c.tipo === 'texto_largo' ? 'col-completa' : undefined}>
            {c.etiqueta}
            <CampoValor col={c} valor={valores[c.clave] ?? null} onCambiar={(v) => setValores({ ...valores, [c.clave]: v })} />
          </label>
        ))}
      </div>
    </Modal>
  );
}
