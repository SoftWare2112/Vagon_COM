import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { rolesApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { useAuth } from '../../auth/AuthContext';
import { Aviso } from '../../components/Aviso';
import type { Permiso } from '../../types';

const NOMBRE_ROL: Record<string, string> = { admin_rh: 'Administrador (RH)', supervisor: 'Supervisor', lector: 'Lector' };

/** Matriz rol × permiso editable por el Administrador. El servidor la aplica en cada petición. */
export function RolesPermisosPanel() {
  const qc = useQueryClient();
  const { refrescar } = useAuth();
  const { data } = useQuery({ queryKey: ['roles'], queryFn: rolesApi.listar });
  const [matriz, setMatriz] = useState<Record<number, Permiso[]>>({});
  const [aviso, setAviso] = useState<{ tipo: 'exito' | 'error'; texto: string } | null>(null);

  useEffect(() => {
    if (data) setMatriz(Object.fromEntries(data.roles.map((r) => [r.id, r.permisos])));
  }, [data]);

  const guardar = useMutation({
    mutationFn: async () => {
      for (const r of data?.roles ?? []) await rolesApi.guardarPermisos(r.id, matriz[r.id] ?? []);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['roles'] });
      void refrescar();
      setAviso({ tipo: 'exito', texto: 'Permisos guardados. Aplican en la siguiente acción de cada usuario.' });
    },
    onError: (e) => setAviso({ tipo: 'error', texto: mensajeDeError(e) }),
  });

  if (!data) return null;
  const alternar = (rolId: number, p: Permiso) => setMatriz((m) => ({
    ...m, [rolId]: m[rolId]?.includes(p) ? m[rolId].filter((x) => x !== p) : [...(m[rolId] ?? []), p],
  }));

  return (
    <div style={{ marginTop: '1.5rem' }}>
      <h2>Roles y permisos</h2>
      {aviso && <Aviso tipo={aviso.tipo}>{aviso.texto}</Aviso>}
      <div className="tabla-scroll"><table className="tabla tabla-compacta">
        <thead><tr><th>Permiso</th>{data.roles.map((r) => <th key={r.id} className="centro">{NOMBRE_ROL[r.nombre] ?? r.nombre}</th>)}</tr></thead>
        <tbody>{data.permisos.map((p) => (
          <tr key={p.clave}>
            <td>{p.descripcion}</td>
            {data.roles.map((r) => {
              const bloqueado = r.nombre === 'admin_rh' && p.clave === 'usuarios.gestionar';
              return (
                <td key={r.id} className="centro">
                  <input type="checkbox" style={{ width: 'auto', minHeight: 'auto' }} disabled={bloqueado}
                    title={bloqueado ? 'El Administrador siempre conserva la gestión de usuarios' : undefined}
                    checked={matriz[r.id]?.includes(p.clave) ?? false} onChange={() => alternar(r.id, p.clave)}
                    aria-label={`${p.descripcion} — ${r.nombre}`} />
                </td>
              );
            })}
          </tr>
        ))}</tbody>
      </table></div>
      <button className="btn" style={{ marginTop: '.75rem' }} disabled={guardar.isPending} onClick={() => guardar.mutate()}>Guardar permisos</button>
    </div>
  );
}
