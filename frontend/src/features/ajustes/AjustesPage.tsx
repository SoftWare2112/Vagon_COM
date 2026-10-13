import { useEffect, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ajustesApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { Aviso } from '../../components/Aviso';
import { useAuth } from '../../auth/AuthContext';
import { ColumnasPanel } from './ColumnasPanel';

export function AjustesPage() {
  const { puede } = useAuth();
  const { data } = useQuery({ queryKey: ['ajustes'], queryFn: ajustesApi.obtener });
  const [f, setF] = useState<Record<string, string>>({});
  const [aviso, setAviso] = useState<{ tipo: 'exito' | 'error'; texto: string } | null>(null);
  useEffect(() => { if (data) setF(data); }, [data]);

  const guardar = useMutation({
    mutationFn: () => ajustesApi.guardar(f),
    onSuccess: () => setAviso({ tipo: 'exito', texto: 'Ajustes guardados.' }),
    onError: (e) => setAviso({ tipo: 'error', texto: mensajeDeError(e) }),
  });

  return (
    <section>
      <div className="encabezado-pagina"><h1>Ajustes</h1></div>
      {aviso && <Aviso tipo={aviso.tipo}>{aviso.texto}</Aviso>}
      <form className="tarjeta-form" onSubmit={(e) => { e.preventDefault(); guardar.mutate(); }}>
        <label>Modo de carga predeterminado
          <select value={f.modo_etl_predeterminado ?? 'MANTENER'} onChange={(e) => setF({ ...f, modo_etl_predeterminado: e.target.value })}>
            <option value="MANTENER">Mantener información creada/editada/eliminada</option>
            <option value="REEMPLAZAR">Reemplazar información</option>
          </select>
        </label>
        <label>Filas esperadas por archivo (solo genera advertencia)
          <input type="number" min={0} value={f.filas_esperadas ?? ''} onChange={(e) => setF({ ...f, filas_esperadas: e.target.value })} />
        </label>
        <p className="tenue">El servidor de correo y otros secretos se configuran en el archivo .env del servidor, no aquí.</p>
        <button className="btn" disabled={guardar.isPending}>Guardar</button>
      </form>
      {puede('columnas.gestionar') && <div style={{ marginTop: '1.5rem' }}><ColumnasPanel /></div>}
    </section>
  );
}
