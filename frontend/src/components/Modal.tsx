import { useEffect, useRef, type ReactNode } from 'react';

interface Props {
  titulo: string;
  abierto: boolean;
  onCerrar: () => void;
  children: ReactNode;
  pie?: ReactNode;
  ancho?: 'sm' | 'md' | 'lg';
}

/** Modal accesible con <dialog> nativo. En móvil ocupa toda la pantalla (CSS). */
export function Modal({ titulo, abierto, onCerrar, children, pie, ancho = 'md' }: Props) {
  const ref = useRef<HTMLDialogElement>(null);

  useEffect(() => {
    const d = ref.current;
    if (!d) return;
    if (abierto && !d.open) d.showModal();
    if (!abierto && d.open) d.close();
  }, [abierto]);

  return (
    <dialog ref={ref} className={`modal modal-${ancho}`} onClose={onCerrar} aria-labelledby="modal-titulo">
      <header className="modal-cab">
        <h2 id="modal-titulo">{titulo}</h2>
        <button type="button" className="btn-icono" onClick={onCerrar} aria-label="Cerrar">✕</button>
      </header>
      <div className="modal-cuerpo">{abierto && children}</div>
      {pie && <footer className="modal-pie">{pie}</footer>}
    </dialog>
  );
}
