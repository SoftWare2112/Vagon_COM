import { useState } from 'react';
import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { useAuth } from '../auth/AuthContext';
import { useOnline } from '../hooks/useOnline';
import type { Permiso } from '../types';

const MENU: { a: string; texto: string; icono: string; permiso: Permiso }[] = [
  { a: '/', texto: 'Dashboard', icono: '▦', permiso: 'registros.ver' },
  { a: '/informes', texto: 'Informes', icono: '🗎', permiso: 'exportar' },
  { a: '/usuarios', texto: 'Usuarios', icono: '👥', permiso: 'usuarios.gestionar' },
  { a: '/historico', texto: 'Histórico', icono: '🕘', permiso: 'historico.ver' },
  { a: '/ajustes', texto: 'Ajustes', icono: '⚙', permiso: 'ajustes.gestionar' },
];

const NOMBRE_ROL = { admin_rh: 'Administrador (RH)', supervisor: 'Supervisor', lector: 'Lector' } as const;

/** Sidebar fijo en laptop; cajón (drawer) con botón ☰ en celular. */
export function AppLayout() {
  const { usuario, puede, logout } = useAuth();
  const [abierto, setAbierto] = useState(false);
  const online = useOnline();
  const nav = useNavigate();

  const salir = async () => {
    await logout();
    nav('/login');
  };

  return (
    <div className={`app ${abierto ? 'menu-abierto' : ''}`}>
      <aside className="sidebar" aria-label="Menú principal">
        <div className="marca">Reportes<span>Personal</span></div>
        <nav>
          {MENU.filter((m) => puede(m.permiso)).map((m) => (
            <NavLink key={m.a} to={m.a} end={m.a === '/'} onClick={() => setAbierto(false)}>
              <span className="icono" aria-hidden>{m.icono}</span>{m.texto}
            </NavLink>
          ))}
        </nav>
      </aside>
      <div className="velo" onClick={() => setAbierto(false)} aria-hidden />

      <div className="principal">
        <header className="topbar">
          <button className="btn-icono solo-movil" onClick={() => setAbierto(true)} aria-label="Abrir menú">☰</button>
          <div className="topbar-espacio" />
          <div className="usuario">
            <strong>{usuario?.name}</strong>
            <small>{usuario?.rol ? NOMBRE_ROL[usuario.rol] : ''}</small>
          </div>
          <NavLink to="/cambiar-password" className="btn btn-sec btn-sm oculto-movil">Contraseña</NavLink>
          <button className="btn btn-sec btn-sm" onClick={salir}>Salir</button>
        </header>
        {!online && <div className="banda-offline" role="alert">Sin conexión. Los cambios no se guardarán hasta que vuelva la red.</div>}
        <main className="contenido"><Outlet /></main>
      </div>
    </div>
  );
}
