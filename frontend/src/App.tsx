import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { Protegida } from './auth/Protegida';
import { AppLayout } from './layout/AppLayout';
import { UsuariosPage } from './features/usuarios/UsuariosPage';
import { LoginPage } from './features/auth/LoginPage';
import { OlvidePage } from './features/auth/OlvidePage';
import { RestablecerPage } from './features/auth/RestablecerPage';
import { CambiarPasswordPage } from './features/auth/CambiarPasswordPage';
import { InicioPage } from './features/inicio/InicioPage';
import { EmpleadosPage } from './features/empleados/EmpleadosPage';
import { EmpleadoPage } from './features/empleados/EmpleadoPage';
import { EmpleadoForm } from './features/empleados/EmpleadoForm';
import { ConfiguracionPage } from './features/nomina/ConfiguracionPage';
import { NominaPage } from './features/nomina/NominaPage';
import { HistorialPage } from './features/nomina/HistorialPage';
import { RecibosPage } from './features/nomina/RecibosPage';
import { ReportesPage } from './features/nomina/ReportesPage';
import { AjusteAnualPage } from './features/nomina/AjusteAnualPage';
import { ImportarXmlPage } from './features/cfdi/ImportarXmlPage';
import { IndicadoresPage } from './features/indicadores/IndicadoresPage';
import { PublicacionesPage } from './features/publicaciones/PublicacionesPage';
import { PublicacionPage } from './features/publicaciones/PublicacionPage';
import { EditorPublicacion } from './features/publicaciones/EditorPublicacion';

export default function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="/olvide" element={<OlvidePage />} />
        <Route path="/restablecer" element={<RestablecerPage />} />

        <Route element={<Protegida />}>
          <Route path="/cambiar-password" element={<CambiarPasswordPage />} />
          <Route element={<AppLayout />}>
            <Route index element={<InicioPage />} />
            {/* v11: sistema de nómina */}
            <Route element={<Protegida permiso="empleados.ver" />}>
              <Route path="empleados" element={<EmpleadosPage />} />
              <Route path="empleados/:id" element={<EmpleadoPage />} />
            </Route>
            <Route element={<Protegida permiso="empleados.gestionar" />}>
              <Route path="empleados/nuevo" element={<EmpleadoForm />} />
              <Route path="empleados/:id/editar" element={<EmpleadoForm />} />
            </Route>
            <Route element={<Protegida permiso="empleados.ver" />}><Route path="nomina/configuracion" element={<ConfiguracionPage />} /></Route>
            {/* v12: nómina del periodo, historial, recibos, reportes e indicadores */}
            <Route element={<Protegida alguno={['nomina.ver', 'nomina.calcular']} />}><Route path="nomina" element={<NominaPage />} /></Route>
            <Route element={<Protegida permiso="nomina.ver" />}>
              <Route path="nomina/historial" element={<HistorialPage />} />
              <Route path="nomina/recibos" element={<RecibosPage />} />
              <Route path="nomina/reportes" element={<ReportesPage />} />
              <Route path="nomina/ajuste-anual" element={<AjusteAnualPage />} />
            </Route>
            {/* v14: importación de los XML timbrados por el departamento de nóminas */}
            <Route element={<Protegida permiso="nomina.timbrar" />}><Route path="nomina/importar-xml" element={<ImportarXmlPage />} /></Route>
            <Route element={<Protegida permiso="indicadores.ver" />}><Route path="indicadores" element={<IndicadoresPage />} /></Route>
            {/* v10: Publicaciones */}
            <Route element={<Protegida permiso="publicaciones.ver" />}>
              <Route path="publicaciones" element={<PublicacionesPage />} />
              <Route path="publicaciones/:id" element={<PublicacionPage />} />
              <Route path="publicaciones/:id/editar" element={<EditorPublicacion />} />
            </Route>
            <Route element={<Protegida permiso="publicaciones.crear" />}>
              <Route path="publicaciones/nueva" element={<EditorPublicacion />} />
            </Route>
            <Route element={<Protegida permiso="usuarios.gestionar" />}><Route path="usuarios" element={<UsuariosPage />} /></Route>
          </Route>
        </Route>
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </BrowserRouter>
  );
}
