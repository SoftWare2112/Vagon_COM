import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { archivoBlob } from '../api/endpoints';

/**
 * v10 — Imagen protegida: se descarga con la sesión del usuario y se muestra como blob.
 * (Los archivos de la plataforma nunca tienen un enlace público.)
 */
export function ImagenSegura({ url, alt, className, onClick }: { url: string; alt: string; className?: string; onClick?: () => void }) {
  const { data: blob, isError } = useQuery({ queryKey: ['archivo', url], queryFn: () => archivoBlob(url), staleTime: Infinity });
  const [src, setSrc] = useState<string | null>(null);
  useEffect(() => {
    if (!blob) return;
    const u = URL.createObjectURL(blob);
    setSrc(u);
    return () => URL.revokeObjectURL(u);
  }, [blob]);

  if (isError) return <span className={`imagen-cargando ${className ?? ''}`}>No se pudo cargar</span>;
  if (!src) return <span className={`imagen-cargando ${className ?? ''}`} aria-label="Cargando imagen" />;
  return <img src={src} alt={alt} className={className} onClick={onClick} loading="lazy" />;
}
