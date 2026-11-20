import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { publicacionesApi } from '../api/endpoints';
import { useAuth } from '../auth/AuthContext';
import { useOnline } from '../hooks/useOnline';
import type { Permiso } from '../types';

// Cada opción se ve si el usuario tiene AL MENOS uno de sus permisos (vacío = todos)
const MENU: { a: string; texto: string; icono: string; permisos: Permiso[] }[] = [
  { a: '/', texto: 'Inicio', icono: '⌂', permisos: [] },
  { a: '/empleados', texto: 'Empleados', icono: '👥', permisos: ['empleados.ver'] },
  { a: '/nomina', texto: 'Nómina del periodo', icono: '🧮', permisos: ['nomina.ver', 'nomina.calcular'] },
  { a: '/nomina/historial', texto: 'Historial y recibos', icono: '🗂', permisos: ['nomina.ver'] },
  { a: '/nomina/reportes', texto: 'Reportes', icono: '📑', permisos: ['nomina.ver'] },
  { a: '/nomina/ajuste-anual', texto: 'Cálculo anual de ISR', icono: '🧾', permisos: ['nomina.ver'] },
  { a: '/indicadores', texto: 'Indicadores', icono: '📊', permisos: ['indicadores.ver'] },
  { a: '/nomina/configuracion', texto: 'Configuración de nómina', icono: '⚙', permisos: ['empleados.ver'] },
  { a: '/publicaciones', texto: 'Publicaciones', icono: '📣', permisos: ['publicaciones.ver'] },
  { a: '/usuarios', texto: 'Usuarios y roles', icono: '🔐', permisos: ['usuarios.gestionar'] },
];

const NOMBRE_ROL = { admin_rh: 'Administrador (RH)', supervisor: 'Supervisor', lector: 'Lector' } as const;

/** Sidebar fijo en laptop; cajón (drawer) con botón ☰ en celular. */
export function AppLayout() {
  const { usuario, puede, logout } = useAuth();
  // v10: cuántas publicaciones nuevas hay (se revisa cada minuto)
  const { data: sinLeer = 0 } = useQuery({ queryKey: ['publicaciones-sin-leer'], queryFn: publicacionesApi.sinLeer, enabled: puede('publicaciones.ver'), refetchInterval: 60_000 });
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
        <div className="marca">Vagón COM<span>Nómina</span></div>
        <nav>
          {MENU.filter((m) => m.permisos.length === 0 || m.permisos.some(puede)).map((m) => (
            <NavLink key={m.a} to={m.a} end={m.a === '/' || m.a === '/nomina'} onClick={() => setAbierto(false)}>
              <span className="icono" aria-hidden>{m.icono}</span>{m.texto}
              {m.a === '/publicaciones' && sinLeer > 0 && <span className="insignia-menu" aria-label={`${sinLeer} nuevas`}>{sinLeer > 99 ? '99+' : sinLeer}</span>}
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
