import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { authApi } from '../api/endpoints';
import type { Permiso, Usuario } from '../types';

interface AuthValor {
  usuario: Usuario | null;
  cargando: boolean;
  login: (email: string, password: string) => Promise<Usuario>;
  logout: () => Promise<void>;
  refrescar: () => Promise<void>;
  puede: (p: Permiso) => boolean;
}

const AuthContext = createContext<AuthValor | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [usuario, setUsuario] = useState<Usuario | null>(null);
  const [cargando, setCargando] = useState(true);

  const refrescar = useCallback(async () => {
    try {
      setUsuario(await authApi.me());
    } catch {
      setUsuario(null);
    } finally {
      setCargando(false);
    }
  }, []);

  useEffect(() => {
    void refrescar();
    const expirada = () => setUsuario(null);
    window.addEventListener('sesion-expirada', expirada);
    return () => window.removeEventListener('sesion-expirada', expirada);
  }, [refrescar]);

  const valor = useMemo<AuthValor>(() => ({
    usuario,
    cargando,
    refrescar,
    login: async (email, password) => {
      const u = await authApi.login(email, password);
      setUsuario(u);
      return u;
    },
    logout: async () => {
      await authApi.logout().catch(() => undefined);
      setUsuario(null);
    },
    // Solo para mostrar/ocultar UI. La seguridad real está en el backend.
    puede: (p) => !!usuario?.permisos.includes(p),
  }), [usuario, cargando, refrescar]);

  return <AuthContext.Provider value={valor}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthValor {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth debe usarse dentro de <AuthProvider>');
  return ctx;
}
