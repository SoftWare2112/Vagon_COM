import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { authApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { Aviso } from '../../components/Aviso';
import { ReglasPassword, passwordValida } from './ReglasPassword';

export function RestablecerPage() {
  const [q] = useSearchParams();
  const [p, setP] = useState({ password: '', password_confirmation: '' });
  const [msg, setMsg] = useState<{ tipo: 'exito' | 'error'; texto: string } | null>(null);

  const enviar = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      setMsg({ tipo: 'exito', texto: await authApi.restablecer({ token: q.get('token') ?? '', email: q.get('email') ?? '', ...p }) });
    } catch (err) {
      setMsg({ tipo: 'error', texto: mensajeDeError(err) });
    }
  };

  return (
    <div className="pantalla-auth">
      <form className="tarjeta-auth" onSubmit={enviar}>
        <h1>Nueva contraseña</h1>
        {msg && <Aviso tipo={msg.tipo}>{msg.texto}</Aviso>}
        <label>Contraseña<input type="password" autoComplete="new-password" value={p.password} onChange={(e) => setP({ ...p, password: e.target.value })} /></label>
        <label>Confirmar<input type="password" autoComplete="new-password" value={p.password_confirmation} onChange={(e) => setP({ ...p, password_confirmation: e.target.value })} /></label>
        <ReglasPassword valor={p.password} />
        <button className="btn btn-bloque" disabled={!passwordValida(p.password) || p.password !== p.password_confirmation}>Guardar</button>
        <Link to="/login" className="enlace-centro">Ir a iniciar sesión</Link>
      </form>
    </div>
  );
}
