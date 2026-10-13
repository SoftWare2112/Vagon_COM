import { useState } from 'react';
import { Link } from 'react-router-dom';
import { authApi } from '../../api/endpoints';
import { mensajeDeError } from '../../api/http';
import { Aviso } from '../../components/Aviso';

export function OlvidePage() {
  const [email, setEmail] = useState('');
  const [msg, setMsg] = useState<{ tipo: 'exito' | 'error'; texto: string } | null>(null);
  const [enviando, setEnviando] = useState(false);

  const enviar = async (e: React.FormEvent) => {
    e.preventDefault();
    setEnviando(true);
    try { setMsg({ tipo: 'exito', texto: await authApi.olvide(email.trim()) }); }
    catch (err) { setMsg({ tipo: 'error', texto: mensajeDeError(err) }); }
    finally { setEnviando(false); }
  };

  return (
    <div className="pantalla-auth">
      <form className="tarjeta-auth" onSubmit={enviar}>
        <h1>Recuperar cuenta</h1>
        <p className="tenue">Te enviaremos un enlace (válido 60 minutos) para crear una nueva contraseña. También desbloquea tu cuenta.</p>
        {msg && <Aviso tipo={msg.tipo}>{msg.texto}</Aviso>}
        <label>Correo<input type="email" required value={email} onChange={(e) => setEmail(e.target.value)} /></label>
        <button className="btn btn-bloque" disabled={enviando}>Enviar enlace</button>
        <Link to="/login" className="enlace-centro">Volver</Link>
      </form>
    </div>
  );
}
