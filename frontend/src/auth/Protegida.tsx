import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from './AuthContext';
import type { Permiso } from '../types';

/** Exige sesión (y opcionalmente un permiso) para entrar a la ruta. */
export function Protegida({ permiso }: { permiso?: Permiso }) {
  const { usuario, cargando, puede } = useAuth();
  const loc = useLocation();

  if (cargando) return <div className="pantalla-centro">Cargando…</div>;
  if (!usuario) return <Navigate to="/login" replace state={{ desde: loc.pathname }} />;
  if (usuario.must_change_password && loc.pathname !== '/cambiar-password') return <Navigate to="/cambiar-password" replace />;
  if (permiso && !puede(permiso)) return <Navigate to="/" replace />;
  return <Outlet />;
}
