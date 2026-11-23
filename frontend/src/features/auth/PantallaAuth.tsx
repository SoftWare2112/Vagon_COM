import type { ReactNode } from 'react';

/** Marca de la plataforma: monograma de «hoja de nómina» (renglones y columna de importes) + nombre. */
export function Logo({ claro = false, compacto = false }: { claro?: boolean; compacto?: boolean }) {
  return (
    <span className={`logo ${claro ? 'logo-claro' : ''}`}>
      <svg className="logo-icono" viewBox="0 0 32 32" aria-hidden="true">
        <rect x="1" y="1" width="30" height="30" rx="7" className="logo-fondo" />
        <path d="M8 10.5h9M8 15.5h9M8 20.5h6" className="logo-linea" />
        <path d="M20.5 10.5h3.5M20.5 15.5h3.5" className="logo-linea logo-tenue" />
        <path d="M19 20.5h5" className="logo-linea logo-neto" />
      </svg>
      {!compacto && <span className="logo-texto">Vagon COM<small>Sistema de nomina</small></span>}
    </span>
  );
}

/** Lema de la empresa, en el lugar donde antes iba el recibo de ejemplo. */
function Lema() {
  return (
    <figure className="portada-lema">
      <span className="portada-lema-etiqueta">Nuestro compromiso</span>
      <blockquote>
        <p>Sirviendo con <em>integridad</em>,<br />innovando con <em>excelencia</em>.</p>
      </blockquote>
      <figcaption>Vagon COM</figcaption>
    </figure>
  );
}

/**
 * Pantalla dividida para iniciar sesión y recuperar la cuenta: a la izquierda la portada (tema de nómina,
 * paleta gris de la plataforma) y a la derecha el formulario. En celular la portada se reduce a una banda.
 */
export function PantallaAuth({ children }: { children: ReactNode }) {
  return (
    <div className="pantalla-auth">
      <aside className="auth-portada">
        <Logo claro />
        <div className="portada-cuerpo">
          <h2 className="portada-titulo">Detras de cada numero hay esfuerzo, y detras de cada calculo, el bienestar de tu equipo</h2> 
          <p className="portada-texto"></p>
          <Lema />
        </div>
        <ul className="portada-puntos">
        </ul>
      </aside>
      <main className="auth-lado">
        <div className="auth-movil-marca"><Logo /></div>
        <div className="auth-contenido">{children}</div>
        <footer className="auth-pie">© {new Date().getFullYear()} Vagón COM · Uso exclusivo del personal autorizado</footer>
      </main>
    </div>
  );
}
