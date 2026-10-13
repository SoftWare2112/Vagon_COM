import { useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { mensajeDeError } from '../../api/http';
import { useAuth } from '../../auth/AuthContext';
import { Aviso } from '../../components/Aviso';

export function LoginPage() {
  const { login } = useAuth();
  const nav = useNavigate();
  const loc = useLocation() as { state?: { desde?: string } };
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [ver, setVer] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [enviando, setEnviando] = useState(false);

  const entrar = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setEnviando(true);
    try {
      const u = await login(email.trim(), password);
      nav(u.must_change_password ? '/cambiar-password' : loc.state?.desde ?? '/', { replace: true });
    } catch (err) {
      setError(mensajeDeError(err));
    } finally {
      setEnviando(false);
    }
  };

  return (
    <div className="pantalla-auth">
      <form className="tarjeta-auth" onSubmit={entrar}>
        <h1>Plataforma de Reportes</h1>
        <p className="tenue">Inicia sesión con tu correo</p>
        {error && <Aviso tipo="error">{error}</Aviso>}
        <label>Correo<input type="email" autoComplete="username" required value={email} onChange={(e) => setEmail(e.target.value)} /></label>
        <label>Contraseña
          <div className="input-con-boton">
            <input type={ver ? 'text' : 'password'} autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} />
            <button type="button" className="btn-link" onClick={() => setVer(!ver)}>{ver ? 'Ocultar' : 'Ver'}</button>
          </div>
        </label>
        <button className="btn btn-bloque" disabled={enviando}>{enviando ? 'Entrando…' : 'Entrar'}</button>
        <Link to="/olvide" className="enlace-centro">¿Olvidaste tu contraseña?</Link>
      </form>
    </div>
  );
}
