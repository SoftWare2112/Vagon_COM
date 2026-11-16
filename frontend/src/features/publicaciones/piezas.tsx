import type { AdjuntoPublicacion, Publicacion, TipoPublicacion } from '../../types';

export const TIPOS: Record<TipoPublicacion, { texto: string; icono: string; detalle: string }> = {
  texto: { texto: 'Texto', icono: '✎', detalle: 'Un aviso, una noticia o instrucciones' },
  documentos: { texto: 'Documentos', icono: '🗎', detalle: 'PDF, Excel, Word, PowerPoint…' },
  imagenes: { texto: 'Imágenes', icono: '🖼', detalle: 'Fotos o gráficos, en galería' },
  video: { texto: 'Video', icono: '▶', detalle: 'Un enlace de YouTube, Vimeo, Drive, Stream…' },
  trabajo: { texto: 'Trabajo de la plataforma', icono: '▦', detalle: 'Una copia de solo lectura de uno de tus libros' },
};

export const PROVEEDORES: Record<string, string> = {
  youtube: 'YouTube', vimeo: 'Vimeo', drive: 'Google Drive', microsoft: 'Microsoft Stream / SharePoint', teams: 'Microsoft Teams', otro: 'sitio externo',
};

export const tamano = (b: number) => (b < 1024 * 1024 ? `${Math.max(1, Math.round(b / 1024))} KB` : `${(b / 1024 / 1024).toFixed(1)} MB`);

export function iconoArchivo(a: Pick<AdjuntoPublicacion, 'nombre' | 'mime'>): string {
  const ext = a.nombre.split('.').pop()?.toLowerCase() ?? '';
  if (a.mime === 'application/pdf') return 'PDF';
  if (['xlsx', 'xls', 'csv'].includes(ext)) return 'XLS';
  if (['docx', 'doc'].includes(ext)) return 'DOC';
  if (ext === 'pptx') return 'PPT';
  if (a.mime.startsWith('image/')) return 'IMG';
  return ext.toUpperCase().slice(0, 4) || 'ARCH';
}

/** Iniciales para el avatar del autor. */
export const iniciales = (n: string) => n.split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]?.toUpperCase()).join('');

/** "hace 5 min", "hace 3 h", "ayer", "12/11/2026". */
export function haceCuanto(iso: string | null, ahora = new Date()): string {
  if (!iso) return '';
  const d = new Date(iso);
  const min = Math.round((ahora.getTime() - d.getTime()) / 60_000);
  if (min < 1) return 'justo ahora';
  if (min < 60) return `hace ${min} min`;
  if (min < 24 * 60) return `hace ${Math.round(min / 60)} h`;
  if (min < 48 * 60) return 'ayer';
  if (min < 7 * 24 * 60) return `hace ${Math.round(min / 1440)} días`;
  return d.toLocaleDateString('es-MX', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

export function Insignias({ p }: { p: Publicacion }) {
  return (
    <span className="insignias">
      {p.fijada && <span className="etiqueta etiqueta-info" title="Fijada arriba por un moderador">📌 Fijada</span>}
      {p.oficial && <span className="etiqueta etiqueta-ok" title="Contenido oficial">✓ Oficial</span>}
      {p.estado === 'borrador' && <span className="etiqueta etiqueta-neutro">Borrador</span>}
      {p.estado === 'archivada' && <span className="etiqueta etiqueta-neutro">Archivada</span>}
      {!p.leida && p.estado === 'publicada' && <span className="etiqueta etiqueta-nueva">Nueva</span>}
    </span>
  );
}
