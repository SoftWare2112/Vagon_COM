import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import { columnasApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { Modal } from '../../components/Modal';
import { Aviso } from '../../components/Aviso';
import type { ColumnaCatalogo, TipoColumna } from '../../types';

export const NOMBRE_TIPO: Record<TipoColumna, string> = {
  texto: 'Texto', texto_largo: 'Texto largo', dinero: 'Dinero ($)', decimal: 'Número con decimales',
  entero: 'Número entero', fecha: 'Fecha (dd/mm/aaaa)', booleano: 'Sí / No',
};

/**
 * Cambiar el tipo de una columna agregada por RH (normalmente de Texto a otro tipo).
 * Paso 1: se simula y se muestra cuántos valores ya guardados se pueden convertir.
 * Paso 2: se aplica; si hay valores no convertibles, el usuario decide si quedan vacíos.
 */
export function CambioTipoModal({ columna, tipo, onCerrar }: { columna: ColumnaCatalogo; tipo: TipoColumna; onCerrar: () => void }) {
  const qc = useQueryClient();
  const [error, setError] = useState<string | null>(null);

  const simulacion = useQuery({
    queryKey: ['cambio-tipo', columna.id, tipo],
    queryFn: () => columnasApi.cambiarTipo(columna.id, tipo, { simular: true }),
    staleTime: 0,
  });
  const r = simulacion.data;

  const aplicar = useMutation({
    mutationFn: (forzar: boolean) => columnasApi.cambiarTipo(columna.id, tipo, { forzar }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['columnas'] });
      void qc.invalidateQueries({ queryKey: ['registros'] });
      onCerrar();
    },
    onError: (e) => {
      setError(mensajeDeError(e));
      if ((e as AxiosError).response?.status === 422) void simulacion.refetch();   // alguien capturó datos mientras tanto
    },
  });

  return (
    <Modal titulo={`Cambiar tipo · ${columna.etiqueta}`} abierto onCerrar={onCerrar}
      pie={<>
        <button className="btn btn-sec" onClick={onCerrar}>Cancelar</button>
        {r && r.no_convertibles === 0 && (
          <button className="btn" disabled={aplicar.isPending} onClick={() => aplicar.mutate(false)}>Cambiar a {NOMBRE_TIPO[tipo]}</button>
        )}
        {r && r.no_convertibles > 0 && (
          <button className="btn" disabled={aplicar.isPending} onClick={() => aplicar.mutate(true)}>Cambiar y dejar vacíos los {r.no_convertibles} no válidos</button>
        )}
      </>}>
      {error && <Aviso tipo="error">{error}</Aviso>}
      {simulacion.isLoading && <p>Revisando los valores guardados…</p>}
      {simulacion.isError && <Aviso tipo="error">{mensajeDeError(simulacion.error)}</Aviso>}
      {r && (
        <>
          <p>De <strong>{NOMBRE_TIPO[r.tipo_actual]}</strong> a <strong>{NOMBRE_TIPO[r.tipo_nuevo]}</strong>.
            {' '}{r.con_valor === 0 ? 'Todavía no hay valores capturados en esta columna.' : `${r.convertibles} de ${r.con_valor} valores se convierten sin problema.`}</p>
          {r.no_convertibles > 0 && (
            <>
              <Aviso tipo="advertencia">{r.no_convertibles} valor(es) no se pueden convertir. Puedes cancelar y corregirlos editando esas filas, o continuar y dejarlos vacíos (el valor original queda en la auditoría).</Aviso>
              <div className="tabla-scroll"><table className="tabla tabla-compacta">
                <thead><tr><th>No.</th><th>Nombre</th><th>Periodo</th><th>Valor actual</th><th>Se esperaba</th></tr></thead>
                <tbody>{r.ejemplos.map((x, i) => (
                  <tr key={i}><td className="mono">{x.numero_empleado}</td><td>{x.nombre}</td><td>{x.periodo}</td><td>{x.valor}</td><td>{x.esperado}</td></tr>
                ))}</tbody>
              </table></div>
              {r.no_convertibles > r.ejemplos.length && <p className="tenue">Se muestran los primeros {r.ejemplos.length}.</p>}
            </>
          )}
        </>
      )}
    </Modal>
  );
}
