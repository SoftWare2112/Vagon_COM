import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { columnasApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { Modal } from '../../components/Modal';
import { Aviso } from '../../components/Aviso';
import type { TipoColumna } from '../../types';

export interface PosicionColumna { lado: 'antes_de' | 'despues_de'; clave: string; etiqueta: string }

const TIPOS: { v: TipoColumna; t: string }[] = [
  { v: 'texto', t: 'Texto (recomendado al inicio)' }, { v: 'dinero', t: 'Dinero ($)' }, { v: 'decimal', t: 'Número con decimales' },
  { v: 'entero', t: 'Número entero' }, { v: 'fecha', t: 'Fecha (dd/mm/aaaa)' }, { v: 'booleano', t: 'Sí / No' }, { v: 'texto_largo', t: 'Texto largo' },
];

/**
 * Insertar una columna desde la tabla, junto a la columna donde el usuario abrió el menú.
 * Usa la misma API que Ajustes > Columnas: los datos existentes no se mueven.
 */
export function NuevaColumnaModal({ posicion, onCerrar }: { posicion: PosicionColumna; onCerrar: () => void }) {
  const qc = useQueryClient();
  const [etiqueta, setEtiqueta] = useState('');
  const [tipo, setTipo] = useState<TipoColumna>('texto');
  const [error, setError] = useState<string | null>(null);

  const crear = useMutation({
    mutationFn: () => columnasApi.crear({
      etiqueta: etiqueta.trim(), tipo,
      ...(posicion.lado === 'antes_de' ? { antes_de: posicion.clave } : { despues_de: posicion.clave }),
    }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['columnas'] });
      void qc.invalidateQueries({ queryKey: ['registros'] });
      onCerrar();
    },
    onError: (e) => setError(mensajeDeError(e)),
  });

  return (
    <Modal titulo="Insertar columna" abierto onCerrar={onCerrar} ancho="sm"
      pie={<>
        <button className="btn btn-sec" onClick={onCerrar}>Cancelar</button>
        <button className="btn" disabled={!etiqueta.trim() || crear.isPending} onClick={() => crear.mutate()}>Insertar</button>
      </>}>
      {error && <Aviso tipo="error">{error}</Aviso>}
      <p className="tenue">Se insertará {posicion.lado === 'antes_de' ? 'a la izquierda' : 'a la derecha'} de <strong>{posicion.etiqueta}</strong>. Las filas actuales la tendrán vacía y podrás llenarla editando; los próximos Excel que la traigan la llenarán solos.</p>
      <div className="form-grid">
        <label className="col-completa">Nombre de la columna (como vendrá en el Excel)
          <input autoFocus maxLength={100} value={etiqueta} onChange={(e) => setEtiqueta(e.target.value)} placeholder="Ej. Bono transporte" />
        </label>
        <label className="col-completa">Tipo de dato (Texto si no estás seguro; se puede cambiar después)
          <select value={tipo} onChange={(e) => setTipo(e.target.value as TipoColumna)}>
            {TIPOS.map((t) => <option key={t.v} value={t.v}>{t.t}</option>)}
          </select>
        </label>
      </div>
    </Modal>
  );
}
