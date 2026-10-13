import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { registrosApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { Modal } from '../../components/Modal';
import { Aviso } from '../../components/Aviso';
import { esFechaValida, mascaraFecha } from '../../utils/formato';

/** Alta manual en la quincena visible. El número MSI000001… lo asigna el servidor. */
export function NuevoEmpleadoModal({ abierto, periodoId, onCerrar }: { abierto: boolean; periodoId?: number; onCerrar: () => void }) {
  const qc = useQueryClient();
  const [f, setF] = useState({ nombre_completo: '', puesto: '', fecha_ingreso: '' });
  const [error, setError] = useState<string | null>(null);

  const crear = useMutation({
    mutationFn: () => registrosApi.crearEmpleado({ ...f, periodo_id: periodoId }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['registros'] });
      setF({ nombre_completo: '', puesto: '', fecha_ingreso: '' });
      onCerrar();
    },
    onError: (e) => setError(mensajeDeError(e)),
  });

  const valido = f.nombre_completo.trim().length > 3 && esFechaValida(f.fecha_ingreso);

  return (
    <Modal titulo="Nuevo empleado" abierto={abierto} onCerrar={onCerrar} ancho="sm"
      pie={<>
        <button className="btn btn-sec" onClick={onCerrar}>Cancelar</button>
        <button className="btn" disabled={!valido || crear.isPending} onClick={() => crear.mutate()}>Crear</button>
      </>}>
      {error && <Aviso tipo="error">{error}</Aviso>}
      <div className="form-grid">
        <label className="col-completa">Nombre completo
          <input value={f.nombre_completo} onChange={(e) => setF({ ...f, nombre_completo: e.target.value })} autoFocus />
        </label>
        <label>Puesto (opcional)
          <input list="lista-puestos" value={f.puesto} onChange={(e) => setF({ ...f, puesto: e.target.value })} />
        </label>
        <label>Fecha de ingreso
          <input inputMode="numeric" placeholder="dd/mm/aaaa" value={f.fecha_ingreso}
            onChange={(e) => setF({ ...f, fecha_ingreso: mascaraFecha(e.target.value) })} />
        </label>
      </div>
    </Modal>
  );
}
