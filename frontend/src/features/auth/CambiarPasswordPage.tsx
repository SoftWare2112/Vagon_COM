import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { authApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { useAuth } from '../../auth/AuthContext';
import { Aviso } from '../../components/Aviso';
import { ReglasPassword, passwordValida } from './ReglasPassword';

export function CambiarPasswordPage() {
  const { usuario, refrescar } = useAuth();
  const nav = useNavigate();
  const [p, setP] = useState({ password_actual: '', password: '', password_confirmation: '' });
  const [error, setError] = useState<string | null>(null);

  const enviar = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      await authApi.cambiarPassword(p);
      await refrescar();
      nav('/');
    } catch (err) {
      setError(mensajeDeError(err));
    }
  };

  return (
    <div className="pantalla-auth">
      <form className="tarjeta-auth" onSubmit={enviar}>
        <h1>Cambiar contraseña</h1>
        {usuario?.must_change_password && <Aviso tipo="info">Por seguridad, define una contraseña propia antes de continuar.</Aviso>}
        {error && <Aviso tipo="error">{error}</Aviso>}
        <label>Contraseña actual<input type="password" autoComplete="current-password" value={p.password_actual} onChange={(e) => setP({ ...p, password_actual: e.target.value })} /></label>
        <label>Nueva contraseña<input type="password" autoComplete="new-password" value={p.password} onChange={(e) => setP({ ...p, password: e.target.value })} /></label>
        <label>Confirmar<input type="password" autoComplete="new-password" value={p.password_confirmation} onChange={(e) => setP({ ...p, password_confirmation: e.target.value })} /></label>
        <ReglasPassword valor={p.password} />
        <button className="btn btn-bloque" disabled={!passwordValida(p.password) || p.password !== p.password_confirmation}>Guardar</button>
      </form>
    </div>
  );
}
