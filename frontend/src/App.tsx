import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { Protegida } from './auth/Protegida';
import { AppLayout } from './layout/AppLayout';
import { DashboardPage } from './features/registros/DashboardPage';
import { InformesPage } from './features/informes/InformesPage';
import { UsuariosPage } from './features/usuarios/UsuariosPage';
import { HistoricoPage } from './features/historico/HistoricoPage';
import { AjustesPage } from './features/ajustes/AjustesPage';
import { LoginPage } from './features/auth/LoginPage';
import { OlvidePage } from './features/auth/OlvidePage';
import { RestablecerPage } from './features/auth/RestablecerPage';
import { CambiarPasswordPage } from './features/auth/CambiarPasswordPage';
import { ImprimirPage } from './features/impresion/ImprimirPage';
import { EstadisticasPage } from './features/estadisticas/EstadisticasPage';

export default function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="/olvide" element={<OlvidePage />} />
        <Route path="/restablecer" element={<RestablecerPage />} />

        <Route element={<Protegida />}>
          <Route path="/cambiar-password" element={<CambiarPasswordPage />} />
          {/* v6: impresión sin menú lateral (solo la tabla y su encabezado) */}
          <Route element={<Protegida permiso="registros.ver" />}><Route path="/imprimir" element={<ImprimirPage />} /></Route>
          <Route element={<AppLayout />}>
            <Route element={<Protegida permiso="registros.ver" />}><Route index element={<DashboardPage />} /></Route>
            <Route element={<Protegida permiso="registros.ver" />}><Route path="graficas" element={<EstadisticasPage />} /></Route>
            <Route element={<Protegida permiso="exportar" />}><Route path="informes" element={<InformesPage />} /></Route>
            <Route element={<Protegida permiso="usuarios.gestionar" />}><Route path="usuarios" element={<UsuariosPage />} /></Route>
            <Route element={<Protegida permiso="historico.ver" />}><Route path="historico" element={<HistoricoPage />} /></Route>
            <Route element={<Protegida permiso="ajustes.gestionar" />}><Route path="ajustes" element={<AjustesPage />} /></Route>
          </Route>
        </Route>
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </BrowserRouter>
  );
}
