import { useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { mensajeDeError } from '../../api/http';
import { useAuth } from '../../auth/AuthContext';
import { Aviso } from '../../components/Aviso';
import { PantallaAuth } from './PantallaAuth';

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
    <PantallaAuth>
      <form className="tarjeta-auth" onSubmit={entrar}>
        <div className="auth-encabezado">
          <h1>Iniciar sesión</h1>
          <p className="tenue">Ingresa con tu correo institucional y tu contraseña.</p>
        </div>
        {error && <Aviso tipo="error">{error}</Aviso>}
        <label>Correo electrónico
          <input type="email" autoComplete="username" required autoFocus placeholder="correo@empresa.com" value={email} onChange={(e) => setEmail(e.target.value)} />
        </label>
        <label>
          <span className="auth-etiqueta-fila">Contraseña <Link to="/olvide" className="auth-enlace">¿Olvidaste tu contraseña?</Link></span>
          <span className="campo-clave">
            <input type={ver ? 'text' : 'password'} autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} />
            <button type="button" className="campo-clave-ver" onClick={() => setVer(!ver)} aria-label={ver ? 'Ocultar contraseña' : 'Mostrar contraseña'} aria-pressed={ver}>
              {ver ? 'Ocultar' : 'Mostrar'}
            </button>
          </span>
        </label>
        <button className="btn btn-bloque btn-primario" disabled={enviando}>{enviando ? 'Verificando…' : 'Entrar'}</button>
        <p className="auth-nota">¿No tienes acceso? Solicítalo al área de Recursos Humanos. Por seguridad, la cuenta se bloquea temporalmente después de varios intentos fallidos.</p>
      </form>
    </PantallaAuth>
  );
}
