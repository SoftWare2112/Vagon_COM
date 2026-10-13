import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { rolesApi, usuariosApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { useAuth } from '../../auth/AuthContext';
import { Aviso } from '../../components/Aviso';
import { Modal } from '../../components/Modal';
import { RolesPermisosPanel } from './RolesPermisosPanel';

const NOMBRE_ROL: Record<string, string> = { admin_rh: 'Administrador (RH)', supervisor: 'Supervisor', lector: 'Lector' };

export function UsuariosPage() {
  const { usuario: yo } = useAuth();
  const qc = useQueryClient();
  const { data: usuarios = [] } = useQuery({ queryKey: ['usuarios'], queryFn: usuariosApi.listar });
  const { data: rolesData } = useQuery({ queryKey: ['roles'], queryFn: rolesApi.listar });
  const roles = rolesData?.roles ?? [];
  const [nuevo, setNuevo] = useState(false);
  const [form, setForm] = useState({ name: '', email: '', rol_id: 0 });
  const [aviso, setAviso] = useState<{ tipo: 'exito' | 'error'; texto: string } | null>(null);

  const accion = useMutation({
    mutationFn: (fn: () => Promise<unknown>) => fn(),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['usuarios'] }); setAviso({ tipo: 'exito', texto: 'Cambios guardados.' }); },
    onError: (e) => setAviso({ tipo: 'error', texto: mensajeDeError(e) }),
  });

  const crear = () => accion.mutate(async () => {
    await usuariosApi.crear(form);
    setNuevo(false);
    setForm({ name: '', email: '', rol_id: 0 });
  });

  return (
    <section>
      <div className="encabezado-pagina">
        <h1>Usuarios</h1>
        <button className="btn" onClick={() => setNuevo(true)}>+ Usuario</button>
      </div>
      {aviso && <Aviso tipo={aviso.tipo}>{aviso.texto}</Aviso>}
      <div className="tabla-scroll"><table className="tabla">
        <thead><tr><th>Nombre</th><th>Correo</th><th>Rol</th><th>Estado</th><th>Último acceso</th><th>Acciones</th></tr></thead>
        <tbody>{usuarios.map((u) => (
          <tr key={u.id}>
            <td>{u.name}</td><td>{u.email}</td>
            <td>
              <select value={u.rol_id ?? ''} disabled={u.id === yo?.id}
                onChange={(e) => accion.mutate(() => usuariosApi.actualizar(u.id, { rol_id: Number(e.target.value) }))}>
                {roles.map((r) => <option key={r.id} value={r.id}>{NOMBRE_ROL[r.nombre] ?? r.nombre}</option>)}
              </select>
            </td>
            <td>{!u.is_active ? 'Inactivo' : u.bloqueado ? `Bloqueado hasta ${u.locked_until}` : 'Activo'}</td>
            <td>{u.last_login_at ?? '—'}</td>
            <td className="acciones">
              {u.bloqueado && <button className="btn btn-sec btn-sm" onClick={() => accion.mutate(() => usuariosApi.desbloquear(u.id))}>Desbloquear</button>}
              {u.id !== yo?.id && (u.is_active
                ? <button className="btn btn-sec btn-sm" onClick={() => confirm(`¿Desactivar a ${u.name}?`) && accion.mutate(() => usuariosApi.desactivar(u.id))}>Desactivar</button>
                : <button className="btn btn-sec btn-sm" onClick={() => accion.mutate(() => usuariosApi.actualizar(u.id, { is_active: true }))}>Activar</button>)}
            </td>
          </tr>
        ))}</tbody>
      </table></div>

      <RolesPermisosPanel />

      <Modal titulo="Nuevo usuario" abierto={nuevo} onCerrar={() => setNuevo(false)} ancho="sm"
        pie={<><button className="btn btn-sec" onClick={() => setNuevo(false)}>Cancelar</button>
          <button className="btn" disabled={!form.name || !form.email || !form.rol_id || accion.isPending} onClick={crear}>Crear y enviar invitación</button></>}>
        <div className="form-grid">
          <label className="col-completa">Nombre<input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
          <label className="col-completa">Correo (personal o institucional)<input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} /></label>
          <label className="col-completa">Rol
            <select value={form.rol_id} onChange={(e) => setForm({ ...form, rol_id: Number(e.target.value) })}>
              <option value={0}>Selecciona…</option>
              {roles.map((r) => <option key={r.id} value={r.id}>{NOMBRE_ROL[r.nombre] ?? r.nombre} — {r.descripcion}</option>)}
            </select>
          </label>
        </div>
        <p className="tenue">La persona recibirá un correo para definir su propia contraseña.</p>
      </Modal>
    </section>
  );
}
